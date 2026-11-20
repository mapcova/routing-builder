//! Largest strongly connected component (iterative Tarjan): routes snap only onto it.

use crate::ch::Arc;
use crate::net::NONE;

pub fn largest_scc(n: usize, arcs: &[Arc]) -> Vec<bool> {
    let mut first = vec![0u32; n + 1];
    for a in arcs {
        first[a.src as usize + 1] += 1;
    }
    for i in 0..n {
        first[i + 1] += first[i];
    }
    let mut fill = first.clone();
    let mut adj = vec![0u32; arcs.len()];
    for a in arcs {
        adj[fill[a.src as usize] as usize] = a.dst;
        fill[a.src as usize] += 1;
    }
    let mut index = vec![NONE; n];
    let mut low = vec![0u32; n];
    let mut on = vec![false; n];
    let mut comp = vec![NONE; n];
    let mut stack: Vec<u32> = Vec::new();
    let mut call: Vec<(u32, u32)> = Vec::new();
    let (mut idx, mut ncomp) = (0u32, 0u32);
    let mut sizes: Vec<u32> = Vec::new();
    for root in 0..n as u32 {
        if index[root as usize] != NONE {
            continue;
        }
        call.push((root, first[root as usize]));
        index[root as usize] = idx;
        low[root as usize] = idx;
        idx += 1;
        stack.push(root);
        on[root as usize] = true;
        while let Some(&(v, ei)) = call.last() {
            if ei < first[v as usize + 1] {
                call.last_mut().unwrap().1 += 1;
                let w = adj[ei as usize];
                if index[w as usize] == NONE {
                    index[w as usize] = idx;
                    low[w as usize] = idx;
                    idx += 1;
                    stack.push(w);
                    on[w as usize] = true;
                    call.push((w, first[w as usize]));
                } else if on[w as usize] {
                    low[v as usize] = low[v as usize].min(index[w as usize]);
                }
            } else {
                call.pop();
                if let Some(&(p, _)) = call.last() {
                    low[p as usize] = low[p as usize].min(low[v as usize]);
                }
                if low[v as usize] == index[v as usize] {
                    let mut size = 0;
                    loop {
                        let w = stack.pop().unwrap();
                        on[w as usize] = false;
                        comp[w as usize] = ncomp;
                        size += 1;
                        if w == v {
                            break;
                        }
                    }
                    sizes.push(size);
                    ncomp += 1;
                }
            }
        }
    }
    let best = sizes
        .iter()
        .enumerate()
        .max_by_key(|x| *x.1)
        .map(|x| x.0 as u32)
        .unwrap_or(0);
    comp.iter().map(|&c| c == best).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn arc(s: u32, d: u32) -> Arc {
        Arc {
            src: s,
            dst: d,
            w: 1,
            t: 1,
            d: 1,
            sd: NONE,
        }
    }

    #[test]
    fn picks_the_biggest_cycle() {
        // 0 <-> 1 <-> 2 is one component, 3 -> 4 are singletons
        let arcs = [arc(0, 1), arc(1, 0), arc(1, 2), arc(2, 1), arc(3, 4)];
        assert_eq!(largest_scc(5, &arcs), vec![true, true, true, false, false]);
    }
}
