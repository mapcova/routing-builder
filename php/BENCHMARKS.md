# Benchmarks of the PHP builder (Luxembourg)

Extract: Geofabrik Luxembourg (`lu.osm.pbf`, 48 MB, OSM time 2026-11-20). Machine: Apple M5 Pro, PHP 8.5.12 CLI with OPcache
**and** JIT off (as on the target host), `memory_limit=256M`. Rust: `mcr` built with `cargo build --release`.

**The machine was shared and heavily loaded during these runs** (load average 14 to 44, 18 cores): the times below are
inflated, by a factor of about 2 for PHP (a quiet run of the car build took 16.6 s in one slice) and about 1.5 for Rust (2.4 s
quiet). Memory is not affected. Reproduce with `MCR=... tools/bench.sh lu.osm.pbf out` and the scripts of `tools/`.

## Build time and memory

| Profile | Rust (time, peak RSS) | PHP, one slice (time, peak) | PHP, slices of 5 s (slices, longest, peak) |
|---|---|---|---|
| car | 3.7 s, 251 MB | 31.5 s, 105 MB (41 %) | 6, 5.0 s, 125 MB (49 %) |
| car-notoll | 2.7 s, 273 MB | 27.2 s, 103 MB (40 %) | 6, 5.0 s, 125 MB (49 %) |
| bike | 2.3 s, 373 MB | 23.6 s, 152 MB (59 %) | 5, 5.3 s, 152 MB (59 %) |
| foot | 2.7 s, 378 MB | 30.1 s, 152 MB (59 %) | 6, 5.0 s, 152 MB (59 %) |

Peak = `memory_get_peak_usage(true)` over the launch, in % of 256 MB (the limit to stay under is 85 %: 217 MB). Every slice is far
under 240 s: one launch builds Luxembourg. Cutting the build into slices of 5 s costs nothing measurable (the state is saved and read back
between slices: a few MB, well under a second).

Phases of the car build (one slice, loaded machine): ways 1.0 s (peak 16 MB), needed 0.1 s (42 MB), nodes 0.9 s (12 MB),
graph 1.6 s (105 MB), contraction 27.4 s (74 MB), finish 0.4 s (73 MB). The contraction is nearly all the time, as in Rust. The
graph phase is the memory peak (the network and the arcs in memory); bike and foot peak in `needed` (the sort of 1 M ids per run,
150 MB) and `graph` (152 MB). Per node of the contracted graph the contraction needs about 450 bytes in all (74 MB for 162,524
nodes, including the PHP runtime), against the 210 bytes of the design study measured on the contraction alone.

## Same graph as the Rust builder

`diff` of the sorted arc lists before contraction (`mcr build --dump-arcs` against `bin/build.php --dump-arcs`), then
`tools/compare-mcr.php`:

| Profile | Arcs | Arc lists | Header | `segments` (no node ids) | `geometry` | `grid_dir` | `grid_lists` | `shadows` | `meta` |
|---|---|---|---|---|---|---|---|---|---|
| car | 268,187 | identical (0 lines differ) | identical | identical (89,073) | identical | identical | identical | identical (26 pairs) | identical |
| car-notoll | 268,187 | identical | identical | identical | identical | identical | identical | identical | identical |
| bike | 470,454 | identical | identical | identical (237,479) | identical | identical | identical | identical (0) | identical |
| foot | 467,685 | identical | identical | identical (234,611) | identical | identical | identical | identical (0) | identical |

(Luxembourg has no toll road: `car` and `car-notoll` are the same graph.) Contraction: the Rust hierarchy has 298,219 forward and
299,141 backward upward arcs for car, the PHP one 296,568 and 301,616: another tie-breaking where priorities are equal, the same
shortest paths. The PHP file is read by the Rust reader too (`mcr info`, `mcr witnesses`: the same 20 pairs, durations within 1 s).

## Routes

1,000 pairs of random points of the network (fixed seed 1), routed on the Rust-built and the PHP-built file with the reader of
`plus-local-routing` (`McrGraph`), `tools/compare-routes.php`:

| Profile | Weight identical | Duration within 1 s | Distance within 1 m |
|---|---|---|---|
| car | 1000 / 1000 (100 %) | 999 / 1000 (99.9 %) | 999 / 999 |
| car-notoll | 1000 / 1000 | 999 / 1000 | 999 / 999 |
| bike | 1000 / 1000 | 1000 / 1000 | 995 / 1000 |
| foot | 1000 / 1000 | 1000 / 1000 | 999 / 1000 |

The one pair of car (and car-notoll) more than 1 s apart, with the same weight 34588:

- pair 79, (50.120935, 6.057105) -> (49.710759, 6.254956): Rust 3441.1 s, PHP 3446.7 s (two paths of equal weight, one through
  service roads that weigh twice what they last).

Pairs within 1 s whose distance differs by more than 1 m (bike 5, foot 1): equal weight, another equally cheap path (10 m or 2 m
longer): bike 341, 492, 666, 827, 892; foot 884.

## Resume after `kill -9`

`tools/kill-resume-test.sh lu.osm.pbf car`: a build killed with SIGKILL at a random moment, relaunched until the file is written,
compared byte for byte with an uninterrupted build (fixed build time). Fresh work directory each time.

| Series | Kills | Result |
|---|---|---|
| default slice (240 s), delays 3-97 % of the build | 10 | 10 identical (killed in `contract` 9 times, `nodes` once) |
| default slice, delays 2-30 % | 10 | 10 identical (`graph` twice, `contract` eight times) |
| slices of 4 s (6 to 8 launches per build), delays 1-14 % | 10 | 10 identical (`nodes`, `graph`, `contract`, and one in `ways`) |
| slices of 4 s, no kill (launch to launch resume) | 2 | 2 identical (10 and 11 launches) |

`tests/BuilderTest.php` does the same in process: a build resumed after every phase and every few contracted nodes, and a work
directory with garbage appended to a file since the last checkpoint, are byte-identical to the uninterrupted build.

## After the hardening of the resume (`fix/php-builder-resume`)

The same Luxembourg extract and machine, `memory_limit=256M`, the code with the work-directory key, the lock, checked writes,
the streamed checkpoint and the preflight. **Nothing moved**: the car file built by the hardened builder (no `--dump-arcs`) is
byte for byte the one of the merged builder, with the same peaks (graph 103 MB, contraction 74 MB, finish 70 MB, slice peak 103 MB
= 40 % of the limit; the earlier table says 105 MB, the run-to-run spread of the allocator).

| Profile | Arc lists vs Rust | Sections vs Rust | Routes: weight identical | Duration within 1 s | Slice (one launch) |
|---|---|---|---|---|---|
| car | identical (268,187 arcs) | identical (89,073 segments, 26 shadow pairs) | 1000 / 1000 | 999 / 1000 (pair 79, as above) | 54 s on the loaded machine, peak 105 MB (41 %) |
| bike | identical (470,454) | identical (237,479 segments) | 1000 / 1000 | 1000 / 1000 | 38 s, peak 152 MB (59 %) |
| foot | identical (467,685) | identical (234,611 segments) | 1000 / 1000 | 1000 / 1000 | 56 s, peak 152 MB (59 %) |

(With `--dump-arcs` the contraction phase of the same process peaks at 86 MB instead of 74 MB: the sorted arc list of the dump leaves the
allocator with chunks it does not give back; the slice peak is unchanged.) The 40 % early-checkpoint rule did not fire on any of these
builds: the contraction of bike and foot starts above 40 % of 256 MB (102 MB) but does not grow.

Kill and resume, byte for byte against an uninterrupted build, car:

| Series | Result |
|---|---|
| slice 240 s, 8 kills at random moments (delays 3-97 % of the build) | 8 identical (4 killed in `contract`, 4 had just finished) |
| slices of 5 s, 4 trials, delays up to 49 s: the launches end by themselves | 4 identical (9 to 10 launches each: resume from launch to launch) |
| slices of 5 s, 6 kills inside a slice (delays 0.5-4.3 s) | 6 identical (killed in `ways`/`nodes`/`graph`, 8 to 16 launches each) |
| `tools/synthetic-check.sh`, small town, car and bike, 6 kills each, plus car with slices of 50 ms | all identical (about 6 s in all) |

