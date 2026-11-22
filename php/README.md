# routing-builder / php

The `.mcr` builder of the [Rust tool](../README.md), in **pure PHP**, for hosts that only run PHP: no FFI, no extension,
OPcache and JIT off, a web request of at most a few minutes, a few hundred MB of memory. It reads an OpenStreetMap
extract and writes the same file ([FORMAT.md](../FORMAT.md), version 1) as `mcr build`, in **resumable slices**: each
launch works for at most `--slice` seconds, saves a small checkpoint in a work directory and stops; the next launch
carries on from there. The last one writes the file, atomically.

This is the prototype of the design study "build the routing graphs in PHP on the host": the Luxembourg extract, the
four profiles, checked against the Rust builder arc by arc. The tile and link levels come later (see the end).

| What | Where |
|---|---|
| Streaming PBF reader (zlib blobs, dense nodes, ways, restriction relations) | `src/Pbf`, `src/Osm` |
| Profiles `car`, `car-notoll`, `bike`, `foot`: line-by-line ports of `src/profile/*.rs`, `f32` arithmetic imitated | `src/Profile`, `src/Support/F32.php` |
| Network, edge-based and node-based graphs, turn restrictions (also through ways), strongly connected component | `src/Net` |
| Contraction Hierarchies, resumable, carrying weight, duration and distance | `src/Ch/Contractor.php` |
| `.mcr` v1 writer, phases and checkpoints | `src/Mcr`, `src/Build` |
| Command line | `bin/build.php` |
| Comparison with the Rust builder, kill test | `tools/` |

## Requirements

PHP 8.3 or later with `zlib` (8.5 on the target host). No other extension. `composer install` is needed only for the
tests: the library has no dependency.

## Use

```sh
php bin/build.php --tile lu --profile car --input lu.osm.pbf --out lu-car.mcr --slice 240 --memory 256M
```

