use crate::OperationPlan;
#[cfg(test)]
use crate::SyncScope;
use crate::command::{
    CommandChild, CommandExecutor, CommandSpec, LocalCommandExecutor, OutputConfig,
    SshCommandExecutor, SshOptions,
};
use crate::config::{DatabaseConfig, EnvironmentKind, ResolvedEnvironment};
use color_eyre::eyre::{self, Context, Result};
use std::io;
use std::path::{Path, PathBuf};
use tempfile::{Builder, NamedTempFile};
use tokio::fs::File as TokioFile;
use tokio::io::{AsyncRead, AsyncWrite, AsyncWriteExt};
use tokio::process::ChildStdout;
use tokio::task::JoinHandle;

pub struct DatabaseSyncService {
    local: LocalCommandExecutor,
    ssh: SshCommandExecutor,
}

impl DatabaseSyncService {
    pub fn new(verbose: bool) -> Self {
        Self {
            local: LocalCommandExecutor::new(verbose),
            ssh: SshCommandExecutor::new(verbose),
        }
    }

    pub fn build_plan(
        &self,
        _plan: &OperationPlan,
        source: &ResolvedEnvironment,
        target: &ResolvedEnvironment,
    ) -> Result<DatabaseSyncPlan> {
        let source_summary = EndpointSummary::from_env(source);
        let target_summary = EndpointSummary::from_env(target);
        let transport = TransportKind::from_endpoints(&source_summary, &target_summary);
        let compression = CompressionMode::from_transport(&transport);
        let staging = determine_staging_mode(&transport);

        let source_stage = build_dump_stage(
            source,
            compression.is_enabled() && source_summary.is_remote(),
        )?;
        let mut filters = Vec::new();
        if compression.is_enabled() && source_summary.is_local() {
            filters.push(compressor_stage());
        }
        if compression.is_enabled() && target_summary.is_local() {
            filters.push(decompressor_stage());
        }
        let import_stage = build_import_stage(
            target,
            compression.is_enabled() && target_summary.is_remote(),
        )?;

        let (wp_cli, wp_cli_reason) = build_wp_cli_plan(source, target, &target_summary);

        Ok(DatabaseSyncPlan {
            source: source_summary,
            target: target_summary,
            transport,
            compression,
            staging,
            pipeline: PipelineStages {
                dump: source_stage,
                filters,
                import: import_stage,
            },
            wp_cli,
            wp_cli_reason,
        })
    }

    pub async fn sync(&self, plan: DatabaseSyncPlan, dry_run: bool) -> Result<DatabaseSyncReport> {
        if dry_run {
            return Ok(DatabaseSyncReport {
                dry_run: true,
                plan,
                wp_cli_executed: false,
                staging_path: None,
            });
        }

        let staging_path = if plan.staging.requires_tempfile() {
            Some(self.run_pipeline_with_tempfile(&plan).await?)
        } else {
            self.run_pipeline(&plan.pipeline).await?;
            None
        };

        let mut wp_cli_executed = false;
        if let Some(step) = plan.wp_cli.as_ref() {
            self.run_wp_cli(step).await?;
            wp_cli_executed = true;
        }

        Ok(DatabaseSyncReport {
            dry_run: false,
            plan,
            wp_cli_executed,
            staging_path,
        })
    }

    async fn run_pipeline(&self, pipeline: &PipelineStages) -> Result<()> {
        let mut state = self.start_pipeline(pipeline).await?;
        let mut import_child = self.spawn_stage(&pipeline.import).await?;
        let import_stdin = import_child
            .take_stdin()
            .ok_or_else(|| eyre::eyre!("mysql stage missing stdin"))?;
        let reader = state.take_reader()?;
        state
            .pipes
            .push(tokio::spawn(pipe_stream(reader, import_stdin)));
        state.running.push(RunningStage {
            stage: &pipeline.import,
            child: import_child,
        });

        self.finalize_pipeline(state).await
    }

