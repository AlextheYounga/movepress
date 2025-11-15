use crate::OperationPlan;
use crate::command::{CommandExecutor, CommandSpec, LocalCommandExecutor};
use crate::config::TransferMode;
use crate::path_resolver::{FileScopeTargets, RsyncEndpoint};
use color_eyre::eyre::{self, Context, Result};
use std::io::{self, Write};
use tempfile::{Builder, NamedTempFile};

pub struct FileSyncService {
    executor: LocalCommandExecutor,
    verbose: bool,
}

impl FileSyncService {
    pub fn new(verbose: bool) -> Self {
        Self {
            executor: LocalCommandExecutor::new(verbose),
            verbose,
        }
    }

    pub async fn sync(
        &self,
        plan: &OperationPlan,
        targets: &FileScopeTargets,
        dry_run: bool,
    ) -> Result<FileSyncResult> {
        let prepared = self.prepare_command(targets, dry_run)?;
        if dry_run && involves_remote(&targets.source, &targets.target) {
            return Ok(FileSyncResult {
                dry_run,
                command: prepared.display,
                stdout: String::new(),
                stderr: String::new(),
                stats: None,
                excludes: prepared.excludes,
                executed: false,
            });
        }

        let exec_result = self
            .executor
            .exec(prepared.spec.clone())
            .await
            .map_err(|err| map_spawn_error(err))?;

        if !exec_result.status().success() {
            let summary = format!(
                "{} {} from '{}' to '{}'",
                plan.direction,
                targets.scope().label(),
                plan.source,
                plan.target
            );
            let err = exec_result.ensure_success().unwrap_err();
            return Err(err.wrap_err(format!("rsync failed while attempting to {summary}.")));
        }

        let stdout = exec_result.stdout().unwrap_or("").to_string();
        let stderr = exec_result.stderr().unwrap_or("").to_string();
        let stats = RsyncStats::parse(&stdout);

        Ok(FileSyncResult {
            dry_run,
            command: prepared.display,
            stdout,
            stderr,
            stats,
            excludes: prepared.excludes,
            executed: true,
        })
    }

    fn prepare_command(
        &self,
        targets: &FileScopeTargets,
        dry_run: bool,
    ) -> Result<PreparedCommand> {
        ensure_supported_direction(&targets.source, &targets.target)?;

        let mut args = vec!["-a".to_string(), "--stats".to_string()];
        if dry_run {
            args.push("--dry-run".to_string());
        }
        if self.verbose {
            args.push("-v".to_string());
        }

        if should_compress(&targets.source, &targets.target) {
            args.push("-z".to_string());
        }

        if let Some(remote_port) = detect_remote_port(&targets.source, &targets.target) {
            if remote_port != 22 {
                args.push("-e".to_string());
                args.push(format!("ssh -p {}", remote_port));
            }
        }

        let excludes = gather_excludes(&targets.source, &targets.target);
        let exclude_file = build_exclude_file(&excludes)?;
        if let Some(file) = exclude_file.as_ref() {
            args.push("--exclude-from".to_string());
            args.push(file.path().display().to_string());
        }

        args.push(targets.source.rsync_path());
        args.push(targets.target.rsync_path());

        let mut spec = CommandSpec::new("rsync").args(args.clone());
        spec = spec.capture_stdout().capture_stderr();

        let display_args = redact_exclude_args(&args);
        Ok(PreparedCommand {
            spec,
            display: render_command("rsync", &display_args),
            excludes,
            _exclude_file: exclude_file,
        })
    }
}

#[derive(Debug)]
struct PreparedCommand {
    spec: CommandSpec,
    display: String,
    excludes: Vec<String>,
    _exclude_file: Option<NamedTempFile>,
}

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct RsyncStats {
    pub total_files: u64,
    pub transferred_files: u64,
    pub total_file_size: u64,
    pub transferred_file_size: u64,
    pub literal_data: u64,
    pub sent_bytes: u64,
    pub received_bytes: u64,
}

impl RsyncStats {
    pub fn describe(&self, dry_run: bool) -> String {
        let verb = if dry_run {
            "Would transfer"
        } else {
            "Transferred"
        };
        let total_display = format_bytes(self.total_file_size);
        let transferred_display = format_bytes(self.transferred_file_size);
        format!(
            "{verb} {} of {} files ({transferred_display}) out of {total_display}.",
            self.transferred_files, self.total_files
        )
    }

    pub fn totals_line(&self) -> String {
        format!(
            "Data sent: {} | Data received: {}",
            format_bytes(self.sent_bytes),
            format_bytes(self.received_bytes)
        )
    }

