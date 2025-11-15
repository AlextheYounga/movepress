mod all_scope;
mod command;
mod config;
mod db_sync;
mod file_sync;
mod path_resolver;

use crate::all_scope::AllScopeOrchestrator;
use crate::config::Movefile;
use crate::db_sync::{
    DatabaseSyncReport, DatabaseSyncService, StageConfig, StagingMode, WpCliPlan,
};
use crate::file_sync::FileSyncResult;
use crate::path_resolver::{FileScope, FileScopeTargets, RsyncEndpoint};
use clap::{Args, Parser, Subcommand, ValueEnum};
use color_eyre::eyre::{self, Context};
use std::process::ExitCode;
use std::{
    fmt,
    io::{self, IsTerminal, Write},
    path::{Path, PathBuf},
};

#[tokio::main]
async fn main() -> ExitCode {
    if let Err(error) = try_main().await {
        eprintln!("Error: {error}");
        for cause in error.chain().skip(1) {
            eprintln!("  caused by: {cause}");
        }
        return ExitCode::FAILURE;
    }
    ExitCode::SUCCESS
}

async fn try_main() -> color_eyre::Result<()> {
    let cli = Cli::parse();
    run(cli).await
}

async fn run(cli: Cli) -> color_eyre::Result<()> {
    ensure_config_exists(&cli.config)?;
    let movefile = Movefile::from_path(&cli.config)?;
    let plan = OperationPlan::from_command(&cli.command);
    let (source_env, target_env) = movefile.resolve_pair(&plan.source, &plan.target)?;
    let file_targets = plan
        .file_scope()
        .map(|scope| {
            path_resolver::resolve_file_targets(&source_env, &target_env)
                .map(|targets| targets.for_scope(scope))
        })
        .transpose()?;
    let ctx = AppContext {
        config: &cli.config,
        verbose: cli.verbose,
        assume_yes: cli.assume_yes,
        allow_production: production_override_enabled(),
    };

    let file_service = file_sync::FileSyncService::new(cli.verbose);
    let db_service = DatabaseSyncService::new(cli.verbose);

    if plan.scope == SyncScope::All {
        let file_targets = file_targets
            .as_ref()
            .cloned()
            .ok_or_else(|| eyre::eyre!("Failed to resolve wp-content targets for all scope"))?;
        let mut orchestrator = AllScopeOrchestrator::new(
            &plan,
            &source_env,
            &target_env,
            file_targets,
            &db_service,
            &file_service,
        );

        if cli.dry_run {
            plan.print_dry_run(&ctx);
            println!();
        } else {
            if plan.requires_confirmation() {
                enforce_confirmation(&plan, &ctx)?;
            }
            println!();
        }

        let db_report = orchestrator.run_database_stage(cli.dry_run).await?;
        print_database_report(&db_report);
        println!();
        let file_report = orchestrator.run_file_stage(cli.dry_run).await?;
        print_file_sync_report(
            &plan,
            orchestrator.file_targets(),
            &file_report,
            cli.verbose,
        );
        return Ok(());
    }

    if cli.dry_run {
        plan.print_dry_run(&ctx);
        if plan.scope.includes_db() {
            println!();
            let db_plan = db_service.build_plan(&plan, &source_env, &target_env)?;
            let report = db_service.sync(db_plan, true).await?;
            print_database_report(&report);
        }
        if let Some(targets) = file_targets.as_ref() {
            println!();
            let report = file_service.sync(&plan, targets, true).await?;
            print_file_sync_report(&plan, targets, &report, cli.verbose);
        } else if plan.scope.includes_files() {
            println!();
            print_file_scope_placeholder(&plan);
        }
        return Ok(());
    }

    if plan.requires_confirmation() {
        enforce_confirmation(&plan, &ctx)?;
    }
    let mut executed = false;

    if plan.scope.includes_db() {
        println!();
        let db_plan = db_service.build_plan(&plan, &source_env, &target_env)?;
        let report = db_service.sync(db_plan, false).await?;
        print_database_report(&report);
        executed = true;
    }

    if let Some(targets) = file_targets {
        if executed {
            println!();
        }
        let report = file_service.sync(&plan, &targets, false).await?;
        print_file_sync_report(&plan, &targets, &report, cli.verbose);
        executed = true;
    } else if plan.scope.includes_files() {
        println!();
        print_file_scope_placeholder(&plan);
    }

    if !executed {
        plan.print_execution_placeholder(&ctx);
    }

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
pub(crate) enum SyncScope {
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
pub(crate) enum Direction {
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
pub(crate) struct OperationPlan {
    pub(crate) direction: Direction,
    pub(crate) scope: SyncScope,
    pub(crate) source: String,
    pub(crate) target: String,
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
        self.scope.includes_db() || self.target_is_production()
    }

    fn file_scope(&self) -> Option<FileScope> {
        match self.scope {
            SyncScope::Uploads => Some(FileScope::Uploads),
            SyncScope::Content | SyncScope::All => Some(FileScope::Content),
            _ => None,
        }
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
        println!("Use --dry-run to preview operations without side effects.");
    }
}

impl OperationPlan {
    #[cfg(test)]
    pub(crate) fn for_test(
        direction: Direction,
        scope: SyncScope,
        source: &str,
        target: &str,
    ) -> Self {
        Self {
            direction,
            scope,
            source: source.to_string(),
            target: target.to_string(),
        }
    }
}

struct AppContext<'a> {
    config: &'a Path,
    verbose: bool,
    assume_yes: bool,
    allow_production: bool,
}

fn enforce_confirmation(plan: &OperationPlan, ctx: &AppContext<'_>) -> color_eyre::Result<()> {
    let target_is_production = plan.target_is_production();
    if ctx.assume_yes {
        if target_is_production && !ctx.allow_production {
            eyre::bail!(
                "Refusing to run {} with --yes because '{}' looks like production.\n\
Re-run interactively after a --dry-run preview or set MOVEPRESS_ALLOW_PROD=1 to enable non-interactive approvals.",
                plan.description(),
                plan.target
            );
        }
        if target_is_production {
            println!(
                "Auto-confirmed production operation for '{}' via --yes and MOVEPRESS_ALLOW_PROD=1.",
                plan.target
            );
        } else {
            println!(
                "Auto-confirmed database operation for non-production target '{}'.",
                plan.target
            );
        }
        return Ok(());
    }

    let stdin = io::stdin();
    if !stdin.is_terminal() {
        if target_is_production {
            eyre::bail!(
                "Refusing to run {} without interactive confirmation because '{}' looks like production.\n\
Launch movepress from a terminal after --dry-run, or pass --yes with MOVEPRESS_ALLOW_PROD=1 for CI.",
                plan.description(),
                plan.target
            );
        } else {
            eyre::bail!(
                "Refusing to run {} without confirmation. Re-run interactively or pass --yes for non-production targets.",
                plan.description()
            );
        }
    }
    let mut stdout = io::stdout();
    let prompt = if plan.scope.includes_db() {
        format!(
            "About to {} which will overwrite the '{}' database. Continue? [y/N]: ",
            plan.description(),
            plan.target
        )
    } else {
        format!(
            "About to {} which will modify the production environment '{}'. Continue? [y/N]: ",
            plan.description(),
            plan.target
        )
    };
    write!(stdout, "{prompt}")?;
    stdout
        .flush()
        .wrap_err("failed to flush confirmation prompt")?;

    let mut input = String::new();
    stdin
        .read_line(&mut input)
        .wrap_err("failed to read confirmation input")?;
    let normalized = input.trim().to_ascii_lowercase();
    if normalized == "y" || normalized == "yes" {
        if target_is_production {
            println!(
                "Confirmed production operation for '{}'. Proceed with caution.",
                plan.target
            );
        } else {
            println!(
                "Confirmed destructive database operation for '{}'.",
                plan.target
            );
        }
        Ok(())
    } else {
        eyre::bail!("Aborted per user response.");
    }
}

fn print_file_sync_report(
    plan: &OperationPlan,
    targets: &FileScopeTargets,
    report: &FileSyncResult,
    verbose: bool,
) {
    let heading = if report.dry_run {
        "File Sync Preview"
    } else {
        "File Sync Result"
    };
    println!("{heading}");
    println!("{}", "=".repeat(heading.len()));
    println!("Scope  : {}", targets.scope().label());
    println!(
        "Mode   : {}",
        if report.dry_run { "dry-run" } else { "active" }
    );
    if report.dry_run && !report.executed {
        println!("- Preview skipped executing rsync to honor dry-run safety guards.");
    }
    println!(
        "Source : {} ({})",
        plan.source,
        describe_endpoint(&targets.source)
    );
    println!(
        "Target : {} ({})",
        plan.target,
        describe_endpoint(&targets.target)
    );
    println!("Command: {}", report.command);
    if !report.excludes.is_empty() {
        println!("Excludes: {}", report.excludes.join(", "));
    }
    if let Some(stats) = &report.stats {
        println!("{}", stats.describe(report.dry_run));
        println!("{}", stats.totals_line());
    } else if report.executed {
        println!("rsync completed without emitting statistics.");
    }

    if verbose || report.dry_run {
        let trimmed = report.stdout.trim();
        if !trimmed.is_empty() {
            println!();
            println!("rsync output:");
            println!("{trimmed}");
        }
    }

    let stderr = report.stderr.trim();
    if !stderr.is_empty() {
        eprintln!("rsync stderr:\n{stderr}");
    }
}

fn print_database_report(report: &DatabaseSyncReport) {
    let heading = if report.dry_run {
        "Database Sync Preview"
    } else {
        "Database Sync Result"
    };
    println!("{heading}");
    println!("{}", "=".repeat(heading.len()));
    println!(
        "Mode       : {}",
        if report.dry_run { "dry-run" } else { "active" }
    );
    let plan = &report.plan;
    println!(
        "Source     : {} ({}:{} / {})",
        plan.source.name, plan.source.db_host, plan.source.db_port, plan.source.db_name
    );
    println!(
        "Target     : {} ({}:{} / {})",
        plan.target.name, plan.target.db_host, plan.target.db_port, plan.target.db_name
    );
    println!("Transport  : {}", plan.transport.label());
    println!(
        "Compression: {}",
        if plan.compression.is_enabled() {
            "enabled (gzip)"
        } else {
            "disabled"
        }
    );
    println!("Pipeline");
    println!("--------");
    println!("Dump   : {}", describe_db_stage(&plan.pipeline.dump));
    for (idx, filter) in plan.pipeline.filters.iter().enumerate() {
        println!("Filter {:>2}: {}", idx + 1, describe_db_stage(filter));
    }
    println!("Import : {}", describe_db_stage(&plan.pipeline.import));

    println!("Staging");
    println!("-------");
    match &plan.staging {
        StagingMode::Streaming => {
            println!("- Streaming pipeline between source and destination; no temp files created.");
        }
        StagingMode::TempFile { reason } => {
            println!("- {reason}");
            if report.dry_run {
                println!("- Temp file allocated under the OS temp directory at runtime.");
            } else if let Some(path) = &report.staging_path {
                println!("Path      : {}", path.display());
            } else {
                println!("- Temp file created for this run.");
            }
        }
    }

    println!("WP-CLI");
    println!("------");
    match (&plan.wp_cli, &plan.wp_cli_reason) {
        (Some(step), _) => {
            println!("search-replace {} -> {}", step.source_url, step.target_url);
            println!("Command   : {}", describe_wp_command(step));
            if report.dry_run {
                println!("- Would run WP-CLI search-replace after import.");
            } else if report.wp_cli_executed {
                println!("- WP-CLI search-replace executed successfully.");
            }
        }
        (_, Some(reason)) => println!("- {reason}"),
        (None, None) => println!("- WP-CLI is not configured for this target."),
    }
}

fn describe_db_stage(stage: &StageConfig) -> String {
    sanitize_command(stage.spec.clone().describe(&stage.location.context_label()))
}

fn describe_wp_command(step: &WpCliPlan) -> String {
    sanitize_command(step.spec.clone().describe(&step.location.context_label()))
}

fn sanitize_command(command: String) -> String {
    command
        .replace("'\\''", "\"")
        .replace('\'', "\"")
        .replace("\"\"", "\"")
}

fn print_file_scope_placeholder(plan: &OperationPlan) {
    println!("File Sync Pending");
    println!("-----------------");
    println!(
        "- File synchronization for '{}' is not wired yet. Upcoming tickets will add rsync support for this scope.",
        plan.scope.as_label()
    );
}

fn describe_endpoint(endpoint: &RsyncEndpoint) -> String {
    if let Some(local) = endpoint.local_path() {
        return redact_local_path(local);
    }
    if let Some((user, host, port, path)) = endpoint.remote_details() {
        if port == 22 {
            return format!("{user}@{host}:{path}");
        }
        return format!("{user}@{host}:{path} (port {port})");
    }
    endpoint.rsync_path()
}

fn redact_local_path(path: &Path) -> String {
    let display = path.display().to_string();
    if let Some(home) = home_dir() {
        if let Ok(stripped) = path.strip_prefix(&home) {
            let mut redacted = PathBuf::from("~");
            redacted.push(stripped);
            return redacted.display().to_string();
        }
    }
    display
}

fn home_dir() -> Option<PathBuf> {
    if let Ok(home) = std::env::var("HOME") {
        return Some(PathBuf::from(home));
    }
    if let Ok(profile) = std::env::var("USERPROFILE") {
        return Some(PathBuf::from(profile));
    }
    None
}

fn production_override_enabled() -> bool {
    std::env::var("MOVEPRESS_ALLOW_PROD")
        .map(|value| match value.trim().to_ascii_lowercase().as_str() {
            "1" | "true" | "yes" | "allow" => true,
            _ => false,
        })
        .unwrap_or(false)
}