    async fn run_wp_cli(&self, plan: &WpCliPlan) -> Result<()> {
        let exec_result = match plan.location {
            StageLocation::Local => {
                self.local
                    .exec(plan.spec.clone().stdout(OutputConfig::Inherit))
                    .await
            }
            StageLocation::Remote(_) => {
                self.ssh
                    .exec(plan.spec.clone().stdout(OutputConfig::Inherit))
                    .await
            }
        }
        .map_err(|err| map_spawn_error(err, &plan.program_hint))?;

        exec_result
            .ensure_success()
            .wrap_err("WP-CLI search-replace failed")?;
        Ok(())
    }

    async fn spawn_stage(&self, stage: &StageConfig) -> Result<CommandChild> {
        let spawn_result = match stage.location {
            StageLocation::Local => self.local.spawn(stage.spec.clone()).await,
            StageLocation::Remote(_) => self.ssh.spawn(stage.spec.clone()).await,
        };
        spawn_result.map_err(|err| map_spawn_error(err, stage.program_hint))
    }

    async fn start_pipeline<'a>(
        &'a self,
        pipeline: &'a PipelineStages,
    ) -> Result<PipelineState<'a>> {
        let mut running = Vec::new();
        let mut pipes = Vec::new();

        let mut source_child = self.spawn_stage(&pipeline.dump).await?;
        let source_stdout = source_child
            .take_stdout()
            .ok_or_else(|| eyre::eyre!("mysqldump stage did not expose stdout"))?;
        running.push(RunningStage {
            stage: &pipeline.dump,
            child: source_child,
        });

        let mut upstream = StageOutput::Stdout(source_stdout);

        for filter in &pipeline.filters {
            let mut child = self.spawn_stage(filter).await?;
            let stdin = child
                .take_stdin()
                .ok_or_else(|| eyre::eyre!("{label} stage missing stdin", label = filter.label))?;
            let stdout = child
                .take_stdout()
                .ok_or_else(|| eyre::eyre!("{label} stage missing stdout", label = filter.label))?;
            pipes.push(tokio::spawn(pipe_stream(upstream.take_reader()?, stdin)));
            upstream = StageOutput::Stdout(stdout);
            running.push(RunningStage {
                stage: filter,
                child,
            });
        }

        Ok(PipelineState {
            running,
            pipes,
            output: Some(upstream),
        })
    }

    async fn finalize_pipeline(&self, state: PipelineState<'_>) -> Result<()> {
        for pipe in state.pipes {
            pipe.await
                .map_err(|err| eyre::eyre!("pipeline task failed: {err}"))??;
        }

        for running_stage in state.running {
            running_stage.wait().await?;
        }
        Ok(())
    }

    async fn run_pipeline_with_tempfile(&self, plan: &DatabaseSyncPlan) -> Result<PathBuf> {
        let compressed = plan.target.is_remote();
        let temp_file = TempDumpFile::create(compressed)?;
        self.capture_dump_to_file(&plan.pipeline, &temp_file)
            .await?;
        let staged_path = temp_file.path().to_path_buf();
        self.import_from_tempfile(&plan.pipeline, &temp_file)
            .await?;
        Ok(staged_path)
    }

    async fn capture_dump_to_file(
        &self,
        pipeline: &PipelineStages,
        temp_file: &TempDumpFile,
    ) -> Result<()> {
        let mut state = self.start_pipeline(pipeline).await?;
        {
            let mut reader = state.take_reader()?;
            let mut writer = temp_file.writer()?;
            tokio::io::copy(&mut reader, &mut writer)
                .await
                .wrap_err("failed to write database dump to temp file")?;
            writer
                .shutdown()
                .await
                .wrap_err("failed to flush staged dump to disk")?;
        }
        self.finalize_pipeline(state).await
    }

    async fn import_from_tempfile(
        &self,
        pipeline: &PipelineStages,
        temp_file: &TempDumpFile,
    ) -> Result<()> {
        let mut import_child = self.spawn_stage(&pipeline.import).await?;
        let mut stdin = import_child
            .take_stdin()
            .ok_or_else(|| eyre::eyre!("mysql stage missing stdin"))?;
        let mut reader = temp_file.reader()?;
        tokio::io::copy(&mut reader, &mut stdin)
            .await
            .wrap_err("failed to feed staged dump into mysql")?;
        stdin
            .shutdown()
            .await
            .wrap_err("failed to flush mysql stdin")?;
        RunningStage {
            stage: &pipeline.import,
            child: import_child,
        }
        .wait()
        .await
    }
}

