# routing-builder

`mcr` turns an OpenStreetMap extract into a **routing graph file** (`.mcr`) that [MapCova](https://mapcova.com)'s
`local-routing` extension reads, in plain PHP, on a shared host: no routing server, no extension, no long-running
process. The expensive work (cutting the network into segments, applying turn rules, building a Contraction
Hierarchy) happens here, once per zone and per profile; a route then costs a few thousand small file reads.

```
mcr build --profile car|car-notoll|bike|foot --zone <id> --input <extract.osm.pbf> --out <file.mcr> [--poly <zone.poly>]
mcr info <file.mcr>
mcr witnesses <file.mcr> [--count 20] [--seed 1] [--out routes.json]
```

| Profile | Graph | Modelled on | What it knows |
|---|---|---|---|
| `car` | edge-based | OSRM `car.lua` | speeds by road type, `maxspeed`, surface/tracktype/smoothness caps, one-ways, roundabouts, access hierarchy, **local-access-only roads** (`destination`, `private`, `customers`...: usable only to start or end a trip), turn and signal penalties, gates, U-turns, `no_*` / `only_*` restrictions **through a node or through one or more ways**, **ferries and car shuttle trains** (`route=ferry`, `route=shuttle_train`, with their `duration`), **service-road, narrow-road, side-road penalties** |
| `car-notoll` | edge-based | the same, without ways tagged `toll=yes` / `toll:motorcar=yes` | the graph behind "avoid tolls". Swiss motorways paid by vignette carry no toll tag and are not avoided |
| `bike` | node-based | OSRM `bicycle.lua` | 15 km/h base, `cycleway`, tracks and paths, surface speeds, one-ways with `oneway:bicycle` and opposite-direction cycleways, **pushing the bike** (4 km/h) where riding is not allowed, steps, ferries, barriers, signals |
| `foot` | node-based | OSRM `foot.lua` | 5 km/h, steps, surface speeds, `oneway:foot` only, sidewalks mapped as separate ways, ferries, barriers, signals |

Not modelled (each is a known gap, not a surprise): height/weight/width limits, directional access tags, conditional
restrictions, elevation, and for bikes the turn penalties of OSRM (a flat 1 s per junction stands in) and the 30 s
mode-change penalty. The file format is specified in [FORMAT.md](FORMAT.md).

## Building a zone

```sh
# 1. an extract (Geofabrik asks for no more than one download per extract and week)
curl -fL -o lu.osm.pbf https://download.geofabrik.de/europe/luxembourg-latest.osm.pbf

# several extracts into one zone (Switzerland and Liechtenstein are one zone, `ch-li`, so that routes
# near the border do not snap to the wrong country)
osmium merge ch.osm.pbf li.osm.pbf -o ch-li.osm.pbf

# 2. the graphs
cargo build --release
for p in car bike foot; do target/release/mcr build --profile $p --zone lu --input lu.osm.pbf --out lu-$p.mcr; done
target/release/mcr build --profile car-notoll --zone ch-li --input ch-li.osm.pbf --out ch-li-car-notoll.mcr

# 3. install on a MapCova instance (the local-routing extension)
php /path/to/plus-local-routing/bin/install-graph.php lu-car.mcr /path/to/mapcova/data
```

Zone identifiers are lower case letters, digits and dashes. `--poly` takes an Osmosis `.poly` file; the polygon
(simplified) is stored in the header, so that a point outside it is "out of area" and goes to the next routing
provider. Without it, the bounding box of the network is the zone.

Cost (Apple M-series, one core for the hierarchy): Luxembourg 2 s and 0.3 GB, Switzerland with Liechtenstein
45 s and 3.1 GB. Memory is about 10 bytes per OSM way node plus the graph; a 16 GB runner is enough for countries of
that size.

## Route checks and publishing

`mcr witnesses` writes **route checks**: twenty pairs of points (1e-6 degree) with the duration, in tenths of a second, that the reference
reader (`src/reader.rs`, the query of [FORMAT.md](FORMAT.md): snap, labels, bidirectional upward search) finds between them. They are
stable for a given file and seed. The updates server puts them in the signed manifest of the graph, and an instance that installs the
file replays them (at least 90 % of the routes within 2 %) before it serves it, falling back to the previous file otherwise.

`tools/publish-graphs.sh <zone> <extract.osm.pbf>` does the whole monthly job for one zone: `mcr build` for each profile, `mcr witnesses`,
then `bin/updates data:pack-graph` of the [mapcova-updates](https://github.com/mapcova/mapcova-updates) checkout, which cuts the file into
64 MiB raw-deflate blocks, writes the manifest (`candidate.json`) and pushes it to `https://updates.mapcova.com` with an `mdp_` publisher
token read from `MAPCOVA_PUBLISHER_TOKEN` (never printed, never an argument). The server re-checks the file (magic, zone and profile of the
header against the dataset name, the zone against its offers, the ODbL notice, the sections, the route checks, a 10 % shrink limit) and only
then signs. Datasets are named `<zone>-<profile>` (`ch-li-car`, `fr-car-notoll`) and versioned by the OSM date of the extract. Profiles:
`car` always, `car-notoll` where the car graph has toll segments, `bike` and `foot` with `PROFILES="car bike foot"`.

```sh
DRY_RUN=1 PROFILES="car foot" tools/publish-graphs.sh lu lu.osm.pbf     # build, check and pack, no push
MAPCOVA_PUBLISHER_TOKEN=mdp_... tools/publish-graphs.sh ch-li ch-li.osm.pbf
```

Build once a month (Geofabrik asks for at most one download per extract and week). The zone must be one of the `zones` of the server's
`config/offers.json`.

## Licence of what it produces (ODbL)

A graph is a *Derived Database* of OpenStreetMap under the Open Database License 1.0
([text](https://opendatacommons.org/licenses/odbl/1-0/)). If you hand a graph to someone, or serve one, you must:

- **attribute**: "(c) OpenStreetMap contributors" with a link to the ODbL, wherever the results of the graph are shown
  (the MapCova extension returns the attribution with every route);
- **share alike**: offer the graph itself under the ODbL, to anyone you gave it or its results to (the Produced Work
  rule, a route, is lighter: attribution is enough);
- **keep the notice**: every file starts with the notice (header offset 512 and `meta.license`); do not remove it;
- **publish the method of construction** (ODbL 4.6 b): this repository, with the profiles, is that method.

Do not put data that is not ODbL-compatible (proprietary traffic, cached results of commercial routing services) in a
graph. This section is a summary for operators, not legal advice.

## Tests

```sh
cargo fmt --check
cargo clippy --all-targets -- -D warnings
cargo test            # unit tests, and tests/synthetic.rs: tiny maps built in memory
```

`tests/synthetic.rs` checks the contracted graph against a plain Dijkstra on the same arcs (random one-way grids,
edge-based and node-based), then turn restrictions (`no_`, `only_`, via a way), ferries, local-access roads, tolls,
one-way streets for bikes and walkers, headers and barriers. `cargo run --example fixture -- <dir>` writes the
tiny test city that the PHP extension's tests read.

CI (`.github/workflows/ci.yml`, GitHub-hosted `ubuntu-latest`): format, clippy, build, tests.

## Comparing with OSRM

Every build can be checked against an [OSRM](https://github.com/Project-OSRM/osrm-backend) built on the same extract
(`osrm-extract -p /opt/car.lua`, `osrm-contract`), with `tools/bench.php` of the extension: random point pairs, absolute
difference of distance and duration, matrices. Results of this version are in [BENCHMARKS.md](BENCHMARKS.md).

## Licence

GNU Affero General Public License v3.0 (`LICENSE`). The profiles derive from OSRM (BSD-2-Clause, `NOTICE`);
`vendor/fast_paths` is fast_paths 1.0.0 (MIT or Apache-2.0).
