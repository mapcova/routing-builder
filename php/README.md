# routing-builder / php

The `.mcr` builder of the [Rust tool](../README.md), in **pure PHP**, for hosts that only run PHP: no FFI, no extension,
OPcache and JIT off, a web request of at most a few minutes, a few hundred MB of memory. It reads an OpenStreetMap
extract and writes the same file ([FORMAT.md](../FORMAT.md), version 1) as `mcr build`, in **resumable slices**: each
launch works for at most `--slice` seconds, saves a small checkpoint in a work directory and stops; the next launch
carries on from there. The last one writes the file, atomically.

This is the prototype of the design study "build the routing graphs in PHP on the host": the Luxembourg extract, the
four profiles, checked against the Rust builder arc by arc. The tiles and the links come in the [last section](#tiles-links-and-the-multi-level-reader).

| What | Where |
|---|---|
| Streaming PBF reader (zlib blobs, dense nodes, ways, restriction relations) | `src/Pbf`, `src/Osm` |
| Profiles `car`, `car-notoll`, `bike`, `foot`: line-by-line ports of `src/profile/*.rs`, `f32` arithmetic imitated | `src/Profile`, `src/Support/F32.php` |
| Network, edge-based and node-based graphs, turn restrictions (also through ways), strongly connected component | `src/Net` |
| Contraction Hierarchies, resumable, carrying weight, duration and distance | `src/Ch/Contractor.php` |
| `.mcr` writer (v1, and the two sections of a tile), phases and checkpoints | `src/Mcr`, `src/Build` |
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
`2` bad arguments, `11` another launch is running in the same work directory (nothing was done: try again later). A
scheduler loops on `10` and waits on `11`: a web cron calls the script, and when it exits with `10` and time is left,
calls its own URL again (the design: `mapcova-updates` already does this for the address data).

**One launch at a time.** A launch holds an exclusive `flock` on `<work>/.lock` from start to end; the operating system
drops it with the process, however it ends (`kill -9` included), so a crashed launch never blocks the next. Temporary files
carry a random suffix, and a launch removes those a killed one left.

**What identifies a build.** `state.json` stores a key made of the version of the code, the input (path, size, modification
time), the profile, the zone, the `.poly`, `--out` and `--dump-arcs`. A work directory is resumed only by the same key. A
finished build is "already built" (exit 0) only when the key matches **and** the output file is there; if the output is gone,
or the options or the input changed, the next launch starts over and replaces the file atomically when it is done. A work
directory in the middle of another build is refused (exit 1) rather than overwritten; a directory whose `state.json` is lost
or unreadable is emptied first, so that no stale phase file or contraction state is ever reused.

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
| `contract` | the hierarchy: priority queue, witness searches; `up.bin` grows, the state (`contract.state`) is saved when the slice ends, or earlier when the memory in use passes 40 % of the limit and keeps growing | the contracted node |
| `finish` | final ids (rank from the top), sections, file | restarts (seconds) |

The contraction is **deterministic** (priorities `1000 * (0.1 * level + (shortcuts + 1) / (arcs + 1))`, witness limits
500 / 100 / 500, heap keys `priority << 25 | node` are unique): resuming gives exactly the hierarchy of an uninterrupted
run, whatever the number and the moments of the interruptions (`tests/BuilderTest.php`, `tools/kill-resume-test.sh`).

`needed`, `graph` and `finish` run whole: their time must fit a slice (it does for a tile of a few hundred thousand
nodes; see the figures in [BENCHMARKS.md](BENCHMARKS.md)). A build that is killed inside one of them starts it again.

Because those phases cannot be resumed, a process killed for memory in one of them would die the same way at every launch.
So the builder estimates the peak before `graph` (330 bytes per kept node for the edge-based graph of `car`, 180 for the node-based one of `bike` and `foot`, from the Luxembourg measures with 20 % of margin) and before the start of `contract` (450 bytes per
graph node, 130 per arc of the sort that loads them) and stops with an error that says what the zone needs, when that is
above 85 % of the memory limit (`--memory`, else `memory_limit`): raise the limit or cut the zone.

**The contraction state** is written to `contract.state` piece by piece (about 1 MB of memory, not a copy of the state),
under a temporary name, checked, then renamed; it starts with the build key and the node count, which a restore checks, and
ends with a mark, so a truncated or foreign file is refused. A restore reads it piece by piece too. When the memory in use
passes 40 % of the limit and has grown by 5 % of it since the launch began, the contraction stops early (after at least 1000
contracted nodes, so that it always advances) and saves, while there is room to do it; a state that is big from the start
but not growing is saved at the end of the slice as usual.

**Checked writes.** Every write is checked against its length (a short write is continued, a refusal is an error), as are
`fflush`, `fclose`, the bytes copied into each section, and the size of the `.part` file before it is renamed: a full disk
ends the build with an error, never with a silently shorter file.

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
tools/synthetic-check.sh                             # the same on the small town of the tests (seconds); MCR=... adds the Rust parity
```

`tests/Support/PbfWriter.php` writes small PBF files; `GridMap` is a town with a one-way street, signals, a barrier,
node and via-way restrictions. The tests check that the hierarchy of every profile gives Dijkstra's distances on the
arcs, and that a build resumed after every phase and every few contracted nodes, or after garbage appended to a file
since the last checkpoint, is byte for byte the uninterrupted one.

## Tiles, links, and the multi-level reader

For a country that does not fit one file (the memory of the host sets the size of a tile, see below) the same builder cuts
the network into **tiles** and ties them with **links**. The format is in [FORMAT.md](../FORMAT.md#tiles-and-links-format-level-2);
the measures on Belgium and Luxembourg are in [BENCHMARKS.md](BENCHMARKS.md#tiles-belu).

| What | Where |
|---|---|
| The tiling: tile polygons, the tree of links, who owns a point | `src/Tiles`, `tiles/tiles.json`, `tiles/*.poly` |
| A tile: the extract of the tile plus a margin, cut down to the tile; border vertices frozen, core saved when K interior vertices are left (`--export-interior`, 1000), sections `border` (the exported vertices) and `core` | `--tiles` of `bin/build.php`, `src/Build/TileCut.php` |
| A link over its children (any number of levels), contracted inside, the top partly | `bin/build-link.php`, `src/Link` |
| Transit files (polylines of the ways between the exported vertices of a tile) | `bin/transit.php`, `src/Link/TransitBuilder.php` |
| The reference reader: up through the levels, across, down; Dijkstra on the remainder of the top; geometry | `src/Query` |
| Cutting the extracts of the tiles, acceptance against the whole graph and against the merged PHP graph (plain Dijkstra in C), reports | `tools/tile-extract.sh`, `tools/tiles-acceptance.php`, `tools/merged-export.php`, `tools/merged-dijkstra.c`, `tools/tiles-report.php` |

```sh
tools/tile-extract.sh tiles/tiles.json be-lu.osm.pbf extracts 3000      # one extract per tile, 3 km of margin (osmium)
php bin/build.php --tile lu --profile car --input extracts/lu.osm.pbf --out out/lu.mcr --tiles tiles/tiles.json --memory 256M
php bin/transit.php out/lu.mcr out/lu.mct
php bin/build-link.php --tiles tiles/tiles.json --link l1-flandre --dir out --memory 256M   # children's files are <id>.mcr / <id>.mco
                                                                                        # --export-interior 1000 (K_L), --remainder 300 (R, the top)
```

A tile is built exactly like a zone (same phases, checkpoints, slices, kill-and-resume), from the extract of its polygon plus a
margin; the roads of the margin that another tile owns are dropped after the network is built, so the turn costs and
restrictions next to the border are those of the whole network. Two options of the build key matter: `--tiles` (the tiling and its
polygons) and the extract. A link is built the same way (`bin/build-link.php`: phases `load`, `contract`, `finish`, state in its
work directory, exit codes as `build.php`), with the same contractor; its key includes the content of every child file. A cross arc
that the two tiles do not both have, alike, stops the build of the link with a message naming it.

**Why the export holds interior vertices.** The shortest ways between the border vertices of a tile are nearly a clique (every
entry to 40 to 70 % of the exits), 20 to 100 arcs a vertex, and the link above that contracts a clique of cliques is far too
slow. The tile freezes its border and contracts its interior until K = 1000 interior vertices are left (the main roads), takes
the snapshot then (about 5 arcs a vertex), and goes on contracting those K and the border for its own hierarchy. A link does
the same over the union of the exports of its children (K_L = 1000), and the top leaves R = 300 vertices to a Dijkstra. The whole
is one contraction hierarchy: tile interiors, then link interiors, then the rest, exact like a single graph.

**What a query does** (`Query\Router`): snap each end in its tile; in each file a multi-source upward search over the hierarchy
(a tile stops at its exported vertices, a link at the ones it exports); the labels at those exits are the sources of the level above;
at the lowest link holding both tiles the two searches meet (the top link finishes with a Dijkstra over its remainder); the route
goes on one level up while the cheapest possible way out of the group (cheapest label at the exits of each side) could beat the
route found (and each search of a level stops at the best known total minus the smallest label of the other side), so a route that leaves its group and comes back (Martelange to Arlon through Luxembourg) is found exactly. The
geometry is unpacked down through the links and child cores to the polylines of the transit files, and from the tiles of the two
ends by their own hierarchy. Two points on one road, the second behind the first, make a loop around a block that a hierarchy
finds only when the loop has a node above both ends: the whole graph and the tiles can differ there (the tests skip those pairs;
the acceptance counts them).

**Memory sets the size of a tile.** The `graph` phase holds the kept nodes of the extract, margin included (about 330 bytes
each, [above](#memory)), so a tile of more than about 600,000 kept nodes (300,000 nodes of the car graph, margin
included) is refused at 256 MB with the message the builder gives for any zone; cut it in two (`tiles/tiles.json`).

## Not in this version

Bike and foot tiles (the cross arcs are those of the edge-based graph; a node-based tile shares junctions instead), the
scheduler in `mapcova-updates`, the `plus-local-routing` port of the reader.
