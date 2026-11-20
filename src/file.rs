//! The `.mcr` container, see FORMAT.md.

use std::io::Write;

pub const MAGIC: &[u8; 4] = b"MCR\0";
pub const VERSION: u32 = 1;
pub const HEADER: usize = 4096;
pub const SECTION_NAMES: [&str; 9] = [
    "nodes",
    "up_fwd",
    "up_bwd",
    "segments",
    "geometry",
    "grid_dir",
    "grid_lists",
    "shadows",
    "meta",
];
pub const SEG_BYTES: usize = 40;
pub const LICENSE_OFFSET: usize = 512;
pub const LICENSE_LEN: usize = 1024;

pub const LICENSE: &str = "Contains information from OpenStreetMap, made available under the Open Database License (ODbL) 1.0 - https://opendatacommons.org/licenses/odbl/1-0/ - (c) OpenStreetMap contributors. This file is a Derived Database: if you share it, share it under the ODbL and keep this notice. Method of construction: https://github.com/mapcova/routing-builder (profiles adapted from OSRM, BSD-2-Clause).";
pub const ATTRIBUTION: &str = "© OpenStreetMap contributors (ODbL)";

pub struct Header {
    pub profile: u32,
    pub edge_based: bool,
    pub nodes: u32,
    pub up_fwd: u32,
    pub up_bwd: u32,
    pub segments: u32,
    pub cells: u32,
    /// min lat, min lon, max lat, max lon in 1e-6 degree
    pub bbox: [i32; 4],
    pub cell: u32,
    pub osm_timestamp: u32,
    pub built_at: u32,
    pub builder: String,
    pub zone: String,
}

pub fn assemble(h: &Header, sections: &[&Vec<u8>; 9]) -> (Vec<u8>, Vec<(u64, u64)>) {
    let mut head = vec![0u8; HEADER];
    head[0..4].copy_from_slice(MAGIC);
    let put = |hd: &mut Vec<u8>, off: usize, v: u32| hd[off..off + 4].copy_from_slice(&v.to_le_bytes());
    put(&mut head, 4, VERSION);
    put(&mut head, 8, h.profile);
    put(&mut head, 12, h.edge_based as u32);
    put(&mut head, 16, h.nodes);
    put(&mut head, 20, h.up_fwd);
    put(&mut head, 24, h.up_bwd);
    put(&mut head, 28, h.segments);
    put(&mut head, 32, h.cells);
    for (i, v) in h.bbox.iter().enumerate() {
        put(&mut head, 36 + i * 4, *v as u32);
    }
    put(&mut head, 52, h.cell);
    put(&mut head, 56, h.osm_timestamp);
    put(&mut head, 60, h.built_at);
    let b = h.builder.as_bytes();
    head[64..64 + b.len().min(32)].copy_from_slice(&b[..b.len().min(32)]);
    let z = h.zone.as_bytes();
    head[96..96 + z.len().min(31)].copy_from_slice(&z[..z.len().min(31)]);
    let l = LICENSE.as_bytes();
    head[LICENSE_OFFSET..LICENSE_OFFSET + l.len().min(LICENSE_LEN - 1)]
        .copy_from_slice(&l[..l.len().min(LICENSE_LEN - 1)]);
    let mut off = HEADER as u64;
    let mut table = Vec::new();
    for s in sections {
        table.push((off, s.len() as u64));
        off += (s.len() as u64).div_ceil(4096) * 4096;
    }
    for (i, (o, l)) in table.iter().enumerate() {
        head[128 + i * 16..136 + i * 16].copy_from_slice(&o.to_le_bytes());
        head[136 + i * 16..144 + i * 16].copy_from_slice(&l.to_le_bytes());
    }
    let mut out = head;
    for s in sections {
        out.extend_from_slice(s);
        let pad = (4096 - s.len() % 4096) % 4096;
        out.extend(std::iter::repeat_n(0u8, pad));
    }
    (out, table)
}

pub fn write(path: &str, bytes: &[u8]) -> std::io::Result<()> {
    let tmp = format!("{path}.part");
    let mut f = std::io::BufWriter::new(std::fs::File::create(&tmp)?);
    f.write_all(bytes)?;
    f.flush()?;
    drop(f);
    std::fs::rename(tmp, path)
}

/// JSON string literal.
pub fn json_str(s: &str) -> String {
    let mut o = String::from("\"");
    for c in s.chars() {
        match c {
            '"' => o.push_str("\\\""),
            '\\' => o.push_str("\\\\"),
            '\n' => o.push_str("\\n"),
            c if (c as u32) < 0x20 => o.push_str(&format!("\\u{:04x}", c as u32)),
            c => o.push(c),
        }
    }
    o.push('"');
    o
}