pub struct DatabaseSyncPlan {
    pub source: EndpointSummary,
    pub target: EndpointSummary,
    pub transport: TransportKind,
    pub compression: CompressionMode,
    pub staging: StagingMode,
    pub pipeline: PipelineStages,
    pub wp_cli: Option<WpCliPlan>,
    pub wp_cli_reason: Option<String>,
}

pub struct DatabaseSyncReport {
    pub dry_run: bool,
    pub plan: DatabaseSyncPlan,
    pub wp_cli_executed: bool,
    pub staging_path: Option<PathBuf>,
}

pub struct PipelineStages {
    pub dump: StageConfig,
    pub filters: Vec<StageConfig>,
    pub import: StageConfig,
}

#[derive(Clone)]
pub struct StageConfig {
    pub label: &'static str,
    pub spec: CommandSpec,
    pub location: StageLocation,
    pub program_hint: &'static str,
}

#[derive(Clone)]
pub enum StageLocation {
    Local,
    Remote(RemoteDetails),
}

#[derive(Clone)]
pub struct RemoteDetails {
    pub user: String,
    pub host: String,
    pub port: u16,
}

impl RemoteDetails {
    fn to_options(&self) -> SshOptions {
        SshOptions::new(self.user.clone(), self.host.clone()).with_port(self.port)
    }
}

impl StageLocation {
    pub fn context_label(&self) -> String {
        match self {
            StageLocation::Local => "local".to_string(),
            StageLocation::Remote(details) => {
                format!("ssh {}@{}:{}", details.user, details.host, details.port)
            }
        }
    }

    pub fn is_remote(&self) -> bool {
        matches!(self, StageLocation::Remote(_))
    }
}

pub struct EndpointSummary {
    pub name: String,
    pub location: StageLocation,
    pub db_host: String,
    pub db_port: u16,
    pub db_name: String,
}

impl EndpointSummary {
    fn from_env(env: &ResolvedEnvironment) -> Self {
        let location = match &env.kind {
            EnvironmentKind::Local { .. } => StageLocation::Local,
            EnvironmentKind::Ssh {
                host, user, port, ..
            } => StageLocation::Remote(RemoteDetails {
                host: host.clone(),
                user: user.clone(),
                port: *port,
            }),
        };

        Self {
            name: env.name.clone(),
            location,
            db_host: env.db.host.clone(),
            db_port: env.db.port,
            db_name: env.db.name.clone(),
        }
    }

    fn is_remote(&self) -> bool {
        self.location.is_remote()
    }

    fn is_local(&self) -> bool {
        !self.is_remote()
    }
}

pub enum TransportKind {
    LocalToLocal,
    LocalToRemote,
    RemoteToLocal,
    RemoteToRemote,
}

impl TransportKind {
    fn from_endpoints(source: &EndpointSummary, target: &EndpointSummary) -> Self {
        match (source.is_remote(), target.is_remote()) {
            (false, false) => Self::LocalToLocal,
            (false, true) => Self::LocalToRemote,
            (true, false) => Self::RemoteToLocal,
            (true, true) => Self::RemoteToRemote,
        }
    }