## Tiles BE+LU

Belgium and Luxembourg as 15 tiles (14 groups of Belgian arrondissements and `lu`, `tiles/tiles.json`) tied by four links
(`l1-flandre`, `l1-wallonie-bruxelles`, `l2-be`, `l3-be-lu`), car profile, graph by arcs. Extracts: Geofabrik Belgium and Luxembourg
(OSM time 2026-11-21), merged, one extract per tile with a margin of 3 km (`tools/tile-extract.sh`). Machine: Apple M5 Pro as
above, **shared and loaded** (load average 24 to 33 during the runs, so the times are inflated, the longest ones most), builds
with `nice -n 10`, one process, PHP CLI with OPcache and JIT off, `--memory 256M --slice 235`. Each tile exports its border and
K = 1000 interior vertices, each link its outer border and K_L = 1000, the top leaves R = 300 vertices to a Dijkstra at query time
(`FORMAT.md`). The reference is the Rust graph of the same data (`mcr`, 320 MB). Reproduce with `tools/tile-extract.sh`,
`bin/build.php --tile ...`, `bin/transit.php`, `bin/build-link.php`, `tools/tiles-report.php`, `tools/tiles-acceptance.php`
and `tools/merged-export.php`.

### Tiles

| Tile | Nodes | Border vertices | Exported vertices | Cross arcs | Export arcs | Launches | Longest slice | Total | Peak memory | Size |
|---|---|---|---|---|---|---|---|---|---|---|
| be-antwerpen | 185,046 | 757 | 1757 | 516 | 8290 | 1 | 54 s | 54 s | 174 MB (68 %) | 24.4 MB |
| be-kempen-maasland | 207,808 | 1068 | 2068 | 735 | 9427 | 1 | 45 s | 45 s | 188 MB (73 %) | 28.5 MB |
| be-hasselt-leuven | 225,277 | 1420 | 2420 | 978 | 10246 | 1 | 43 s | 43 s | 190 MB (74 %) | 29.4 MB |
| be-gent-eeklo | 148,903 | 741 | 1741 | 523 | 7695 | 1 | 25 s | 25 s | 127 MB (50 %) | 18.9 MB |
| be-aalst-waasland | 129,041 | 776 | 1776 | 531 | 7730 | 1 | 22 s | 22 s | 112 MB (44 %) | 17.5 MB |
| be-brugge-kust | 190,716 | 812 | 1812 | 573 | 8380 | 1 | 48 s | 48 s | 174 MB (68 %) | 25.1 MB |
| be-kortrijk-ieper | 171,583 | 1492 | 2492 | 1055 | 11340 | 1 | 42 s | 42 s | 135 MB (53 %) | 23.2 MB |
| be-bruxelles-halle | 215,729 | 1286 | 2286 | 935 | 10604 | 1 | 44 s | 44 s | 192 MB (75 %) | 28.1 MB |
| be-nivelles-charleroi | 182,338 | 1443 | 2443 | 1009 | 10165 | 1 | 36 s | 36 s | 181 MB (71 %) | 24.1 MB |
| be-hainaut-ouest | 168,049 | 1571 | 2571 | 1100 | 10956 | 1 | 38 s | 38 s | 182 MB (71 %) | 23.6 MB |
| be-liege | 122,015 | 897 | 1897 | 607 | 8261 | 1 | 38 s | 38 s | 110 MB (43 %) | 16.1 MB |
| be-verviers-huy | 142,454 | 873 | 1873 | 622 | 7001 | 1 | 35 s | 35 s | 126 MB (49 %) | 17.9 MB |
| be-namur-dinant | 197,848 | 783 | 1783 | 545 | 8504 | 1 | 40 s | 40 s | 141 MB (55 %) | 25.8 MB |
| be-luxembourg | 54,425 | 489 | 1489 | 321 | 5866 | 1 | 9 s | 9 s | 82 MB (32 %) | 7.8 MB |
| lu | 161,075 | 274 | 1274 | 175 | 6479 | 1 | 26 s | 26 s | 129 MB (50 %) | 19.3 MB |

