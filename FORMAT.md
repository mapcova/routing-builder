# The `.mcr` format, version 1

One file per zone and profile: a road network prepared for routing with Contraction Hierarchies (CH).
Little-endian. Meant to be read with `fopen`/`fseek`/`fread`/`unpack` (pure PHP on a shared host) and never
loaded whole: a route reads a few thousand records. Written by `mcr build`; readers are the reference reader
in `src/reader.rs` and the MapCova `local-routing` extension.

**Versioning.** The header carries the format version (`1`). A reader refuses any other value. A change that
an old reader would misread bumps it; sections may be added at the end of the section table without a bump
only if old readers can ignore them. The two sections of a **tile** (`border`, `core`, [below](#tiles-and-links-format-level-2))
are such sections: they sit in entries 9 and 10 of the section table, which a reader of the nine sections never looks at,
and `meta.format_level` is `2`. The version field of a tile stays `1`, so that the readers that exist keep reading it.

**Units.** Coordinates are integers in 1e-6 degree. Weights and durations are tenths of a second, distances
are metres. All offsets are bytes from the start of the file, all sections start on a 4096-byte boundary.

## Header (4096 bytes)

| Offset | Type | Content |
|---|---|---|
| 0 | char[4] | magic `MCR\0` |
| 4 | u32 | format version (1) |
| 8 | u32 | profile: 0 car, 1 bike, 2 foot, 3 car without toll roads |
| 12 | u32 | graph type: 1 edge-based (car), 0 node-based (bike, foot) |
| 16, 20, 24, 28, 32 | u32 | nodes, forward upward arcs, backward upward arcs, segments, grid cells |
| 36 | i32 x4 | bounding box: min lat, min lon, max lat, max lon |
| 52 | u32 | grid cell size (5000 = 0.005 degree) |
| 56 | u32 | unix time of the OSM data (replication timestamp of the extract; 0 if unknown) |
| 60 | u32 | unix time of the build |
| 64 | char[32] | builder name and version, NUL padded (`mcr 0.1.0`) |
| 96 | char[32] | zone identifier, NUL padded (`lu`, `ch-li`) |
| 128 | 9 x (u64 offset, u64 length) | section table, in the order below |
| 512 | char[1024] | licence notice (below), NUL padded |

Sections: 0 `nodes`, 1 `up_fwd`, 2 `up_bwd`, 3 `segments`, 4 `geometry`, 5 `grid_dir`, 6 `grid_lists`,
7 `shadows`, 8 `meta`.

### Licence notice

Every file starts with the ODbL notice of the data it derives from, and so does `meta.license`:

> Contains information from OpenStreetMap, made available under the Open Database License (ODbL) 1.0 -
> https://opendatacommons.org/licenses/odbl/1-0/ - (c) OpenStreetMap contributors. This file is a Derived
> Database: if you share it, share it under the ODbL and keep this notice. Method of construction:
> https://github.com/mapcova/routing-builder (profiles adapted from OSRM, BSD-2-Clause).

### `meta` (section 8)

UTF-8 JSON: `format`, `zone`, `profile` (`car`, `car-notoll`, `bike`, `foot`), `graph` (`edge`/`node`),
`toll_segments` (segments of toll ways in the network; always 0 in `car-notoll`; tells a reader that a zone
without toll roads needs no separate `car-notoll` file), `osm_timestamp`, `built_at`, `builder`,
`bbox` (degrees: min lat, min lon, max lat, max lon), `polygon` (rings of `[lat, lon]` in degrees, simplified;
empty when the zone is its bounding box), `license`, `attribution`. A reader decides "in the zone" with the
polygon when there is one, the bounding box (inclusive) otherwise.

## Graph model

A **segment** is the stretch of an OSM way between two junctions (way ends, nodes shared by two ways, barriers,
signals, via nodes of restrictions). A segment has two directions, numbered `segment * 2 + direction`
(0 = along the way from its first node, 1 = against).

- **Edge-based** (car): a graph node is a *directed segment*. An arc A -> B costs the duration of A plus the
  penalty of the turn from A into B (signals, gates, angle, U-turns); restrictions are the absence of arcs.
  Via-way restrictions are modelled by *copies* of the restricted directed segments (section `shadows`).
- **Node-based** (bike, foot): a graph node is a junction. An arc follows one segment in one direction; no turn costs.

Node ids are CH ids: 0 is the top of the hierarchy, so the upper levels that every query visits are contiguous
and stay in the file cache. Nodes without any arc come last.

Every arc has a **weight** (what the shortest path minimises), a **duration** and a **distance**. They differ
where the profile penalises a road without making it slower: service roads (alley, driveway, parking aisle: x2),
narrow or unmarked roads, side roads, and *local-access-only* roads (`access=destination`, `private`,
`customers`, ...), which cost a very large weight to enter from the open network (so they serve only trips that
start or end there) but their true duration.

## Sections

**nodes** (section 0): `(N + 1)` records of 12 bytes: `first_fwd u32, first_bwd u32, segdir u32`. The upward
arcs of node *i* are `[first[i], first[i+1])` in `up_fwd` (arcs leaving i towards a higher node) and `up_bwd`
(arcs entering i from a higher node; `target` is that higher node). `segdir` is the directed segment of the
node in edge-based graphs, 0 otherwise. The last record is a sentinel.

**up_fwd, up_bwd** (sections 1, 2): 20 bytes per arc: `target u32, weight u32, duration u32, distance u32,
middle u32`.
`middle` with bit 31 set marks an original arc: edge-based graphs store `0xFFFFFFFF`, node-based graphs store
`0x80000000 | (segment * 2 + direction)` of the road the arc follows. Otherwise the arc is a shortcut `u -> w`
over the node `middle`; its two halves are the arc `u -> middle` (in `up_bwd[middle]`, `target = u`) and the arc
`middle -> w` (in `up_fwd[middle]`, `target = w`).

**segments** (section 3): 40 bytes: `geom_offset u32, geom_length u32, length_m u32, flags u32, dur_fwd u32,
dur_bwd u32, node_fwd u32, node_bwd u32, weight_fwd u32, weight_bwd u32`.
`flags`: bit 0 / bit 1 direction 0 / 1 allowed; bit 2 / bit 3 direction 0 / 1 usable to start or end a route
(in the largest strongly connected component and not a ferry or private service road); bit 4 / bit 5 direction
0 / 1 is local-access-only. `node_fwd` / `node_bwd`: edge-based: the graph node of direction 0 / 1
(`0xFFFFFFFF` if not allowed); node-based: the junction at the start / at the end of the segment.
Durations and weights are of the whole segment; ferries and shuttle trains use the `duration` tag of the way
shared out over its segments by length.

**geometry** (section 4): per segment, the first point as two i32 (lat, lon), then zigzag varint deltas (lat, lon)
for each further point.

**grid_dir** (section 5): `cells` sorted records `(key u32, list_offset u32)`, with
`key = lat_cell * 100000 + lon_cell`, `cell = floor((degree + offset) / 0.005)`, offsets 90 and 180 degrees
(computed on the 1e-6 integers: `(lat + 90_000_000) div 5000`). Binary search by key.
**grid_lists** (section 6): at `list_offset`: `count u32` then `count` segment ids. Only segments usable as a
start or end (bits 2 or 3) are indexed, each in every cell it crosses.

**shadows** (section 7): sorted `(node u32, copy u32)` pairs (8 bytes). A via-way restriction `from -> via ways
-> to` is built by copying the directed segments of the via ways: the arc entering the first via segment from the
`from` segment leads to the copies only, the copies lead on like the originals, except that the last copy lacks
the exits to the `to` way (`no_*`) or has only those (`only_*`). A route *ending* on a copied segment may have
arrived through its copy: a reader adds the copies of the target node as targets with the same label.

## Query

1. **Snap** each end to the nearest indexed segment within the radius (3 x 3 cells around the point, 200 m by
   default): projection on the polyline, `f` = fraction of the segment from its start.
2. **Labels** (weight, duration, distance), with `c` the cost of the whole directed segment and `fd` the fraction
   of that direction travelled when the point is reached (`f` for direction 0, `1 - f` for direction 1):
   - edge-based: source: node of each usable direction with label `-fd * c` (the arc out of a segment charges all
     of it); target: the same nodes (and their copies) with `+fd * c`;
   - node-based: source: leaving along direction d reaches its end junction with `(1 - fd) * c`; target: arriving
     along direction d from its start junction costs `fd * c`.
3. **Bidirectional upward Dijkstra** from the source labels over `up_fwd` and from the target labels over
   `up_bwd`; the best total `>= 0` at a common node wins. Node-based graphs also compare with driving straight
   along the segment when both points lie on the same one.
4. **Unpack** shortcuts through `middle` for the geometry: edge-based routes list the road of each arc's source
   node plus the road of the last node; node-based routes list the road of each original arc, with the partial
   first and last segments clipped at the snapped points.
5. **Matrix**: many-to-many with buckets (backward searches from each target fill buckets, forward searches from
   each source scan them).

## Tiles and links (format level 2)

A country is cut into **tiles** (administrative areas, one `.mcr` each), and the tiles are tied together by **links**
(`.mco`) in a tree: a link holds the border of its children (tiles or links) and is itself a child of the link above. A
route goes up through the levels from each end, across at the lowest link that holds both tiles, and down, and goes on
upward while the way out of the current group could still be cheaper; the result is exactly the one of a single graph.
`php/README.md` explains the build, `php/src/Query/Router.php` is the reference reader.

**Which tile owns a road.** The tiling file (`php/tiles/tiles.json`) lists the polygons (Osmosis `.poly`, even-odd rule) and the
tree of links. A road segment belongs to the tile that owns its *first point* (read from a raster of 0.002 degree cells, filled
from the polygons; a cell outside every polygon belongs to the tile with the nearest bounding box, measured from the centre of the
cell). A tile is built from the extract of the cells it owns, polygon and outside cells alike, plus a margin (3 km), so that the turn
costs, restrictions and junctions next to its border are those of the whole network and every road a tile owns is in its extract;
the roads of the margin owned by another tile are left out of the graph (their records in `segments` are zeroed: unusable, no node).

**Vertices, cross arcs.** In the edge-based graph a vertex is a directed road segment, so the tiles share no vertex: an arc
`A -> B` with `A` in one tile and `B` in another is a **cross arc**, computed (the same) from the roads by both tiles. The
end of a cross arc that lies in the tile is a **border vertex**. During the contraction of a tile its border vertices wait
(they are *frozen*) while the interior is contracted, down to the K most important interior vertices (the last K in the order of
the contraction, 1000 by default). Then the arcs among the vertices still there (the **core**: the border and the K interior
vertices, with the shortest ways between them inside the tile, shortcuts through the contracted interior included) are saved, and
the border and the K vertices are contracted last, in the order of their priority. The file is therefore a whole hierarchy that a
version 1 reader can route in, and the **exported** vertices (border and K) are its top: node ids `0 .. exported - 1`.
Exporting only the border would hand the level above a clique (every entry to every exit, 20 to 100 arcs a vertex); the K
interior vertices (main roads) carry the long ways, and the export stays sparse (about 5 arcs a vertex).

**Stable key** (28 bytes, big-endian, so the byte order is the numeric order): `u64 from OSM node, u64 to OSM node, u64 way,
u32 dc` of the directed segment, with `dc = direction | copy << 1`, where `copy` is 0 for the segment and the rank (from 1) of
the copy made for a via-way restriction among the copies of that segment, ordered by the key of the segment the restricted
sequence starts from. A vertex keeps its key from one build to the next as long as the road does not change.

### `border` (tile section 9)

`u32 vertices, u32 cross arcs, 8 bytes reserved`; then the exported vertices sorted by key, 36 bytes each (28-byte key, `u32` node
id in this file, `u32` flags: 1 a cross arc leaves the vertex, 2 one enters it (both: 3), 4 an exported interior vertex);
then the cross arcs, 52 bytes each, sorted by vertex, kind and key of the other end: `u32 vertex index,
u32 kind (0 leaves the tile, 1 enters it), 28-byte key of the vertex at the other end, u32 index of the other tile in the tiling,
u32 weight, u32 duration, u32 distance`. Both ends keep each cross arc (kind 0 in the tile it leaves, kind 1 in the other).

### `core` (tile section 10)

`u32 vertices, u32 arcs, 8 bytes reserved`, `core_hash` (SHA-1, 20 bytes, zero padded to 32), `vertices + 1` `u32` first-arc
indices, then the arcs, 20 bytes each: `u32 target (index in `border`), weight, duration, distance, middle` (`middle` as in
`up_fwd`: bit 31 marks an original arc, else the node a shortcut goes over; the halves are found in the records of that node, so a
core arc unpacks into the roads of the tile). `core_hash` covers the keys of the exported vertices, the core arcs (keys, weight,
duration, distance) and the cross arcs (kind, keys, weight, duration, distance), never the node ids, the indices or the middles:
**a tile rebuilt with the same hash needs none of the links above it rebuilt**, and a link that cites the hash is still valid.
`meta.tile` repeats it as hex (`id`, `index`, `border` vertices, `exported` vertices, `cross_arcs`, `core_arcs`, `core_hash`).
A rebuilt tile whose exported interior vertices (chosen by the order of the contraction) or the ways between them changed has a new
hash even if its border did not: a local edit can reach the links above more often than with a border-only export.

### Links: `.mco`

Header of 4096 bytes as a tile's (magic `MCO\0`, version 1, profile, graph type 1, vertices, forward and backward upward arcs,
at offset 36 the number of uncontracted vertices, at 96 the link id, the licence notice at 512), section table at 128 with eight
entries: 0 `nodes`, 1 `up_fwd`, 2 `up_bwd` (as in a tile), 3 `vertices`, 4 `orig`, 5 `border`, 6 `core`, 7 `meta`.

The vertices of a link are the vertices its children export, sorted by key (`vertices`: 28-byte key, `u32` node id).
Its arcs are the core arcs of its children and the cross arcs between two of its children; a cross arc must be in the files of
both its tiles, alike: the build stops otherwise. A vertex with a cross arc to a tile outside the link is on the **outer border**:
it is frozen, and left uncontracted with the K_L most important interior vertices (1000 by default); they are exported
(`border`, `core`, as for a tile, without cross arcs; flags 1 outer border, 4 interior) to the link above. A link with no outer
border (the top) is contracted except for a **remainder** of R vertices (300 by default): they are left uncontracted (flag 4), and a
query finishes by a Dijkstra over their `core` arcs (`core` header flag, third `u32`: 1). The uncontracted vertices have the lowest
node ids, in key order, so the core index of a vertex is its node id.

`orig` (12 bytes per original arc): `u32 kind, u32 source vertex, u32 target vertex` (indices in `vertices`): `kind` is the
index of a child (the arc is a core arc of that child between those two vertices), or `0x80000000 | tile index` (a cross arc of
that tile). The middle of an original arc in `up_fwd`, `up_bwd` and `core` is `0x80000000 | index in orig`, so that a path
unpacks through the links, the child cores and the tiles down to roads. `meta`: `link`, `children` and `tiles` with the
`core_hash` of each (what the link was built from), `outer_border`, `exported`, `remainder`, `core_hash`, counts.

### Transit files: `.mct`

For every core arc of a tile (exported vertex to exported vertex) and every cross arc that leaves it, the polyline of the roads,
simplified (0.5 m), so that a route through a tile that is not installed still has its geometry. Header of 64 bytes (magic
`MCT\0`, `u32` version 1, vertices, core arcs, cross arcs); the keys of the exported vertices (28 bytes each); `u32` first-arc
indices (vertices + 1) and `u32` targets of the core arcs (same order as in `core`); the cross arcs of kind 0 (`u32 vertex
index, 28-byte key of the other end`); `u32` offsets of the polylines (core arcs, then cross arcs, plus one); the polylines,
coded like `geometry`. A core arc covers the roads from its first vertex up to, not including, its last one (that road belongs
to the next arc, which starts with it), a cross arc the road of its source.