    pub fn label(&self) -> &'static str {
        match self {
            TransportKind::LocalToLocal => "local → local",
            TransportKind::LocalToRemote => "local → remote (ssh)",
            TransportKind::RemoteToLocal => "remote (ssh) → local",
            TransportKind::RemoteToRemote => "remote (ssh) → remote (ssh)",
        }
    }
}

#[derive(Clone, Copy)]
pub enum CompressionMode {
    Disabled,
    Enabled,
}

impl CompressionMode {
    fn from_transport(transport: &TransportKind) -> Self {
        match transport {
            TransportKind::LocalToLocal => CompressionMode::Disabled,
            _ => CompressionMode::Enabled,
        }
    }

    pub fn is_enabled(&self) -> bool {
        matches!(self, CompressionMode::Enabled)
    }
}

pub enum StagingMode {
    Streaming,
    TempFile { reason: String },
}

impl StagingMode {
    pub fn requires_tempfile(&self) -> bool {
        matches!(self, StagingMode::TempFile { .. })
    }
}

fn determine_staging_mode(transport: &TransportKind) -> StagingMode {
    if matches!(transport, TransportKind::RemoteToRemote) {
        return StagingMode::TempFile {
            reason: "Remote-to-remote transfers stage dumps on the operator host to bridge SSH sessions."
                .to_string(),
        };
    }
    StagingMode::Streaming
}

pub struct WpCliPlan {
    pub spec: CommandSpec,
    pub location: StageLocation,
    pub program_hint: &'static str,
    pub source_url: String,
    pub target_url: String,
}

struct RunningStage<'a> {
    stage: &'a StageConfig,
    child: CommandChild,
}

impl RunningStage<'_> {
    async fn wait(self) -> Result<()> {
        let status = self
            .child
            .wait()
            .await
            .wrap_err_with(|| format!("failed to wait for {} stage", self.stage.label))?;
        if status.success() {
            return Ok(());
        }
        let code = status
            .code()
            .map(|value| value.to_string())
            .unwrap_or_else(|| "unknown".to_string());
        let command = self
            .stage
            .spec
            .clone()
            .describe(&self.stage.location.context_label());
        eyre::bail!(
            "Stage '{}' failed with exit code {code}: {command}",
            self.stage.label
        );
    }
}

enum StageOutput {
    Stdout(ChildStdout),
}

impl StageOutput {
    fn take_reader(self) -> Result<ChildStdout> {
        match self {
            StageOutput::Stdout(handle) => Ok(handle),
        }
    }
}

struct PipelineState<'a> {
    running: Vec<RunningStage<'a>>,
    pipes: Vec<JoinHandle<Result<()>>>,
    output: Option<StageOutput>,
}

impl<'a> PipelineState<'a> {
    fn take_reader(&mut self) -> Result<ChildStdout> {
        self.output
            .take()
            .ok_or_else(|| eyre::eyre!("pipeline output is no longer available"))?
            .take_reader()
    }
}

struct TempDumpFile {
    handle: NamedTempFile,
}

impl TempDumpFile {
    fn create(compressed: bool) -> Result<Self> {
        let suffix = if compressed { ".sql.gz" } else { ".sql" };
        let handle = Builder::new()
            .prefix("movepress-db-dump-")
            .suffix(suffix)
            .tempfile()
            .wrap_err("failed to create temporary file for database staging")?;
        Ok(Self { handle })
    }

    fn path(&self) -> &Path {
        self.handle.path()
    }

    fn writer(&self) -> Result<TokioFile> {
        let file = self
            .handle
            .reopen()
            .wrap_err("failed to open staging file for writing")?;
        Ok(TokioFile::from_std(file))
    }

    fn reader(&self) -> Result<TokioFile> {
        let file = self
            .handle
            .reopen()
            .wrap_err("failed to open staging file for reading")?;
        Ok(TokioFile::from_std(file))
    }
}