    fn parse(stdout: &str) -> Option<Self> {
        let mut stats = Self::default();
        let mut saw_stats = false;
        for line in stdout.lines() {
            let trimmed = line.trim();
            if let Some(value) = trimmed.strip_prefix("Number of files:") {
                stats.total_files = parse_colon_value(value);
                saw_stats = true;
            } else if let Some(value) = trimmed.strip_prefix("Number of regular files transferred:")
            {
                stats.transferred_files = parse_colon_value(value);
            } else if let Some(value) = trimmed.strip_prefix("Total file size:") {
                stats.total_file_size = parse_colon_value(value);
            } else if let Some(value) = trimmed.strip_prefix("Total transferred file size:") {
                stats.transferred_file_size = parse_colon_value(value);
            } else if let Some(value) = trimmed.strip_prefix("Literal data:") {
                stats.literal_data = parse_colon_value(value);
            } else if let Some(value) = trimmed.strip_prefix("Total bytes sent:") {
                stats.sent_bytes = parse_colon_value(value);
            } else if let Some(value) = trimmed.strip_prefix("Total bytes received:") {
                stats.received_bytes = parse_colon_value(value);
            } else if trimmed.starts_with("sent ") && trimmed.contains("received") {
                let tokens: Vec<&str> = trimmed.split_whitespace().collect();
                if tokens.len() >= 6 {
                    stats.sent_bytes = parse_numeric(tokens[1]);
                    stats.received_bytes = parse_numeric(tokens[4]);
                }
            }
        }

        if saw_stats { Some(stats) } else { None }
    }
}

pub struct FileSyncResult {
    pub dry_run: bool,
    pub command: String,
    pub stdout: String,
    pub stderr: String,
    pub stats: Option<RsyncStats>,
    pub excludes: Vec<String>,
    pub executed: bool,
}

fn should_compress(source: &RsyncEndpoint, target: &RsyncEndpoint) -> bool {
    matches!(source.transfer_mode(), TransferMode::Compressed)
        || matches!(target.transfer_mode(), TransferMode::Compressed)
}

fn ensure_supported_direction(source: &RsyncEndpoint, target: &RsyncEndpoint) -> Result<()> {
    let source_remote = source.remote_details().is_some();
    let target_remote = target.remote_details().is_some();

    match (source_remote, target_remote) {
        (false, false) | (true, false) | (false, true) => Ok(()),
        (true, true) => eyre::bail!(
            "File sync between two remote environments is not supported yet. Run movepress from one of the servers or sync via local staging."
        ),
    }
}

fn involves_remote(source: &RsyncEndpoint, target: &RsyncEndpoint) -> bool {
    source.remote_details().is_some() || target.remote_details().is_some()
}

fn detect_remote_port(source: &RsyncEndpoint, target: &RsyncEndpoint) -> Option<u16> {
    source
        .remote_details()
        .map(|(_, _, port, _)| port)
        .or_else(|| target.remote_details().map(|(_, _, port, _)| port))
}

fn gather_excludes(source: &RsyncEndpoint, target: &RsyncEndpoint) -> Vec<String> {
    let mut excludes = source.excludes().to_vec();
    excludes.extend(target.excludes().iter().cloned());
    excludes.sort();
    excludes.dedup();
    excludes
}

fn build_exclude_file(patterns: &[String]) -> Result<Option<NamedTempFile>> {
    if patterns.is_empty() {
        return Ok(None);
    }
    let mut file = Builder::new()
        .prefix("movepress-excludes-")
        .tempfile()
        .wrap_err("Failed to create temporary exclude file for rsync")?;
    {
        let handle = file.as_file_mut();
        for pattern in patterns {
            writeln!(handle, "{pattern}").wrap_err("Failed to write rsync exclude pattern")?;
        }
    }
    Ok(Some(file))
}

fn render_command(program: &str, args: &[String]) -> String {
    let mut parts = Vec::with_capacity(args.len() + 1);
    parts.push(shell_escape(program));
    for arg in args {
        parts.push(shell_escape(arg));
    }
    parts.join(" ")
}

