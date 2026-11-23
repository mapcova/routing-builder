#!/usr/bin/env bash
# Kills the build of a link with `kill -9` at random moments, relaunches it, and checks that the file is byte for byte the one
# of an uninterrupted build.
#
# usage: tools/kill-resume-link.sh <tiles.json> <link> <dir of the children's files> [trials=8] [slice=240] [seed=42] [from=0.03] [to=0.97]
set -u
tiles=${1:?tiles.json}
link=${2:?link}
dir=${3:?dir}
trials=${4:-8}
slice=${5:-240}
seed=${6:-42}
from=${7:-0.03}
to=${8:-0.97}
here=$(cd "$(dirname "$0")/.." && pwd)
work=${KILL_TEST_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/mcr-kill-link.XXXXXX")}
mkdir -p "$work"
args() { # out
  echo -d memory_limit=256M "$here/bin/build-link.php" --tiles "$tiles" --link "$link" --dir "$dir" --out "$1" --work "$1.work" \
    --slice "$slice" --built-at 1700000000
}
build() { # shellcheck disable=SC2046
  nice php $(args "$1")
}
echo "reference build (uninterrupted, slice $slice s) in $work"
start=$(date +%s.%N)
build "$work/ref.mco" 2> "$work/ref.log"
while [ ! -f "$work/ref.mco" ]; do build "$work/ref.mco" 2>> "$work/ref.log"; done
total=$(echo "$(date +%s.%N) - $start" | bc -l)
printf 'reference: %.1f s\n' "$total"
ok=0
for i in $(seq 1 "$trials"); do
  delay=$(awk -v s="$seed" -v i="$i" -v t="$total" -v a="$from" -v b="$to" 'BEGIN { srand(s * 1000 + i); printf "%.2f", t * (a + (b - a) * rand()) }')
  out="$work/t$i.mco"
  rm -rf "$out" "$out.work" "$out.slices.json"
  # shellcheck disable=SC2046
  (exec nice php $(args "$out") 2> "$work/t$i.log") &
  pid=$!
  sleep "$delay"
  if kill -9 "$pid" 2> /dev/null; then killed="killed after ${delay}s"; else killed="had finished after ${delay}s"; fi
  wait "$pid" 2> /dev/null
  phase=$(sed -n 's/.*"phase":"\([a-z]*\)".*/\1/p' "$out.work/state.json" 2> /dev/null)
  launches=1
  until [ -f "$out" ]; do
    build "$out" 2>> "$work/t$i.log"
    launches=$((launches + 1))
    [ "$launches" -gt 500 ] && break
  done
  if [ -f "$out" ] && cmp -s "$work/ref.mco" "$out"; then res=identical; ok=$((ok + 1)); else res=DIFFERENT; fi
  printf 'trial %2d: %-28s state phase %-8s launches %d: %s\n' "$i" "$killed" "${phase:-?}" "$launches" "$res"
done
echo "$ok of $trials identical"
[ "$ok" -eq "$trials" ]