fn build_dump_stage(env: &ResolvedEnvironment, remote_compression: bool) -> Result<StageConfig> {
    let args = mysqldump_args(&env.db);
    let mut spec = if remote_compression {
        let script = build_remote_dump_script(&args);
        let mut command = CommandSpec::new("bash")
            .arg("-lc")
            .arg(script)
            .pipe_stdout();
        if let Some(details) = remote_connection(env) {
            command = command.with_ssh(details.to_options());
        }
        command
    } else {
        build_basic_command(env, "mysqldump", &args)?.pipe_stdout()
    };
    spec = spec.env_redacted("MYSQL_PWD", env.db.password.clone());
    Ok(StageConfig {
        label: "mysqldump",
        spec,
        location: endpoint_location(env),
        program_hint: "mysqldump",
    })
}

fn build_import_stage(env: &ResolvedEnvironment, remote_decompress: bool) -> Result<StageConfig> {
    let args = mysql_args(&env.db);
    let mut spec = if remote_decompress {
        let script = build_remote_import_script(&args);
        let mut command = CommandSpec::new("bash").arg("-lc").arg(script).pipe_stdin();
        if let Some(details) = remote_connection(env) {
            command = command.with_ssh(details.to_options());
        }
        command
    } else {
        let mut base = build_basic_command(env, "mysql", &args)?;
        base = base.pipe_stdin();
        base
    };
    spec = spec.env_redacted("MYSQL_PWD", env.db.password.clone());
    Ok(StageConfig {
        label: "mysql",
        spec,
        location: endpoint_location(env),
        program_hint: "mysql",
    })
}

fn compressor_stage() -> StageConfig {
    let spec = CommandSpec::new("gzip")
        .arg("-c")
        .pipe_stdin()
        .pipe_stdout();
    StageConfig {
        label: "gzip",
        spec,
        location: StageLocation::Local,
        program_hint: "gzip",
    }
}

fn decompressor_stage() -> StageConfig {
    let spec = CommandSpec::new("gzip")
        .arg("-d")
        .arg("-c")
        .pipe_stdin()
        .pipe_stdout();
    StageConfig {
        label: "gunzip",
        spec,
        location: StageLocation::Local,
        program_hint: "gzip",
    }
}

fn mysqldump_args(db: &DatabaseConfig) -> Vec<String> {
    vec![
        format!("--host={}", db.host),
        format!("--port={}", db.port),
        format!("--user={}", db.user),
        "--single-transaction".to_string(),
        "--quick".to_string(),
        "--skip-lock-tables".to_string(),
        "--default-character-set=utf8mb4".to_string(),
        db.name.clone(),
    ]
}

fn mysql_args(db: &DatabaseConfig) -> Vec<String> {
    vec![
        format!("--host={}", db.host),
        format!("--port={}", db.port),
        format!("--user={}", db.user),
        "--default-character-set=utf8mb4".to_string(),
        db.name.clone(),
    ]
}

fn build_remote_dump_script(args: &[String]) -> String {
    format!(
        "set -o pipefail; {} | gzip -c",
        render_command("mysqldump", args)
    )
}

fn build_remote_import_script(args: &[String]) -> String {
    format!(
        "set -o pipefail; gzip -cd | {}",
        render_command("mysql", args)
    )
}

fn build_basic_command(
    env: &ResolvedEnvironment,
    program: &str,
    args: &[String],
) -> Result<CommandSpec> {
    let mut spec = CommandSpec::new(program).args(args.to_vec());
    if let Some(details) = remote_connection(env) {
        spec = spec.with_ssh(details.to_options());
    }
    Ok(spec)
}

fn endpoint_location(env: &ResolvedEnvironment) -> StageLocation {
    match remote_connection(env) {
        Some(details) => StageLocation::Remote(details),
        None => StageLocation::Local,
    }
}

fn remote_connection(env: &ResolvedEnvironment) -> Option<RemoteDetails> {
    match &env.kind {
        EnvironmentKind::Ssh {
            host, user, port, ..
        } => Some(RemoteDetails {
            host: host.clone(),
            user: user.clone(),
            port: *port,
        }),
        _ => None,
    }
}