fn redact_exclude_args(args: &[String]) -> Vec<String> {
    let mut redacted = Vec::with_capacity(args.len());
    let mut idx = 0;
    while idx < args.len() {
        let arg = &args[idx];
        if arg == "--exclude-from" {
            redacted.push(arg.clone());
            if idx + 1 < args.len() {
                redacted.push("[excludes-file]".to_string());
                idx += 2;
                continue;
            }
        } else if let Some(_) = arg.strip_prefix("--exclude-from=") {
            redacted.push("--exclude-from=[excludes-file]".to_string());
            idx += 1;
            continue;
        }
        redacted.push(arg.clone());
        idx += 1;
    }
    redacted
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

fn parse_colon_value(raw: &str) -> u64 {
    let trimmed = raw.trim();
    let segment = trimmed
        .split_whitespace()
        .next()
        .unwrap_or_default()
        .trim_end_matches(|ch: char| !ch.is_ascii_digit() && ch != ',' && ch != '.');
    parse_numeric(segment)
}

fn parse_numeric(raw: &str) -> u64 {
    let digits: String = raw.chars().filter(|ch| ch.is_ascii_digit()).collect();
    digits.parse::<u64>().unwrap_or(0)
}

fn format_bytes(bytes: u64) -> String {
    const UNITS: [&str; 5] = ["B", "KB", "MB", "GB", "TB"];
    let mut value = bytes as f64;
    let mut unit = 0;
    while value >= 1024.0 && unit < UNITS.len() - 1 {
        value /= 1024.0;
        unit += 1;
    }
    if unit == 0 {
        format!("{bytes} {}", UNITS[unit])
    } else {
        format!("{value:.2} {}", UNITS[unit])
    }
}

fn build_missing_rsync_error() -> eyre::Report {
    eyre::eyre!(
        "rsync is required but was not found on PATH. Install it via:\n  • macOS: `brew install rsync` (pre-installed on most releases)\n  • Linux: `apt-get install rsync` or `yum install rsync`\n  • Windows: install via WSL, Cygwin, or msys2 for SSH+rsync support."
    )
}

fn map_spawn_error(err: eyre::Report) -> eyre::Report {
    if err.chain().any(|cause| {
        cause
            .downcast_ref::<io::Error>()
            .is_some_and(|io_err| io_err.kind() == io::ErrorKind::NotFound)
    }) {
        build_missing_rsync_error()
    } else {
        err
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::{DatabaseConfig, EnvironmentKind, ResolvedEnvironment};
    use crate::path_resolver::{FileScope, resolve_file_targets};
    use crate::{Direction, SyncScope};
    use tempfile::tempdir;

    fn mock_db() -> DatabaseConfig {
        DatabaseConfig {
            name: "db".into(),
            user: "user".into(),
            password: "".into(),
            host: "localhost".into(),
            port: 3306,
        }
    }

    fn build_local_env(name: &str, root: &std::path::Path) -> ResolvedEnvironment {
        ResolvedEnvironment {
            name: name.into(),
            kind: EnvironmentKind::Local {
                root: root.to_path_buf(),
            },
            wp_content_subdir: "wp-content".into(),
            excludes: vec!["*.log".into()],
            transfer_mode: TransferMode::Compressed,
            db: mock_db(),
            php: None,
            wp_cli: None,
        }
    }

    fn build_remote_env(name: &str) -> ResolvedEnvironment {
        ResolvedEnvironment {
            name: name.into(),
            kind: EnvironmentKind::Ssh {
                host: format!("{name}.example.com"),
                user: "deploy".into(),
                port: 2222,
                root: "/var/www/site".into(),
            },
            wp_content_subdir: "wp-content".into(),
            excludes: vec!["cache/**".into()],
            transfer_mode: TransferMode::Compressed,
            db: mock_db(),
            php: None,
            wp_cli: None,
        }
    }

    #[tokio::test]
    async fn executes_local_sync_in_dry_run() {
        if which::which("rsync").is_err() {
            eprintln!("skipping rsync integration test because rsync is unavailable");
            return;
        }
        let dir = tempdir().expect("temp dir");
        let wp_content = dir.path().join("wp-content");
        let uploads = wp_content.join("uploads");
        std::fs::create_dir_all(&uploads).expect("create tree");
        std::fs::write(uploads.join("file.txt"), "hello").expect("write file");

        let dst_dir = tempdir().expect("dst dir");
        let dst_wp = dst_dir.path().join("wp-content");
        let dst_uploads = dst_wp.join("uploads");
        std::fs::create_dir_all(&dst_uploads).expect("create dst");

        let local = build_local_env("local", dir.path());
        let other = build_local_env("other", dst_dir.path());
        let targets = resolve_file_targets(&local, &other)
            .expect("resolve targets")
            .for_scope(FileScope::Uploads);

        let plan = OperationPlan::for_test(Direction::Push, SyncScope::Uploads, "local", "other");
        let service = FileSyncService::new(false);
        let result = service.sync(&plan, &targets, true).await.expect("dry run");
        assert!(result.command.contains("rsync"));
        assert!(result.dry_run);
        assert!(result.stats.is_some());
        assert!(result.executed, "local dry-run should execute rsync");
    }

    #[tokio::test]
    async fn copies_files_when_rsync_available() {
        if which::which("rsync").is_err() {
            eprintln!("skipping rsync integration test because rsync is unavailable");
            return;
        }
        let dir = tempdir().expect("temp dir");
        let src_root = dir.path();
        let src_wp = src_root.join("wp-content/uploads");
        std::fs::create_dir_all(&src_wp).expect("create src tree");
        std::fs::write(src_wp.join("asset.txt"), "from-local").expect("write src file");

        let dst_dir = tempdir().expect("dst dir");
        let dst_root = dst_dir.path();
        let dst_wp = dst_root.join("wp-content/uploads");
        std::fs::create_dir_all(&dst_wp).expect("create dst tree");

        let local = build_local_env("local", src_root);
        let other = build_local_env("other", dst_root);
        let targets = resolve_file_targets(&local, &other)
            .expect("resolve targets")
            .for_scope(FileScope::Uploads);
        let plan = OperationPlan::for_test(Direction::Push, SyncScope::Uploads, "local", "other");
        let service = FileSyncService::new(false);
        service
            .sync(&plan, &targets, false)
            .await
            .expect("sync succeeds");
        let copied = dst_wp.join("asset.txt");
        assert!(
            copied.exists(),
            "expected copied file at {}",
            copied.display()
        );
        assert_eq!(
            std::fs::read_to_string(copied).expect("read copied"),
            "from-local"
        );
    }

    #[tokio::test]
    async fn remote_dry_run_skips_execution() {
        let dir = tempdir().expect("temp dir");
        let wp_content = dir.path().join("wp-content/uploads");
        std::fs::create_dir_all(&wp_content).expect("create local tree");

        let local = build_local_env("local", dir.path());
        let remote = build_remote_env("remote");
        let targets = resolve_file_targets(&local, &remote)
            .expect("resolve targets")
            .for_scope(FileScope::Uploads);
        let plan = OperationPlan::for_test(Direction::Push, SyncScope::Uploads, "local", "remote");
        let service = FileSyncService::new(false);
        let result = service
            .sync(&plan, &targets, true)
            .await
            .expect("sync preview");
        assert!(
            !result.executed,
            "expected preview to skip remote rsync execution"
        );
        assert!(result.stats.is_none());
    }

    #[test]
    fn prepare_command_includes_excludes_and_compression() {
        let dir = tempdir().expect("temp dir");
        let wp_content = dir.path().join("wp-content");
        let uploads = wp_content.join("uploads");
        std::fs::create_dir_all(&uploads).expect("create tree");

        let dst_dir = tempdir().expect("dst dir");
        let dst_wp = dst_dir.path().join("wp-content");
        let dst_uploads = dst_wp.join("uploads");
        std::fs::create_dir_all(&dst_uploads).expect("create dst");

        let local = build_local_env("local", dir.path());
        let other = build_local_env("other", dst_dir.path());
        let targets = resolve_file_targets(&local, &other)
            .expect("resolve targets")
            .for_scope(FileScope::Uploads);

        let service = FileSyncService::new(false);
        let prepared = service.prepare_command(&targets, false).expect("command");
        assert!(
            prepared.display.contains("-z"),
            "expected compression flag: {}",
            prepared.display
        );
        assert!(prepared.excludes.contains(&"*.log".to_string()));
        let uploads_display = uploads.to_string_lossy();
        assert!(
            prepared.display.contains(uploads_display.as_ref()),
            "expected source path"
        );
    }

    #[test]
    fn remote_command_includes_custom_port() {
        let dir = tempdir().expect("temp dir");
        let wp_content = dir.path().join("wp-content");
        let uploads = wp_content.join("uploads");
        std::fs::create_dir_all(&uploads).expect("create tree");

        let local = build_local_env("local", dir.path());
        let remote = build_remote_env("remote");
        let targets = resolve_file_targets(&local, &remote)
            .expect("resolve targets")
            .for_scope(FileScope::Uploads);
        let service = FileSyncService::new(false);
        let prepared = service.prepare_command(&targets, false).expect("command");
        assert!(
            prepared.display.contains("ssh -p 2222"),
            "expected ssh -p flag, got {}",
            prepared.display
        );
    }
}
