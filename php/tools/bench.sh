#!/usr/bin/env bash
# Measures the Rust builder and the PHP builder on one extract, for each profile: time and peak memory of the Rust build
# (`/usr/bin/time -l`, macOS), time per slice and peak PHP memory (`memory_get_peak_usage(true)`) of the PHP build, once
# with one long slice and once with short slices.
#
# usage: MCR=/path/to/mcr tools/bench.sh <extract.osm.pbf> <out-dir> [profiles="car car-notoll bike foot"] [short-slice=5] [memory=256M]
set -u
in=${1:?extract}
out=${2:?output directory}
profiles=${3:-car car-notoll bike foot}
short=${4:-5}
memory=${5:-256M}
mcr=${MCR:-mcr}
here=$(cd "$(dirname "$0")/.." && pwd)
mkdir -p "$out"
echo "load average: $(uptime | sed 's/.*load averages*: //')" > "$out/bench.txt"
for p in $profiles; do
  { /usr/bin/time -l nice "$mcr" build --profile "$p" --zone lu --input "$in" --out "$out/rust-$p.mcr" > /dev/null; } 2> "$out/rust-$p.log"
  rs=$(awk '/real/ {print $1}' "$out/rust-$p.log")
  [ -z "$rs" ] && rs=$(sed -n 's/^ *\([0-9.]*\) real.*/\1/p' "$out/rust-$p.log")
  rmem=$(awk '/maximum resident set size/ {printf "%.0f", $1 / 1048576}' "$out/rust-$p.log")
  for mode in long short; do
    s=600
    [ "$mode" = short ] && s=$short
    o="$out/php-$p-$mode.mcr"
    rm -rf "$o" "$o.work" "$o.slices.json"
    n=0
    t0=$(date +%s.%N)
    while [ ! -f "$o" ]; do
      nice php -d memory_limit="$memory" "$here/bin/build.php" --tile lu --profile "$p" --input "$in" --out "$o" --slice "$s" --built-at 1700000000 2>> "$out/php-$p-$mode.log"
      n=$((n + 1))
      [ "$n" -gt 2000 ] && break
    done
    wall=$(echo "$(date +%s.%N) - $t0" | bc -l)
    php -r '
      $j = json_decode((string) file_get_contents($argv[1]), true);
      $s = is_array($j) ? ($j["slices"] ?? []) : [];
      $max = 0.0; $peak = 0; $sum = 0.0;
      foreach ($s as $x) { $max = max($max, $x["seconds"]); $peak = max($peak, $x["peak_bytes"]); $sum += $x["seconds"]; }
      printf("%s\t%s\tslices %d\tmax slice %.1f s\tsum of slices %.1f s\tpeak %.1f MB\n", $argv[2], $argv[3], count($s), $max, $sum, $peak / 1048576);
    ' "$o.slices.json" "$p" "$mode" >> "$out/bench.txt"
    echo "$p $mode wall $wall s" >> "$out/bench.txt"
  done
  printf '%s\trust\t%s s\t%s MB RSS\n' "$p" "$rs" "$rmem" >> "$out/bench.txt"
done
cat "$out/bench.txt"
