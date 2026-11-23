#!/usr/bin/env bash
# Kills a build with `kill -9` at random moments, relaunches it, and checks that the file is byte for byte the one of
# an uninterrupted build.
#
# usage: tools/kill-resume-test.sh <extract.osm.pbf> <profile> [trials=10] [slice=240] [memory=256M] [seed=42] [from=0.03] [to=0.97]
#
# Each trial: a fresh work directory, a build started in the background, killed (SIGKILL) after a random delay between
# `from` and `to` (fractions, 3 % and 97 % by default) of the time an uninterrupted build takes, then relaunched until it reports the file written. The build
# time of the file is fixed (`--built-at`), so identical means identical.
#
# For a tile: KILL_TEST_TILE=lu KILL_TEST_EXTRA='--tiles tiles/tiles.json --junctions junctions.bin' tools/kill-resume-test.sh lu.osm.pbf car 10
set -u
in=${1:?extract}
profile=${2:?profile}
trials=${3:-10}
slice=${4:-240}
memory=${5:-256M}
seed=${6:-42}
from=${7:-0.03}
to=${8:-0.97}
here=$(cd "$(dirname "$0")/.." && pwd)
work=${KILL_TEST_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/mcr-kill.XXXXXX")}
mkdir -p "$work"
args() { # out
  echo -d memory_limit="$memory" "$here/bin/build.php" --tile "${KILL_TEST_TILE:-test}" --profile "$profile" --input "$in" --out "$1" \
    --slice "$slice" --built-at 1700000000 --work "$1.work" ${KILL_TEST_EXTRA:-}
}
build() { # out: one launch, in the foreground
  # shellcheck disable=SC2046
  nice php $(args "$1")
}

echo "reference build (uninterrupted, slice $slice s) in $work"
start=$(date +%s.%N)
build "$work/ref.mcr" 2> "$work/ref.log"
# a slice shorter than the build: go on until it is written
while [ ! -f "$work/ref.mcr" ]; do build "$work/ref.mcr" 2>> "$work/ref.log"; done
total=$(echo "$(date +%s.%N) - $start" | bc -l)
printf 'reference: %.1f s\n' "$total"

ok=0
for i in $(seq 1 "$trials"); do
  delay=$(awk -v s="$seed" -v i="$i" -v t="$total" -v a="$from" -v b="$to" 'BEGIN { srand(s * 1000 + i); printf "%.2f", t * (a + (b - a) * rand()) }')
  out="$work/t$i.mcr"
  rm -rf "$out" "$out.work" "$out.slices.json"
  # `exec`: the background process is the PHP process itself, so that the kill reaches it and nothing else goes on writing
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
  if [ -f "$out" ] && cmp -s "$work/ref.mcr" "$out"; then
    res=identical
    ok=$((ok + 1))
  else
    res=DIFFERENT
  fi
  printf 'trial %2d: %-28s state phase %-8s launches %d: %s\n' "$i" "$killed" "${phase:-?}" "$launches" "$res"
done
echo "$ok of $trials identical"
[ "$ok" -eq "$trials" ]
