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
    },
    /// Print the header and metadata of a graph file.
    Info { file: String },
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
        Command::Build {
            profile,
            zone,
            input,
            out,
            poly,
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
                keep_arcs: false,
                built_at: 0,
            };
            // deep shortcut chains are unpacked recursively
            let child = std::thread::Builder::new()
                .stack_size(512 << 20)
                .spawn(move || build_file(&input, &out, &opt))
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
