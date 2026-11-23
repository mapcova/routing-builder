/*
 * Dijkstra on the merged graph of all the tiles (tools/merged-export.php writes its input), the reference the layered reader
 * must equal exactly:  merged-dijkstra <graph.bin> <queries.bin> <results.bin>
 *
 * graph.bin: u32 nodes, u32 arcs, then per arc u32 source, target, weight (little endian).
 * queries.bin: u32 count, then per query u32 n sources, (u32 node, i32 label) each, u32 n targets, (u32 node, i32 label) each.
 * results.bin: per query an i64: the cheapest label + path + label (not below 0, as the reader's meeting rule), or -1.
 * Multi-source: the labels of the sources are the start distances (they may be negative: the arc out of a segment costs all of it).
 */
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>

/* stops with a message when an allocation fails (the graphs are large) */
static void *ck(void *p)
{
    if (!p) {
        fprintf(stderr, "merged-dijkstra: out of memory\n");
        exit(3);
    }
    return p;
}

typedef struct { int64_t k; uint32_t v; } E;
static E *heap; static size_t hn, hcap;
static void push(int64_t k, uint32_t v) {
    if (hn == hcap) { hcap = hcap ? hcap * 2 : 1 << 20; heap = realloc(heap, hcap * sizeof(E)); if (!heap) { fprintf(stderr, "merged-dijkstra: out of memory\n"); exit(3); } }
    size_t i = hn++;
    while (i > 0) { size_t p = (i - 1) / 2; if (heap[p].k <= k) break; heap[i] = heap[p]; i = p; }
    heap[i].k = k; heap[i].v = v;
}
static E pop(void) {
    E top = heap[0], last = heap[--hn];
    size_t i = 0;
    for (;;) {
        size_t c = 2 * i + 1;
        if (c >= hn) break;
        if (c + 1 < hn && heap[c + 1].k < heap[c].k) c++;
        if (heap[c].k >= last.k) break;
        heap[i] = heap[c]; i = c;
    }
    if (hn > 0) heap[i] = last;
    return top;
}
static void rd(void *p, size_t n, FILE *f) { if (fread(p, 1, n, f) != n) { fprintf(stderr, "short read\n"); exit(2); } }

int main(int argc, char **argv) {
    if (argc < 4) { fprintf(stderr, "usage: merged-dijkstra graph.bin queries.bin results.bin\n"); return 2; }
    FILE *g = fopen(argv[1], "rb"), *q = fopen(argv[2], "rb"), *o = fopen(argv[3], "wb");
    if (!g || !q || !o) { fprintf(stderr, "cannot open files\n"); return 2; }
    uint32_t n, m; rd(&n, 4, g); rd(&m, 4, g);
    uint32_t *raw = ck(malloc((size_t)m * 12)); rd(raw, (size_t)m * 12, g);
    uint32_t *first = ck(calloc((size_t)n + 2, 4)), *to = ck(malloc((size_t)m * 4)), *wt = ck(malloc((size_t)m * 4));
    for (uint32_t i = 0; i < m; i++) first[raw[i * 3] + 1]++;
    for (uint32_t i = 0; i < n; i++) first[i + 1] += first[i];
    uint32_t *fill = ck(malloc((size_t)n * 4)); for (uint32_t i = 0; i < n; i++) fill[i] = first[i];
    for (uint32_t i = 0; i < m; i++) { uint32_t p = fill[raw[i * 3]]++; to[p] = raw[i * 3 + 1]; wt[p] = raw[i * 3 + 2]; }
    free(raw); free(fill);
    int64_t *dist = ck(malloc((size_t)n * 8)); int64_t *tl = ck(malloc((size_t)n * 8));
    const int64_t INF = INT64_MAX / 4;
    for (uint32_t i = 0; i < n; i++) { dist[i] = INF; tl[i] = INF; }
    uint32_t nq; rd(&nq, 4, q);
    uint32_t *touched = ck(malloc((size_t)n * 4));
    for (uint32_t qi = 0; qi < nq; qi++) {
        uint32_t ns; rd(&ns, 4, q);
        uint32_t *sn = ck(malloc(ns * 4)); int32_t *sl = ck(malloc(ns * 4));
        for (uint32_t i = 0; i < ns; i++) { rd(&sn[i], 4, q); rd(&sl[i], 4, q); }
        uint32_t nt; rd(&nt, 4, q);
        uint32_t *tn = ck(malloc(nt * 4)); int32_t *tlab = ck(malloc(nt * 4));
        int64_t mint = INF;
        for (uint32_t i = 0; i < nt; i++) { rd(&tn[i], 4, q); rd(&tlab[i], 4, q); if (tl[tn[i]] > tlab[i]) tl[tn[i]] = tlab[i]; if (tlab[i] < mint) mint = tlab[i]; }
        size_t ntouch = 0; hn = 0;
        for (uint32_t i = 0; i < ns; i++) { if (sl[i] < dist[sn[i]]) { if (dist[sn[i]] == INF) touched[ntouch++] = sn[i]; dist[sn[i]] = sl[i]; push(sl[i], sn[i]); } }
        int64_t best = INF;
        while (hn > 0) {
            E e = pop();
            if (e.k > dist[e.v]) continue;
            if (best < INF && e.k + mint >= best) break;
            if (tl[e.v] < INF) { int64_t tot = e.k + tl[e.v]; if (tot >= 0 && tot < best) best = tot; }
            for (uint32_t a = first[e.v]; a < first[e.v + 1]; a++) {
                int64_t nd = e.k + wt[a];
                if (nd < dist[to[a]]) { if (dist[to[a]] == INF) touched[ntouch++] = to[a]; dist[to[a]] = nd; push(nd, to[a]); }
            }
        }
        int64_t res = best < INF ? best : -1;
        fwrite(&res, 8, 1, o);
        for (size_t i = 0; i < ntouch; i++) dist[touched[i]] = INF;
        for (uint32_t i = 0; i < nt; i++) tl[tn[i]] = INF;
        free(sn); free(sl); free(tn); free(tlab);
    }
    fclose(o);
    return 0;
}
