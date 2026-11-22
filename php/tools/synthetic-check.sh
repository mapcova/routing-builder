#!/usr/bin/env bash
# The checks of the README that need no Luxembourg extract, on the small town of the unit tests (tests/Support/GridMap.php):
# `kill -9` at random moments with byte-identical results, and, when the Rust builder is given, the same arcs and sections
# as `mcr build`. Cheap (seconds): CI runs the kill part, the parity part needs the Rust binary (`MCR=target/release/mcr`).
#
# usage: [MCR=/path/to/mcr] tools/synthetic-check.sh [work-dir] [trials=6]
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
work=${1:-$(mktemp -d "${TMPDIR:-/tmp}/mcr-synthetic.XXXXXX")}
trials=${2:-6}
mkdir -p "$work"
php -r 'require $argv[1] . "/vendor/autoload.php"; MapCova\GraphBuilder\Tests\Support\GridMap::write($argv[2]);' "$here" "$work/town.osm.pbf"
status=0
for profile in car bike; do
  echo "== $profile: kill -9, $trials trials"
  KILL_TEST_DIR="$work/kill-$profile" "$here/tools/kill-resume-test.sh" "$work/town.osm.pbf" "$profile" "$trials" 600 256M || status=1
  if [ "$profile" = car ]; then
    echo "== car: kill -9, slices of 50 ms (the contraction is resumed many times)"
    KILL_TEST_DIR="$work/kill-short" "$here/tools/kill-resume-test.sh" "$work/town.osm.pbf" car "$trials" 0.05 256M || status=1
  fi
  if [ -n "${MCR:-}" ]; then
    echo "== $profile: parity with $MCR"
    "$MCR" build --profile "$profile" --zone town --input "$work/town.osm.pbf" --out "$work/rust-$profile.mcr" --dump-arcs "$work/rust-$profile.arcs" > /dev/null
    php "$here/bin/build.php" --tile town --profile "$profile" --input "$work/town.osm.pbf" --out "$work/php-$profile.mcr" --dump-arcs "$work/php-$profile.arcs" --keep-work 2> /dev/null
    diff "$work/rust-$profile.arcs" "$work/php-$profile.arcs" && echo "arcs identical"
    php "$here/tools/compare-mcr.php" "$work/rust-$profile.mcr" "$work/php-$profile.mcr" || status=1
  fi
done
exit "$status"
