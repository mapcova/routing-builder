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

## Not measured

Extrapolation to tiles of 0.25 to 0.9 M nodes, and the timings on the production host (OPcache off there as here; the design
study estimates a CPU factor of 1.5 to 2.5), are for the next step.
