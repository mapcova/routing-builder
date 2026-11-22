#!/usr/bin/env bash
# Build the graphs of one zone, check them with route checks, and publish them to the updates server.
#
#   tools/publish-graphs.sh <zone> <extract.osm.pbf>
#
# Per profile: `mcr build`, `mcr witnesses` (20 routes with the duration the reference reader finds), then
# `bin/updates data:pack-graph` of the mapcova-updates checkout, which cuts the file into 64 MiB deflate blocks, writes
# the manifest (`candidate.json`) and pushes it. The server re-checks everything and signs; this script never signs.
# Datasets are named `<zone>-<profile>` and versioned by the OSM date of the extract.
#
# Environment:
#   MAPCOVA_PUBLISHER_TOKEN  an `mdp_` publisher token limited to local-routing/* (never printed, never in argv)
#   PROFILES                 default "car"; `car-notoll` is added when the car graph has toll segments;
#                            add "bike" and/or "foot" to publish those too
#   POLY                     Osmosis .poly of the zone (default: the bounding box of the network)
#   VERSION                  YYYY-MM-DD, only when the extract carries no OSM timestamp
#   UPDATES_DIR              checkout of mapcova-updates (default ../mapcova-updates)
#   UPDATES_URL              default https://updates.mapcova.com
#   MCR                      the mcr binary (default target/release/mcr)
#   WORK_DIR                 where graphs and artifacts go (default: a new temporary directory, removed at the end)
#   DRY_RUN=1                build, check and pack, but do not push (no token needed)
#   KEEP=1                   keep the graphs and artifacts
set -euo pipefail
set +x # a trace would print the token

die() { echo "publish-graphs: $*" >&2; exit 1; }

[[ $# -eq 2 ]] || die "usage: $0 <zone> <extract.osm.pbf>"
ZONE=$1
INPUT=$2
[[ $ZONE =~ ^[a-z0-9][a-z0-9-]*$ ]] || die "the zone must be lower case letters, digits and dashes (a zone of config/offers.json)"
[[ -f $INPUT ]] || die "no such extract: $INPUT"

MCR=${MCR:-target/release/mcr}
UPDATES_DIR=${UPDATES_DIR:-../mapcova-updates}
UPDATES_URL=${UPDATES_URL:-https://updates.mapcova.com}
PROFILES=${PROFILES:-car}
DRY_RUN=${DRY_RUN:-0}
[[ -x $MCR ]] || die "build the tool first: cargo build --release (looked for $MCR)"
[[ -f $UPDATES_DIR/bin/updates ]] || die "no mapcova-updates checkout at $UPDATES_DIR (set UPDATES_DIR)"
if [[ $DRY_RUN != 1 ]]; then
  [[ ${MAPCOVA_PUBLISHER_TOKEN:-} == mdp_* ]] || die "set MAPCOVA_PUBLISHER_TOKEN to an mdp_ publisher token (or DRY_RUN=1)"
  export MAPCOVA_PUBLISHER_TOKEN
fi

if [[ -n ${WORK_DIR:-} ]]; then
  WORK=$WORK_DIR
  mkdir -p "$WORK"
else
  WORK=$(mktemp -d)
fi
cleanup() {
  [[ ${KEEP:-0} != 1 ]] || return 0
  if [[ -n ${WORK_DIR:-} ]]; then
    rm -rf "${WORK:?}/$ZONE"-* "${WORK:?}"/pack-"$ZONE"-*
  else
    rm -rf "${WORK:?}"
  fi
}
trap cleanup EXIT

publish_profile() {
  local profile=$1 graph="$WORK/$ZONE-$1.mcr" routes="$WORK/$ZONE-$1.routes.json"
  local build=(--profile "$profile" --zone "$ZONE" --input "$INPUT" --out "$graph")
  [[ -z ${POLY:-} ]] || build+=(--poly "$POLY")
  echo "== $ZONE-$profile: build"
  "$MCR" build "${build[@]}"

  echo "== $ZONE-$profile: route checks"
  "$MCR" witnesses "$graph" --count 20 --out "$routes"
  [[ $(grep -o '"duration_ds"' "$routes" | wc -l) -ge 20 ]] || die "$ZONE-$profile: fewer than 20 route checks"

  local pack=("$graph" "--witnesses=$routes" "--out=$WORK/pack-$ZONE-$profile")
  [[ -z ${VERSION:-} ]] || pack+=("--version=$VERSION")
  [[ $DRY_RUN == 1 ]] || pack+=("--push=$UPDATES_URL")
  echo "== $ZONE-$profile: pack$([[ $DRY_RUN == 1 ]] || echo ' and push')"
  php "$UPDATES_DIR/bin/updates" data:pack-graph "${pack[@]}"
}

has_tolls() {
  "$MCR" info "$WORK/$ZONE-car.mcr" | grep -Eo '"toll_segments":[0-9]+' | grep -vq ':0$'
}

for profile in $PROFILES; do
  case $profile in car | car-notoll | bike | foot) ;; *) die "unknown profile: $profile" ;; esac
  [[ $profile != car-notoll ]] || continue # decided from the car graph below
  publish_profile "$profile"
done

# The "avoid tolls" graph only exists where there are toll roads (meta.toll_segments > 0 in the car graph).
if [[ " $PROFILES " == *" car "* ]] && has_tolls; then
  publish_profile car-notoll
elif [[ " $PROFILES " == *" car-notoll "* && " $PROFILES " != *" car "* ]]; then
  publish_profile car-notoll
fi
echo "== $ZONE: done"
