use std::fmt;
use std::io::{self, IsTerminal, Write};
use std::path::{Path, PathBuf};

mod command;
mod config;

use crate::config::Movefile;
use clap::{Args, Parser, Subcommand, ValueEnum};
use color_eyre::eyre::{self, Context};
use std::process::ExitCode;

fn main() -> ExitCode {
    if let Err(error) = try_main() {
        eprintln!("Error: {error}");
        for cause in error.chain().skip(1) {
            eprintln!("  caused by: {cause}");
        }
        return ExitCode::FAILURE;
    }
    ExitCode::SUCCESS
}

fn try_main() -> color_eyre::Result<()> {
    let cli = Cli::parse();
    run(cli)
}

fn run(cli: Cli) -> color_eyre::Result<()> {
    ensure_config_exists(&cli.config)?;
    let movefile = Movefile::from_path(&cli.config)?;
    let plan = OperationPlan::from_command(&cli.command);
    let (_source_env, _target_env) = movefile.resolve_pair(&plan.source, &plan.target)?;
    let ctx = AppContext {
        config: &cli.config,
        verbose: cli.verbose,
        assume_yes: cli.assume_yes,
    };

    if cli.dry_run {
        plan.print_dry_run(&ctx);
        return Ok(());
    }

    if plan.requires_confirmation() {
        enforce_confirmation(&plan, &ctx)?;
    }

    plan.print_execution_placeholder(&ctx);
    Ok(())
}

fn ensure_config_exists(path: &Path) -> color_eyre::Result<()> {
    if !path.exists() {
        eyre::bail!(
            "Movefile not found at '{}'. Provide a valid path with --config.",
            path.display()
        );
    }
    if !path.is_file() {
        eyre::bail!(
            "Expected --config to point at a file, but '{}' is not a file.",
            path.display()
        );
    }
    Ok(())
}

#[derive(Parser, Debug)]
#[command(
    name = "movepress",
    version,
    about = "Synchronize WordPress environments with a modern Wordmove-inspired CLI."
)]
struct Cli {
    /// Path to Movefile.toml configuration
    #[arg(
        long,
        value_name = "PATH",
        global = true,
        default_value = "Movefile.toml"
    )]
    config: PathBuf,

    /// Enable verbose logging
    #[arg(short, long, global = true)]
    verbose: bool,

    /// Preview the operation without applying changes
    #[arg(long, global = true)]
    dry_run: bool,

    /// Automatically confirm destructive operations for non-production targets
    #[arg(short = 'y', long = "yes", global = true)]
    assume_yes: bool,

    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand, Debug)]
enum Command {
    /// Push changes from the source environment into the destination
    Push(OperationArgs),
    /// Pull changes from the source environment into the destination
    Pull(OperationArgs),
}

#[derive(Args, Debug, Clone)]
struct OperationArgs {
    /// Scope of the sync operation
    #[arg(value_enum)]
    what: SyncScope,

    /// Source environment nickname (as defined in Movefile.toml)
    src: String,

    /// Destination environment nickname (as defined in Movefile.toml)
    dst: String,
}

#[derive(Debug, Clone, Copy, ValueEnum, Eq, PartialEq)]
enum SyncScope {
    Db,
    Uploads,
    Content,
    All,
}

impl SyncScope {
    fn includes_db(&self) -> bool {
        matches!(self, Self::Db | Self::All)
    }

    fn includes_files(&self) -> bool {
        matches!(self, Self::Uploads | Self::Content | Self::All)
    }

    fn as_label(&self) -> &'static str {
        match self {
            Self::Db => "database",
            Self::Uploads => "uploads",
            Self::Content => "wp-content",
            Self::All => "entire environment",
        }
    }
}

impl fmt::Display for SyncScope {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.as_label())
    }
}

#[derive(Debug, Clone, Copy)]
enum Direction {
    Push,
    Pull,
}

impl fmt::Display for Direction {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Direction::Push => write!(f, "push"),
            Direction::Pull => write!(f, "pull"),
        }
    }
}

#[derive(Debug)]
struct OperationPlan {
    direction: Direction,
    scope: SyncScope,
    source: String,
    target: String,
}

