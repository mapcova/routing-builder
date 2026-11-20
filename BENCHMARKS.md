# Benchmarks of version 0.1.0

Machine: Apple M-series Mac (18 cores, 64 GB), PHP 8.5 CLI with `memory_limit=256M` and CLI opcache, OSRM
`ghcr.io/project-osrm/osrm-backend:latest` (CH) built on the same Geofabrik extract (20 November 2026). Zones: `lu`
(Luxembourg) and `ch-li` (Switzerland and Liechtenstein, merged with `osmium merge`). The 1,000 pairs are random
points of the network moved by at most 30 m; errors are absolute differences against OSRM (`tools/bench.php` of the
extension; the routes are compared on the same trips, a PHP process is simulated by clearing the caches before each).
Disk cache warm; the cold path is measured on the target host with `tools/measure-host.php`.

## Car, before and after (proof of concept, then this version)

| | LU before | LU now | CH-LI before | CH-LI now |
|---|---|---|---|---|
| Duration error, median / p95 | 0.71 % / 4.96 % | **0.52 % / 1.69 %** | 0.46 % / 3.23 % | **0.36 % / 0.84 %** |
| Distance error, median / p95 | 0.15 % / 5.77 % | **0.13 % / 1.01 %** | 0.15 % / 4.95 % | **0.13 % / 0.44 %** |
| Pairs over 8 % (of 1,000) | 51 | **13** | 35 | **6** |
| Found by OSRM, not by us | 0 | 0 | 0 | 0 |
| File | 14.7 MB | 19.8 MB | 269.7 MB | 338.3 MB |
| Build time, peak memory | 1.9 s, 237 MB | 2.8 s, 274 MB | 35.7 s, 2.4 GB | 46-66 s, 2.8 GB |
| Route p50 / p95 (no geometry) | 1.9 / 3.0 ms | 2.0 / 3.3 ms | 3.7 / 5.9 ms | 4.1 / 7.5 ms |
| Route with geometry p50 / p95 | 3.7 / 5.2 ms | 3.8 / 6.2 ms | 10.2 / 14.5 ms | 14.5 / 36.5 ms |
| Matrix 1 x 25 p50 / p95 | 29.8 / 33.7 ms | 30.6 / 37.9 ms | 59.1 / 65.6 ms | 62.2 / 77.3 ms |
| Matrix 25 x 25 p50 / p95 | 58.7 / 73.6 ms | 61.6 / 71.2 ms | 114 / 134 ms | 116 / 169 ms |
| Peak PHP memory (real) | 24 MB | 24 MB | 46 MB | 44 MB |

What changed the accuracy: ferries and shuttle trains (the Alpine and Lake Constance gaps), via-way restrictions,
local-access roads, weights that differ from durations (service roads), the current OSRM tag tables. What
made the files bigger: 20-byte arcs (separate weight and duration), 40-byte segment records, more nodes (private and
service roads are kept, as OSRM does) and the copies for via-way restrictions. The CH-LI file now exceeds the 300 MB
target of the plan (338 MB, +25 %).

## The five reference routes (distance, duration; OSRM local / public demo in the plan)

| Route | Now | OSRM local | Public demo |
|---|---|---|---|
| Luxembourg - Esch-sur-Alzette | 18,962 m, 1,072 s | 18,983 m, 1,054 s | 18,933 m, 1,072 s |
| Lausanne - Geneva | 62,685 m, 2,851 s | 62,735 m, 2,850 s | 62,679 m, 2,858 s |
| Zurich - Bern | 123,434 m, 5,416 s | 123,584 m, 5,442 s | 123,584 m, 5,437 s |
| Martigny - Tasch | 99,580 m, 4,979 s | 99,714 m, 4,990 s | 99,638 m, 4,974 s |
| Vaduz - Sargans (with LI) | 18,087 m, 891 s | 18,098 m, 896 s | 18,098 m, 889 s |

All within 5 % of the public demo on distance and duration.

## Avoiding tolls (`car-notoll`)

Luxembourg has no toll-tagged road (a second car file is not needed there); Switzerland has some (OSRM `exclude=toll`
changes Lausanne - Geneva from 62.7 km to 59.4 km, and the duration by 65 %).

| Route, tolls avoided | Now | OSRM `exclude=toll` |
|---|---|---|
| Lausanne - Geneva | 59,337 m, 4,708 s | 59,389 m, 4,702 s |
| Zurich - Bern | 118,664 m, 9,189 s | 118,812 m, 9,175 s |

1,000 random pairs on CH-LI: duration median 0.35 %, p95 1.01 %, distance median 0.14 %, p95 1.21 % (4 pairs
where OSRM finds no route, we none either). The extra file costs the same as the main one (335.8 MB for CH-LI, 19.8 MB
for LU): the toll ways are rare but the hierarchy is rebuilt. A lighter alternative (one graph, a toll flag per arc, shortcuts that contain a toll arc
ignored) finds a route but not always the best one, so a second graph stays the exact solution; the header's `toll_segments` count lets a zone without toll roads skip it.

## Bike and foot (LU, node-based, against OSRM `bicycle.lua` and `foot.lua`)

| | Bike | Foot |
|---|---|---|
| Duration error, median / p95 | 1.07 % / 5.22 % | 0.12 % / 0.21 % |
| Distance error, median / p95 | 0.41 % / 3.44 % | 0.12 % / 0.23 % |
| Pairs over 8 % | 23 | 0 |
| Matrix 25 x 25 duration, median / p95 | 1.08 % / 4.98 % | 0.11 % / 0.21 % |
| File, build | 32.9 MB, 2.4 s | 34.2 MB, 3.2 s |
| Route p50 / p95, matrix 25 x 25 p50 | 3.9 / 9.7 ms, 112 ms | 3.9 / 8.6 ms, 117 ms |

The bike graph has no turn costs; a flat 1 s per junction of three roads or more stands in for OSRM's angle penalty.
That constant was calibrated on this same Luxembourg comparison (without it the bias is -3.1 %; at 2 s it is +3.4 %),
so the bike figures above are not an independent test: check them on another zone (Belgium) before trusting
the 1 % median.

## Cold path

Distinct 4 KiB pages read per route (the cold-cache cost is one disk access each): LU p50 312, p95 501; CH-LI p50 486,
p95 830, max 1,176. At 1 ms per random read on a network disk that is 0.5 to 0.85 s for CH-LI. Measure on the target host
with `tools/measure-host.php`.