fn build_wp_cli_plan(
    source: &ResolvedEnvironment,
    target: &ResolvedEnvironment,
    target_summary: &EndpointSummary,
) -> (Option<WpCliPlan>, Option<String>) {
    let wp_cli_path = match target.wp_cli.as_deref() {
        Some(path) => path,
        None => {
            return (
                None,
                Some(format!(
                    "Target environment '{}' does not define wp_cli; skipping WP-CLI stage.",
                    target.name
                )),
            );
        }
    };

    let source_url = match source.url.as_ref() {
        Some(url) => url.clone(),
        None => {
            return (
                None,
                Some(format!(
                    "Source environment '{}' is missing 'url' in Movefile; cannot run WP-CLI search-replace.",
                    source.name
                )),
            );
        }
    };
    let target_url = match target.url.as_ref() {
        Some(url) => url.clone(),
        None => {
            return (
                None,
                Some(format!(
                    "Target environment '{}' is missing 'url'; cannot run WP-CLI search-replace.",
                    target.name
                )),
            );
        }
    };

    let mut spec = if let Some(php) = target.php.as_deref() {
        CommandSpec::new(php).arg(wp_cli_path)
    } else {
        CommandSpec::new(wp_cli_path)
    };
    spec = spec
        .arg("search-replace")
        .arg(&source_url)
        .arg(&target_url)
        .arg("--skip-columns=guid");
    if let Some(dir) = environment_root_path(target) {
        spec = spec.working_dir(dir);
    }
    if target_summary.is_remote() {
        if let Some(details) = remote_connection(target) {
            spec = spec.with_ssh(details.to_options());
        }
    }

    (
        Some(WpCliPlan {
            spec,
            location: endpoint_location(target),
            program_hint: "wp",
            source_url,
            target_url,
        }),
        None,
    )
}

fn environment_root_path(env: &ResolvedEnvironment) -> Option<PathBuf> {
    match &env.kind {
        EnvironmentKind::Local { root } => Some(root.clone()),
        EnvironmentKind::Ssh { root, .. } => Some(PathBuf::from(root)),
    }
}

fn map_spawn_error(err: eyre::Report, program: &str) -> eyre::Report {
    if err.chain().any(|cause| {
        cause
            .downcast_ref::<io::Error>()
            .is_some_and(|io_err| io_err.kind() == io::ErrorKind::NotFound)
    }) {
        return match program {
            "mysqldump" => build_missing_binary_error(
                "mysqldump",
                "Install MySQL client utilities (mysqldump) via 'brew install mysql-client' or 'apt install mysql-client'.",
            ),
            "mysql" => build_missing_binary_error(
                "mysql",
                "Install the MySQL client binary via 'brew install mysql-client' or 'apt install mysql-client'.",
            ),
            "gzip" => build_missing_binary_error(
                "gzip",
                "Install gzip (preinstalled on macOS/Linux; on Windows use WSL or msys2).",
            ),
            "wp" => build_missing_binary_error(
                "wp",
                "Install WP-CLI from https://wp-cli.org/ and ensure it's on PATH.",
            ),
            other => {
                build_missing_binary_error(other, "Ensure the binary is installed and on PATH.")
            }
        };
    }
    err
}

fn build_missing_binary_error(tool: &str, guidance: &str) -> eyre::Report {
    eyre::eyre!("Required binary '{tool}' was not found on PATH. {guidance}")
}

async fn pipe_stream<R, W>(mut reader: R, mut writer: W) -> Result<()>
where
    R: AsyncRead + Unpin + Send + 'static,
    W: AsyncWrite + Unpin + Send + 'static,
{
    tokio::io::copy(&mut reader, &mut writer)
        .await
        .wrap_err("failed to stream database dump between stages")?;
    writer
        .shutdown()
        .await
        .wrap_err("failed to flush downstream stage")?;
    Ok(())
}

