#!/usr/bin/env python3
"""Derives the tiles of Belgium and Luxembourg (php/tiles/*.poly, tiles.json, tiles-flat.json) from OpenStreetMap boundaries.

Belgium: the 43 arrondissements (admin_level=7, tag ref:INS) grouped into tiles that fit the memory of the PHP builder
(see ../README.md, "Memory sets the size of a tile"); Luxembourg: the country (admin_level=2) as one tile.

  osmium tags-filter belgium-latest.osm.pbf r/boundary=administrative -o bound.osm.pbf
  osmium tags-filter bound.osm.pbf r/admin_level=7 -o bound7.osm.pbf && osmium export bound7.osm.pbf --geometry-types=polygon -f geojson -o bound7.geojson
  osmium tags-filter lu.osm.pbf r/boundary=administrative -o lu-bound.osm.pbf && osmium tags-filter lu-bound.osm.pbf r/admin_level=2 -o lu-bound2.osm.pbf
  osmium export lu-bound2.osm.pbf --geometry-types=polygon -f geojson -o lu-bound2.geojson
  python3 -I derive.py bound7.geojson lu-bound2.geojson   # writes next to this file

Rings are simplified to 0.0003 degree (about 30 m) and written as plain rings: the reader fills them with the even-odd rule.
"""
import json
import math
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOL = 0.0003

# tile -> arrondissements (INS code, first two digits); the order of the tiles is the order of the tiling
TILES = {
    # Flandre
    'be-antwerpen': ['11', '12'],
    'be-kempen-maasland': ['13', '72', '73'],
    'be-hasselt-leuven': ['71', '24'],
    'be-gent-eeklo': ['44', '43'],
    'be-aalst-waasland': ['41', '42', '46'],
    'be-brugge-kust': ['31', '35', '37', '32', '38'],
    'be-kortrijk-ieper': ['33', '34', '36', '45'],
    # Wallonie et Bruxelles
    'be-bruxelles-halle': ['21', '23'],
    'be-nivelles-charleroi': ['25', '52', '58'],
    'be-hainaut-ouest': ['51', '53', '55', '57', '56'],
    'be-liege': ['62', '64'],
    'be-verviers-huy': ['63', '61'],
    'be-namur-dinant': ['92', '91', '93', '83', '84'],
    'be-luxembourg': ['81', '82', '85'],
    'lu': None,
}
GROUPS = [
    ('l1-flandre', ['be-antwerpen', 'be-kempen-maasland', 'be-hasselt-leuven', 'be-gent-eeklo', 'be-aalst-waasland', 'be-brugge-kust', 'be-kortrijk-ieper']),
    ('l1-wallonie-bruxelles', ['be-bruxelles-halle', 'be-nivelles-charleroi', 'be-hainaut-ouest', 'be-liege', 'be-verviers-huy', 'be-namur-dinant', 'be-luxembourg']),
]


def simplify(pts, tol):
    if len(pts) < 3:
        return pts
    keep = [False] * len(pts)
    keep[0] = keep[-1] = True
    stack = [(0, len(pts) - 1)]
    while stack:
        a, b = stack.pop()
        ax, ay = pts[a]
        dx, dy = pts[b][0] - ax, pts[b][1] - ay
        l2 = dx * dx + dy * dy
        dm, im = 0.0, -1
        for i in range(a + 1, b):
            px, py = pts[i][0] - ax, pts[i][1] - ay
            t = max(0.0, min(1.0, (px * dx + py * dy) / l2)) if l2 > 0 else 0.0
            d = math.hypot(px - t * dx, py - t * dy)
            if d > dm:
                dm, im = d, i
        if im >= 0 and dm > tol:
            keep[im] = True
            stack += [(a, im), (im, b)]
    return [p for p, k in zip(pts, keep) if k]


def rings(feature):
    g = feature['geometry']
    polys = g['coordinates'] if g['type'] == 'MultiPolygon' else [g['coordinates']]
    for poly in polys:
        for ring in poly:
            yield ring


def write_poly(name, features):
    out = name + '\n'
    k = 1
    for f in features:
        for r in rings(f):
            s = simplify([(x, y) for x, y in r], TOL)
            if len(s) < 4:
                continue
            out += f'{k}\n' + ''.join(f'   {x:.5f} {y:.5f}\n' for x, y in s) + 'END\n'
            k += 1
    open(os.path.join(HERE, name + '.poly'), 'w').write(out + 'END\n')


def main(bound7, lu2):
    arr = {}
    for f in json.load(open(bound7))['features']:
        p = f['properties']
        if p.get('admin_level') == '7' and p.get('ref:INS'):
            arr[p['ref:INS'][:2]] = f
    country = [f for f in json.load(open(lu2))['features'] if f['properties'].get('admin_level') == '2' and f['properties'].get('name') == 'Lëtzebuerg']
    used = [c for codes in TILES.values() if codes for c in codes]
    assert sorted(used) == sorted(arr), (set(arr) ^ set(used))
    assert len(country) == 1
    for name, codes in TILES.items():
        write_poly(name, [arr[c] for c in codes] if codes else country)
    tiles = {n: n + '.poly' for n in TILES}
    l1 = [{'id': i, 'children': c} for i, c in GROUPS]
    tree = {'id': 'l3-be-lu', 'children': [{'id': 'l2-be', 'children': l1}, 'lu']}
    json.dump({'name': 'be-lu', 'cell': 2000, 'tiles': tiles, 'tree': tree}, open(os.path.join(HERE, 'tiles.json'), 'w'), indent=2)
    json.dump({'name': 'be-lu-flat', 'cell': 2000, 'tiles': tiles, 'tree': {'id': 'flat-be-lu', 'children': list(TILES)}}, open(os.path.join(HERE, 'tiles-flat.json'), 'w'), indent=2)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