Tiles: 15, 2,502,307 nodes in all.

"Border vertices" have a cross arc, "exported vertices" are the border and the 1000 interior vertices of the snapshot, "export arcs"
are the arcs of the core among them: 3.7 to 5.1 arcs a vertex, where the border-only export of the first prototype had 21 to 102 a vertex (6 to 147
thousand arcs a tile). Every tile is **one launch**, 9 to 54 s in all (the prototype: 17 to 903 s, up to 4 launches). The contraction
alone (`phase contract` in the logs) takes 7.5 s for `be-luxembourg`, 48.6 s for `be-antwerpen`, 38.8 s for `be-hasselt-leuven`
(903 s in the prototype), 32.5 s for `be-nivelles-charleroi` (677 s), 37.3 s for `be-kortrijk-ieper` (641 s),
40.5 s for `be-kempen-maasland` (609 s), 35.8 s for `be-namur-dinant` (336 s); 0.13 to 0.52 million shortcuts a tile. The
transit files (`.mct`, polylines of the export arcs) are 1.2 to 2.7 MB a tile, 26.6 MB in all (up to 295 MB a tile and 1.5 GB in all
for the border-only export).

### Links

| Link | Children | Vertices | Outer border | Exported vertices | Remainder | Arcs (up fwd + bwd) | Export arcs | Launches | Longest slice | Total | Peak memory | Size |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| l1-flandre | 7 | 14066 | 2013 | 3013 | 0 | 63,356 + 62,687 | 15682 | 1 | 29.4 s | 29.4 s | 41 MB (16 %) | 4.2 MB |
| l1-wallonie-bruxelles | 7 | 14342 | 2186 | 3186 | 0 | 61,125 + 60,732 | 16283 | 1 | 27.1 s | 27.1 s | 43 MB (17 %) | 4.1 MB |
| l2-be | 2 | 6199 | 270 | 1270 | 0 | 25,990 + 25,541 | 8321 | 1 | 23.2 s | 23.2 s | 24 MB (9 %) | 1.9 MB |
| l3-be-lu | 2 | 2544 | 0 | 300 | 300 | 13,744 + 13,711 | 1843 | 1 | 10.3 s | 10.3 s | 20 MB (8 %) | 0.9 MB |

Every link is **one launch** of 10 to 30 s with a peak of 20 to 43 MB (8 to 17 % of 256 MB). In the prototype `l1-flandre` took
17,340 s in 73 launches, `l1-wallonie-bruxelles` was stopped at 2,523 of 7,320 vertices after 12 launches, and `l2-be` and `l3-be-lu` were
not built. Contraction alone: 29.3, 27.0, 23.2 and 10.2 s. The 3,531 cross arcs between the children of `l1-flandre` (and those of the
three other links) are each in the files of both tiles, with the same costs: a link stops with a message naming the arc otherwise
(0 cross arcs ignored or seen differently; the prototype ignored 101, see below).

All 19 files: 19 launches, longest slice 54 s, highest peak 192 MB (75 % of 256 MB).

### Cross arcs without a twin (the bug of the prototype)

The prototype ignored 101 cross arcs (`be-hainaut-ouest` to `be-kortrijk-ieper` 60, `lu` to `be-luxembourg` 29, `be-kempen-maasland` to
`be-antwerpen` 7, `be-brugge-kust` to `be-gent-eeklo` 3, `be-hainaut-ouest` to `be-namur-dinant` 1), because the other tile did not
have the road. Cause: a road in a cell that **no polygon covers** (the gaps between the polygons along the border: France, Germany, the
Netherlands) belongs to the tile whose bounding box is nearest, but the extract of a tile was cut from the cells its polygon covers
grown by the margin, so the extract of the owner lacked roads more than 3 km from its polygon. All 71 first nodes of the missing roads were in
uncovered cells. The fix (`TileSet`): the extract is cut from the cells a tile owns, covered or not, and the owner of an uncovered cell is
computed at the centre of the cell (it depended on which point asked first). After it, every cross arc of the 15 tiles has its
twin (checked on the files: 0 of the 10,225 cross arcs that leave a tile without one; and the links refuse the contrary).

