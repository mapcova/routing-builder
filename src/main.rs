use clap::{Parser, Subcommand};
use mcr::profile::Profile;
use mcr::{BuildOptions, build_file, parse_poly, reader::Mcr};
use std::process::ExitCode;

#[derive(Parser)]
#[command(
    name = "mcr",
    version,
    about = "Builds MapCova routing graphs (.mcr) from OpenStreetMap extracts"
)]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Build a graph file for one zone and one profile.
    Build {
        /// car, car-notoll, bike or foot
        #[arg(long)]
        profile: String,
        /// Zone identifier written in the header (for example lu or ch-li)
        #[arg(long)]
        zone: String,
        /// OSM PBF extract
        #[arg(long)]
        input: String,
        /// Output file (.mcr)
        #[arg(long)]
        out: String,
        /// Osmosis .poly file of the zone, stored (simplified) in the header; default: the extract's bounding box
        #[arg(long)]
        poly: Option<String>,
        /// Also write the arcs of the graph before contraction (sorted `src dst weight duration distance` lines),
        /// to compare with the PHP builder
        #[arg(long)]
        dump_arcs: Option<String>,
    },
    /// Print the header and metadata of a graph file.
    Info { file: String },
    /// Write route checks for a graph file: points with the duration the reference reader finds between them.
    Witnesses {
        file: String,
        /// Number of routes (the server wants at least 20)
        #[arg(long, default_value_t = 20)]
        count: usize,
        /// Seed of the choice: the same file and seed give the same routes
        #[arg(long, default_value_t = 1)]
        seed: u64,
        /// Output JSON file (default: standard output)
        #[arg(long)]
        out: Option<String>,
    },
}

fn main() -> ExitCode {
    let cli = Cli::parse();
    match cli.command {
        Command::Info { file } => match Mcr::open(&file) {
            Ok(m) => {
                print!("{}", m.info());
                ExitCode::SUCCESS
            }
            Err(e) => fail(&e),
        },
        Command::Witnesses { file, count, seed, out } => {
            let m = match Mcr::open(&file) {
                Ok(m) => m,
                Err(e) => return fail(&e),
            };
            let w = mcr::witness::witnesses(&m, count, seed);
            if w.len() < count {
                return fail(&format!(
                    "only {} of {count} route checks found: the graph is too small or too fragmented",
                    w.len()
                ));
            }
            let json = mcr::witness::to_json(&w);
            match out {
                Some(path) => match std::fs::write(&path, json) {
                    Ok(()) => ExitCode::SUCCESS,
                    Err(e) => fail(&format!("cannot write {path}: {e}")),
                },
                None => {
                    print!("{json}");
                    ExitCode::SUCCESS
                }
            }
        }
        Command::Build {
            profile,
            zone,
            input,
            out,
            poly,
            dump_arcs,
        } => {
            let Some(profile) = Profile::parse(&profile) else {
                return fail("profile must be car, car-notoll, bike or foot");
            };
            let polygon = match poly.map(|p| std::fs::read_to_string(&p).map_err(|e| format!("cannot read {p}: {e}"))) {
                Some(Ok(t)) => parse_poly(&t),
                Some(Err(e)) => return fail(&e),
                None => Vec::new(),
            };
            let opt = BuildOptions {
                zone,
                profile,
                polygon,
                keep_arcs: dump_arcs.is_some(),
                built_at: 0,
            };
            // deep shortcut chains are unpacked recursively
            let child = std::thread::Builder::new()
                .stack_size(512 << 20)
                .spawn(move || build_file(&input, &out, &opt, dump_arcs.as_deref()))
                .expect("thread");
            match child.join() {
                Ok(Ok(())) => ExitCode::SUCCESS,
                Ok(Err(e)) => fail(&e),
                Err(_) => fail("build panicked"),
            }
        }
    }
}

fn fail(msg: &str) -> ExitCode {
    eprintln!("mcr: {msg}");
    ExitCode::from(1)
}
