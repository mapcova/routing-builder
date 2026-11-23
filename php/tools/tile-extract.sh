#!/usr/bin/env bash
# Cuts the extract of each tile (the tile and a margin) out of one merged, sorted extract with osmium:
#   tools/tile-extract.sh <tiles.json> <merged.osm.pbf> <out dir> [margin metres, default 3000]
# Writes <out dir>/<tile>.osm.pbf; the tile is then built with
#   php bin/build.php --tile <tile> --input <that file> --tiles <tiles.json> --junctions <junctions.bin> ...
#
# 1. the roads (and turn restrictions) of the merged extract are kept: `tags-filter w/highway w/route r/type=restriction`;
# 2. the region of the tile grown by the margin (`tile-margin.php`) is cut out with its complete ways;
# 3. every road that touches a node of that extract is added (`getparents`, then the nodes of those roads): the junctions at
#    the ends of the roads that leave the margin are then complete, and the turn costs there are those of the whole network.
set -eo pipefail
tiles=$1
input=$2
out=$3
margin=${4:-3000}
here=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$out"
roads="$out/roads.osm.pbf"
if [ ! -s "$roads" ] || [ "$input" -nt "$roads" ]; then
    nice -n 10 osmium tags-filter "$input" w/highway w/route r/type=restriction -o "$roads" --overwrite
fi
for t in $(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo implode(" ", array_keys($j["tiles"]));' "$tiles"); do
    php "$here/tile-margin.php" "$tiles" "$t" "$margin" "$out/$t.margin.poly"
    nice -n 10 osmium extract -p "$out/$t.margin.poly" -s complete_ways "$roads" -o "$out/$t.base.osm.pbf" --overwrite
    nice -n 10 osmium cat "$out/$t.base.osm.pbf" -t node -f opl -o - | cut -d' ' -f1 > "$out/$t.nodes.txt"
    nice -n 10 osmium getparents "$roads" -i "$out/$t.nodes.txt" -o "$out/$t.parents.osm.pbf" --overwrite
    nice -n 10 osmium cat "$out/$t.parents.osm.pbf" -f opl -o - | cut -d' ' -f1 > "$out/$t.parents.txt"
    # exit 1: some member of a relation (a restriction) is not a road of the data set, which is no error here
    nice -n 10 osmium getid -r "$roads" -i "$out/$t.parents.txt" -o "$out/$t.parents-full.osm.pbf" --overwrite || [ $? -eq 1 ]
    nice -n 10 osmium merge "$out/$t.base.osm.pbf" "$out/$t.parents-full.osm.pbf" -o "$out/$t.osm.pbf" --overwrite
    rm -f "$out/$t.base.osm.pbf" "$out/$t.parents.osm.pbf" "$out/$t.parents-full.osm.pbf" "$out/$t.nodes.txt" "$out/$t.parents.txt"
done