fn render_command(program: &str, args: &[String]) -> String {
    let mut parts = Vec::with_capacity(args.len() + 1);
    parts.push(shell_escape(program));
    for arg in args {
        parts.push(shell_escape(arg));
    }
    parts.join(" ")
}

fn shell_escape(value: &str) -> String {
    if value.is_empty() {
        return "''".to_string();
    }
    let mut escaped = String::with_capacity(value.len() + 2);
    escaped.push('\'');
    for ch in value.chars() {
        if ch == '\'' {
            escaped.push_str("'\\''");
        } else {
            escaped.push(ch);
        }
    }
    escaped.push('\'');
    escaped
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::{DatabaseConfig, EnvironmentKind, TransferMode};

    fn mock_db(name: &str) -> DatabaseConfig {
        DatabaseConfig {
            name: name.into(),
            user: "user".into(),
            password: "pass".into(),
            host: "127.0.0.1".into(),
            port: 3306,
        }
    }

    fn local_env(name: &str, url: &str) -> ResolvedEnvironment {
        ResolvedEnvironment {
            name: name.into(),
            kind: EnvironmentKind::Local {
                root: PathBuf::from("/sites"),
            },
            wp_content_subdir: "wp-content".into(),
            excludes: vec![],
            transfer_mode: TransferMode::Compressed,
            db: mock_db(name),
            php: None,
            wp_cli: Some("wp".into()),
            url: Some(url.into()),
        }
    }

    fn remote_env(name: &str, url: &str) -> ResolvedEnvironment {
        ResolvedEnvironment {
            name: name.into(),
            kind: EnvironmentKind::Ssh {
                host: format!("{name}.example.com"),
                user: "deploy".into(),
                port: 22,
                root: "/var/www/site".into(),
            },
            wp_content_subdir: "wp-content".into(),
            excludes: vec![],
            transfer_mode: TransferMode::Compressed,
            db: mock_db(name),
            php: Some("php81".into()),
            wp_cli: Some("/usr/local/bin/wp-cli.phar".into()),
            url: Some(url.into()),
        }
    }

    #[test]
    fn local_plan_omits_compression_filters() {
        let service = DatabaseSyncService::new(false);
        let op = OperationPlan::for_test(crate::Direction::Push, SyncScope::Db, "local", "sandbox");
        let source = local_env("local", "https://local.test");
        let mut target = local_env("sandbox", "https://sandbox.test");
        target.wp_cli = None;
        let plan = service
            .build_plan(&op, &source, &target)
            .expect("plan builds");
        assert_eq!(
            plan.pipeline.filters.len(),
            0,
            "local-to-local should not add filters"
        );
        assert!(!plan.compression.is_enabled());
        assert!(plan.wp_cli.is_none());
        assert!(plan.wp_cli_reason.is_some());
        assert!(
            matches!(plan.staging, StagingMode::Streaming),
            "purely local transfers should stream"
        );
    }

    #[test]
    fn local_to_remote_plan_uses_gzip_filter() {
        let service = DatabaseSyncService::new(false);
        let op = OperationPlan::for_test(crate::Direction::Push, SyncScope::Db, "local", "staging");
        let source = local_env("local", "https://local.test");
        let target = remote_env("staging", "https://staging.example.com");
        let plan = service
            .build_plan(&op, &source, &target)
            .expect("plan builds");
        assert!(plan.compression.is_enabled());
        assert_eq!(plan.pipeline.filters.len(), 1, "gzip filter expected");
        assert_eq!(plan.pipeline.filters[0].label, "gzip");
        assert!(plan.wp_cli.is_some(), "wp-cli should run when available");
        assert!(
            matches!(plan.staging, StagingMode::Streaming),
            "local→remote should stream"
        );
    }

    #[test]
    fn remote_to_local_plan_includes_gunzip_filter() {
        let service = DatabaseSyncService::new(false);
        let op = OperationPlan::for_test(crate::Direction::Pull, SyncScope::Db, "staging", "local");
        let source = remote_env("staging", "https://staging.example.com");
        let target = local_env("local", "https://local.test");
        let plan = service
            .build_plan(&op, &source, &target)
            .expect("plan builds");
        assert!(
            plan.compression.is_enabled(),
            "compressed transport should be used when remotes present"
        );
        assert_eq!(plan.pipeline.filters.len(), 1);
        assert_eq!(plan.pipeline.filters[0].label, "gunzip");
        assert!(
            matches!(plan.staging, StagingMode::Streaming),
            "remote→local continues to stream"
        );
    }

    #[test]
    fn remote_to_remote_plan_stages_to_tempfile() {
        let service = DatabaseSyncService::new(false);
        let op = OperationPlan::for_test(crate::Direction::Push, SyncScope::Db, "staging", "prod");
        let source = remote_env("staging", "https://staging.example.com");
        let target = remote_env("prod", "https://prod.example.com");
        let plan = service
            .build_plan(&op, &source, &target)
            .expect("plan builds");
        match plan.staging {
            StagingMode::TempFile { ref reason } => {
                assert!(
                    reason.contains("Remote-to-remote"),
                    "reason should mention remote staging"
                );
            }
            _ => panic!("remote→remote transfers must require temp files"),
        }
    }

    #[test]
    fn wp_cli_plan_uses_php_wrapper_when_configured() {
        let service = DatabaseSyncService::new(false);
        let op = OperationPlan::for_test(crate::Direction::Push, SyncScope::Db, "local", "staging");
        let source = local_env("local", "https://local.test");
        let target = remote_env("staging", "https://staging.example.com");
        let plan = service
            .build_plan(&op, &source, &target)
            .expect("plan builds");
        let wp = plan.wp_cli.expect("wp-cli plan");
        let description = wp.spec.clone().describe(&wp.location.context_label());
        assert!(
            description.contains("php81"),
            "php wrapper should prefix wp-cli invocation: {description}"
        );
        assert!(
            description.contains("search-replace"),
            "search-replace expected: {description}"
        );
    }

    #[test]
    fn wp_cli_plan_skipped_without_target_binary() {
        let service = DatabaseSyncService::new(false);
        let op = OperationPlan::for_test(crate::Direction::Push, SyncScope::Db, "local", "staging");
        let source = local_env("local", "https://local.test");
        let mut target = remote_env("staging", "https://staging.example.com");
        target.wp_cli = None;
        let plan = service
            .build_plan(&op, &source, &target)
            .expect("plan builds");
        assert!(plan.wp_cli.is_none());
        assert!(
            plan.wp_cli_reason
                .as_ref()
                .is_some_and(|reason| reason.contains("does not define wp_cli")),
            "missing wp_cli reason expected"
        );
    }

    #[test]
    fn wp_cli_plan_skipped_without_urls() {
        let service = DatabaseSyncService::new(false);
        let op = OperationPlan::for_test(crate::Direction::Push, SyncScope::Db, "local", "staging");
        let mut source = local_env("local", "https://local.test");
        source.url = None;
        let target = remote_env("staging", "https://staging.example.com");
        let plan = service
            .build_plan(&op, &source, &target)
            .expect("plan builds");
        assert!(plan.wp_cli.is_none());
        assert!(
            plan.wp_cli_reason.as_ref().is_some_and(
                |reason| reason.contains("Source environment 'local' is missing 'url'")
            )
        );
    }

    #[test]
    fn temp_dump_file_removes_path_on_drop() {
        let dump = TempDumpFile::create(false).expect("temp dump");
        let path = dump.path().to_path_buf();
        assert!(path.exists(), "temp path {path:?} should exist before drop");
        drop(dump);
        assert!(
            !path.exists(),
            "temp path {} should be cleaned automatically",
            path.display()
        );
    }
}