A second effect of the cut: a tile saw only part of the network, and the largest strongly connected component it snaps to missed roads that
join the main network only through another tile. In a first acceptance run (after the fix above) three of the 1,000 pairs were wrong for that
reason: Rust snapped an end to a road (0.7 to 3 m away) that the tile did not take as part of the main network, so two routes were 3 % and 11 %
longer and one was missed (an end in `be-hainaut-ouest`, near the French border). The component is now computed with the exits and
entries of the cross arcs joined through one virtual vertex (`Scc::largest`): the world outside is taken as one connected whole. The
tiles were rebuilt; the figures of this section are those of the second run.

### Acceptance against the Rust graph

1,000 pairs, seed 1: 600 random points of the network of BE+LU, 200 pairs in one Belgian tile within 10 km of the Luxembourg border
(Arlon, Martelange: the best route may leave the tile and come back), 200 between Luxembourg and Belgium. Each pair is routed on
`be-lu-car.mcr` (Rust graph, read by the same reference reader) and on the tiles (`tools/tiles-acceptance.php`).

| Criterion | Result |
|---|---|
| Weight within 0.1 % of the Rust graph | **1,000 of 1,000** (all identical to the tenth of a second) |
| Duration within 1 s | 998 of 1,000 (99.8 %; the other two have the same weight, equally good routes: 18.2 s and 1.4 s apart) |
| Found by Rust, missed by the tiles | **0** (only by the tiles: 0; without a route in both: 0) |
| Weight equal to the merged graph of the PHP builder (`tools/merged-export.php`: the arcs of all the tiles before contraction and their cross arcs, plain Dijkstra in C, 2,502,307 nodes, 4,238,517 arcs) | **1,000 of 1,000** |
| Geometry of 300 routes: gaps between the polylines at the joints | 0 m (largest) |
| Length of the polyline against the distance of the route (median / p95 / max) | 2.3 / 10.1 / 16.7 m (the whole graph: 2.3 / 10.2 / 14.6 m) |
| Routes found at the level (random pairs and all) | tile 23, `l1-*` 88, `l2-be` 66, `l3-be-lu` 823 |
| Files read per route | 5.8 on average, 15 at most |
| Cross arcs ignored, or seen differently by the two tiles | 0 |
| Every slice 240 s or less, and 85 % of 256 MB or less | longest slice 54 s, highest peak 192 MB (75 %) |
| Each tile and each link 90 s or less (Mac) | longest 54 s |

### Latency of the PHP reader

