# Tiles of Belgium and Luxembourg

`tiles.json` is the tiling the prototype uses: 14 tiles for Belgium and one for Luxembourg, in the tree of links of the plan
(`l1-flandre` and `l1-wallonie-bruxelles`, under `l2-be`, with `lu`, under `l3-be-lu`). `tiles-flat.json` is the same tiles under
one link over all of them (the single-level check of the acceptance). The polygons are the `*.poly` files; the rings are filled with
the even-odd rule, so a ring inside another is a hole.

The tiles are groups of the 43 Belgian arrondissements (admin_level 7) cut to fit the memory of the PHP builder at 256 MB: the
`graph` phase holds about 330 bytes per node of the extract, margin included, so a tile is at most about 125,000 road
segments (250,000 nodes of the car graph). Provinces are too big for that (Antwerpen, Oost-Vlaanderen, West-Vlaanderen, Liege,
Hainaut and the two Brabants all exceed it or come close), so the tiles are arrondissements grouped by neighbourhood. Brussels
is with Halle-Vilvoorde. `derive.py` writes the polygons and both tiling files from OpenStreetMap boundaries (the commands
that make its input are in its header).

A tile is built from its own extract: the polygon grown by a margin, plus every road that touches a node of that area
(`../tools/tile-extract.sh`), and from the list of the nodes that are junctions in the whole data set (`../tools/junctions.php`),
so that roads are cut at the same nodes in every tile.

The cells that no polygon covers (France, Germany, the Netherlands next to the border, the sea) belong to the tile with the nearest
bounding box, and the extract of a tile is cut from the cells it owns, covered or not, grown by the margin (`TileSet::margin`):
a road in the gap between two polygons is then in the extract of its owner, and the tile next to it finds the twin of its cross
arc. (Before that, 101 cross arcs of the first prototype named a road that the owner's extract lacked.)

`git`: the polygons and the two tiling files are marked `linguist-generated` in `.gitattributes`.