| Option | |
|---|---|
| `--tile <id>` | zone identifier written in the header (lower case letters, digits, dashes), as `mcr build --zone` |
| `--profile` | `car`, `car-notoll`, `bike` or `foot` |
| `--input`, `--out` | the extract (sorted by id, as Geofabrik's are) and the file to write |
| `--slice <s>` | seconds one launch may work (default 240); checked after every node and every block |
| `--memory <size>` | `memory_limit` of the process (`256M`); the peak of each slice is reported against it |
| `--work <dir>` | work directory (default `.<out>.work` beside the output) |
| `--poly <file>` | Osmosis `.poly` of the zone, simplified into the header as `mcr build --poly` |
| `--built-at <unix time>` | fixed build time (default: when the file is written): two builds are then byte-identical |
| `--dump-arcs <file>` | also write the arcs before contraction, sorted (see [the comparison](#same-graph-as-the-rust-builder)) |
| `--keep-work` | keep the work directory after the file is written |

**Exit status**: `0` the file is written, `10` the slice is over and there is more to do (launch it again), `1` an error,
`2` bad arguments. A scheduler loops on `10`: a web cron calls the script, and when it exits with `10` and time is left,
calls its own URL again (the design: `mapcova-updates` already does this for the address data).

### Phases and checkpoints

The work directory holds the phase files and `state.json`. Every phase writes `x.tmp` and renames, or appends to a file
whose length the state records: a launch killed at any moment (`kill -9`) loses at most what it did since its last
checkpoint, and the next launch cuts the files back to the recorded lengths.

| Phase | Does | Resumes at |
|---|---|---|
| `ways` | streams the PBF: the ways the profile keeps and the restrictions, as binary records | the blob (state saved every 2 s) |
| `needed` | external sort (sorted runs, one merge) of the node ids the ways use | restarts (seconds) |
| `nodes` | streams the PBF again: coordinates and flags by a **sorted merge** with the needed ids, no id table in memory | the blob and the position in the needed ids |
| `graph` | network, arcs, strongly connected component, segments, geometry, grid | restarts (a few seconds on LU) |
| `contract` | the hierarchy: priority queue, witness searches; `up.bin` grows, the state (`contract.state`, a few MB) is saved when the slice ends | the contracted node |
| `finish` | final ids (rank from the top), sections, file | restarts (seconds) |

The contraction is **deterministic** (priorities `1000 * (0.1 * level + (shortcuts + 1) / (arcs + 1))`, witness limits
500 / 100 / 500, heap keys `priority << 25 | node` are unique): resuming gives exactly the hierarchy of an uninterrupted
run, whatever the number and the moments of the interruptions (`tests/BuilderTest.php`, `tools/kill-resume-test.sh`).

`needed`, `graph` and `finish` run whole: their time must fit a slice (it does for a tile of a few hundred thousand
nodes; see the figures in [BENCHMARKS.md](BENCHMARKS.md)). A build that is killed inside one of them starts it again.

### Memory

Whatever is large lives in binary strings or files, not in PHP arrays (16 bytes an element): node coordinates, flags and
junction ids are strings of fixed-size records, the arcs are one string of 24-byte records, the per-node adjacency of the
contraction is two strings and a third of durations and distances. The nodes pass never holds an id table (sorted merge). The
network build keeps one hash table, OSM id to node index of the kept nodes, until the ways are cut. Measured on Luxembourg
(323,000 kept nodes, 162,000 graph nodes for car): peak 105 MB for car and 152 MB for bike and foot, of 256 MB
([BENCHMARKS.md](BENCHMARKS.md)). `bin/build.php` prints the peak of the launch in % of the limit and warns above 85 %. The
input must be sorted by id (Geofabrik's are; `osmium sort` otherwise): the builder refuses ways that are not. To build again
from scratch, delete the work directory.

## Same graph as the Rust builder

The port is exact: the Rust code is the specification, including its single-precision arithmetic. Rust computes
`speed`, `rate` and the maxspeed arithmetic in `f32`; PHP floats are doubles, so every operation Rust rounds to single
precision goes through `F32::r()` (`unpack('g', pack('g', $x))`). `haversine`, `bearing`, `to_radians` use the same
formulas and constants as `src/geom.rs` (PHP's `deg2rad` is not `f64::to_radians`). Rounding is half away from zero as
in Rust, coordinates are converted from nanodegrees as `osmpbf` does.

Two small changes to the Rust side make the comparison possible:

- `mcr build ... --dump-arcs <file>` writes the arcs of the graph **before contraction**, one `src dst weight duration
  distance` line each, sorted;
- the Rust builder now sorts the ways (and the restrictions) it reads, because it reads blocks in parallel and the
  order, and with it every segment and node number, depended on the thread schedule. The PHP builder reads the file in
  order, which for a sorted extract is the same order.

`tools/` then checks a PHP-built file against a Rust-built one:

```sh
mcr build --profile car --zone lu --input lu.osm.pbf --out rust-lu-car.mcr --dump-arcs rust-lu-car.arcs
php bin/build.php --tile lu --profile car --input lu.osm.pbf --out lu-car.mcr --dump-arcs lu-car.arcs --keep-work
diff rust-lu-car.arcs lu-car.arcs                                   # every arc: source, destination, weight, duration, distance
php tools/compare-mcr.php rust-lu-car.mcr lu-car.mcr                # sections
php tools/compare-routes.php rust-lu-car.mcr lu-car.mcr --reader ../plus-local-routing/src/Graph/McrGraph.php --pairs 1000
```

`compare-mcr.php` checks the header, `segments` (graph node ids masked: they depend on the hierarchy), `geometry`,
`grid_dir`, `grid_lists`, `shadows` (renumbered to directed segments) and `meta` (without `builder` and `built_at`).
`compare-routes.php` routes random pairs with the reader of the MapCova `local-routing` extension on both files and
compares the weight, which must be identical, and the duration, which may differ by a second where two paths of the same
weight differ in duration (service roads cost twice, local-access roads cost a lot but take their true time); it lists
every pair more than one second apart.

The hierarchy itself is not the Rust one (another tie-breaking where priorities are equal, the Rust `BinaryHeap`
order is not specified): the `nodes`, `up_fwd` and `up_bwd` sections differ, the shortest paths do not.

## Tests

```sh
composer install
vendor/bin/phpunit          # profiles, PBF reader and passes, contraction (resumed and not), whole builds, resume after a kill
vendor/bin/phpstan analyse  # level max
tools/kill-resume-test.sh lu.osm.pbf car 10          # kill -9 at random moments, files byte-identical
```

`tests/Support/PbfWriter.php` writes small PBF files; `GridMap` is a town with a one-way street, signals, a barrier,
node and via-way restrictions. The tests check that the hierarchy of every profile gives Dijkstra's distances on the
arcs, and that a build resumed after every phase and every few contracted nodes, or after garbage appended to a file
since the last checkpoint, is byte for byte the uninterrupted one.

## Not in this version

The design study continues from here: tiles (an administrative cut of a country, one `.mcr` each), the `border` and
`core` sections of format v2, the links between tiles, the scheduler in `mapcova-updates`. This library builds one
`.mcr` v1 per run; for a zone that fits the memory of the host (about 210 bytes per node of the graph at the peak of the
contraction, plus the network) it is already the whole job.