200 same-tile routes near the border and 200 Belgium - Luxembourg routes, in ms (p50 / p95 / max). *Warm*: the same reader again, its
files open and their caches filled by a first pass. *Cold*: a new reader for each route (files opened again, nothing cached in PHP; the pages of the files are
in the operating system's cache: the system cache could not be purged without privileges, so a disk-cold figure is not measured).

| Routes | Warm | Warm, geometry | Cold | Cold, geometry | Settled nodes (mean / p95 / max) | Files (mean / max) |
|---|---|---|---|---|---|---|
| Same tile, near the border | 1 / 1.4 / 1.7 | 1.7 / 8.6 / 20.9 | 14.7 / 27.6 / 45.1 | 17.4 / 27.1 / 37.7 | 405 / 949 / 1600 | 3.5 / 5 |
| Belgium - Luxembourg | 2.5 / 6 / 17.4 | 5.3 / 10.1 / 19.8 | 28.8 / 60.1 / 165.5 | 43.5 / 80.2 / 144.3 | 1960 / 2680 / 3378 | 5 / 5 |

Budgets of the plan: same tile p95 15 ms (30 ms with geometry), BE-LU p95 30 ms (120 ms with geometry), cold p95 1 s: all met, warm by a factor of
3.5 or more, cold by 12 or more (the cold figure is what a PHP-FPM request pays, plus the reads from disk). Each level's search stops at the best known total minus the
smallest label of the other side (`Search::up` with a limit); the unpruned reader was not measured, so the gain is not quantified here (a unit test checks that the weights
below the limit are the same and that fewer nodes are settled). Whole run:
6.5 s for 1,000 routes (with geometry for 300) on the Rust graph, 7.7 s on the tiles.

### Resume after `kill -9`

`tools/kill-resume-test.sh` on the tile `be-luxembourg` and `tools/kill-resume-link.sh` on the link `l2-be`, 10 kills each at random moments (fixed seed), the
build relaunched until the file is written, the file compared byte for byte with an uninterrupted build (the time of the build is fixed).

| Test | Result |
|---|---|
| tile, slice 235 s, kills at 3-97 % of the build (0.9 to 8.1 s) | 10 killed, 10 identical (1 in `graph`, 9 in `contract`; 2 launches each) |
| tile, slices of 3 s (a checkpoint of the contraction at each launch), kills at 3-30 % (0.5 to 2.9 s) | 10 killed, 10 identical (4 in `graph`, 6 in `contract`; 4 to 6 launches each) |
| tile, slices of 3 s, kills at 3-97 % (1.0 to 8.5 s) | 2 killed, 8 launches had ended by themselves; 10 identical (3 to 5 launches) |
| link, slice 235 s, kills at 3-97 % (2.5 to 22.4 s) | 8 killed (in `contract`), 2 had finished; 10 identical (1 to 2 launches) |
| link, slices of 4 s, kills at 3-20 % (1.0 to 4.4 s) | 7 killed (in `contract`), 3 had ended by themselves; 10 identical (5 to 6 launches) |
| link, slices of 4 s, kills at 3-97 % (1.9 to 17.0 s) | 2 killed, 8 launches had ended by themselves; 10 identical (5 to 7 launches) |

The snapshot at K interior vertices is in the checkpoint (`MCRCHK05`), so a build killed after it resumes into the release and gives the same
file; `tests/ContractorTest.php` does the same in process (a checkpoint and a restore at every step).

### Stability of the `core_hash` (two extracts three days apart)

The same tiling built from the Geofabrik extracts of 2026-11-18 (`belgium-261118`, `luxembourg-261118`, public dated files, no account) and of
2026-11-21, same code and options. **14 of the 15 tiles have another `core_hash`** (only `be-aalst-waasland` kept it), and so do the four links: a
retouch anywhere in Belgium or Luxembourg reaches `l3-be-lu` (with a hash that changes at nearly every update, the propagation is the whole tree: 6 to 9 slices
in sequence on the production host). The change is small though: of the 130,944 export arcs of the 15 tiles, **123,053 (94.0 %) are the same arc** (same
two keys, same weight), 223 have the same ends and another weight, the others exist in one build only; the exported vertices have the same border in 11 tiles
(the other four have a few roads changed on the border), and 89 to 100 % of the 1,000 interior vertices of a tile are the same. The decision of the plan (choose
the exported interior vertices by road class if this is a nuisance) is therefore a question of how often the data are updated: the hash covers the weights, so it
changes with any retouch that moves a shortest way between two exported vertices, whatever the choice of the vertices; a choice by road class would make the
vertex set stable (fewer arcs only in one build) but not the weights. The comparison reads the `core_hash` of `meta.tile` and `meta.core_hash` and the export arcs (`TileFile::border()` and `core()`).

## Not measured

The timings on the production host (OPcache off there as here; the design study estimates a CPU factor of 1.5 to 2.5 against this machine: a tile of 54 s here is then
80 to 135 s, under the 240 s of a slice), the disk-cold latency of the reader (the system cache cannot be purged without privileges), the comparison of the
tiles with the Rust graph for the bike and foot profiles (tiles are built for the car profiles only), and the unpruned reader.