impl OperationPlan {
    fn from_command(command: &Command) -> Self {
        match command {
            Command::Push(args) => OperationPlan {
                direction: Direction::Push,
                scope: args.what,
                source: args.src.clone(),
                target: args.dst.clone(),
            },
            Command::Pull(args) => OperationPlan {
                direction: Direction::Pull,
                scope: args.what,
                source: args.src.clone(),
                target: args.dst.clone(),
            },
        }
    }

    fn requires_confirmation(&self) -> bool {
        self.scope.includes_db()
    }

    fn target_is_production(&self) -> bool {
        let lower = self.target.trim().to_ascii_lowercase();
        lower == "production" || lower == "prod"
    }

    fn description(&self) -> String {
        format!(
            "{} {} from '{}' to '{}'",
            self.direction,
            self.scope.as_label(),
            self.source,
            self.target
        )
    }

    fn print_dry_run(&self, ctx: &AppContext<'_>) {
        println!("Dry Run Summary");
        println!("===============");
        println!("Config   : {}", ctx.config.display());
        println!("Operation: {}", self.description());
        println!("Scope    : {}", self.scope.as_label());
        println!("Source   : {}", self.source);
        println!("Target   : {}", self.target);
        if ctx.verbose {
            println!("Verbose  : enabled");
        }
        if self.scope.includes_files() {
            println!();
            println!("File Sync Plan");
            println!("--------------");
            println!(
                "- Would build local and remote manifests for '{}'.",
                self.scope.as_label()
            );
            println!("- Would compute file deltas to transfer efficiently.");
            println!("- Would honor excludes from Movefile.toml.");
        } else {
            println!();
            println!("File Sync Plan");
            println!("--------------");
            println!("- Not included for the selected scope.");
        }

        if self.scope.includes_db() {
            println!();
            println!("Database Plan");
            println!("-------------");
            println!("- Would dump the source database from '{}'.", self.source);
            println!(
                "- Would import into '{}' after applying required confirmations.",
                self.target
            );
            println!("- Would optionally run WP-CLI search-replace if configured.");
        } else {
            println!();
            println!("Database Plan");
            println!("-------------");
            println!("- Not included for the selected scope.");
        }
    }

    fn print_execution_placeholder(&self, ctx: &AppContext<'_>) {
        println!("Executing {}", self.description());
        if ctx.verbose {
            println!(
                "Verbose logging is enabled; future stages will emit detailed command traces."
            );
        }
        if self.scope.includes_files() {
            println!(
                "- File sync engines are not wired yet; see upcoming tickets for implementation."
            );
        }
        if self.scope.includes_db() {
            println!(
                "- Database sync pipeline is pending future tickets; confirmation handled above."
            );
        }
        println!("Use --dry-run to preview operations without side effects.");
    }
}

struct AppContext<'a> {
    config: &'a Path,
    verbose: bool,
    assume_yes: bool,
}

fn enforce_confirmation(plan: &OperationPlan, ctx: &AppContext<'_>) -> color_eyre::Result<()> {
    if ctx.assume_yes {
        if plan.target_is_production() {
            eyre::bail!(
                "Destination '{}' is marked as production. Interactive confirmation is required.",
                plan.target
            );
        }
        println!(
            "Auto-confirmed database operation for non-production target '{}'.",
            plan.target
        );
        return Ok(());
    }

    let stdin = io::stdin();
    if !stdin.is_terminal() {
        eyre::bail!(
            "Refusing to run {} without confirmation. Re-run interactively or pass --yes for non-production targets.",
            plan.description()
        );
    }
    let mut stdout = io::stdout();
    write!(
        stdout,
        "About to {} which will overwrite the '{}' database. Continue? [y/N]: ",
        plan.description(),
        plan.target
    )?;
    stdout
        .flush()
        .wrap_err("failed to flush confirmation prompt")?;

    let mut input = String::new();
    stdin
        .read_line(&mut input)
        .wrap_err("failed to read confirmation input")?;
    let normalized = input.trim().to_ascii_lowercase();
    if normalized == "y" || normalized == "yes" {
        println!(
            "Confirmed destructive database operation for '{}'.",
            plan.target
        );
        Ok(())
    } else {
        eyre::bail!("Aborted per user response.");
    }
}
