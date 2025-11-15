#![allow(dead_code)]

use std::borrow::Cow;
use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};
use std::process::ExitStatus;

use async_trait::async_trait;
use color_eyre::eyre::{self, Context, Result};
use tokio::io::{AsyncRead, AsyncReadExt};
use tokio::process::{Child, ChildStderr, ChildStdin, ChildStdout, Command as TokioCommand};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum StdinConfig {
    Inherit,
    Null,
    Pipe,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum OutputConfig {
    Inherit,
    Capture,
    Pipe,
}

#[derive(Debug, Clone)]
pub struct SshOptions {
    user: String,
    host: String,
    port: u16,
    identity_file: Option<PathBuf>,
    extra_args: Vec<String>,
}

impl SshOptions {
    pub fn new(user: impl Into<String>, host: impl Into<String>) -> Self {
        Self {
            user: user.into(),
            host: host.into(),
            port: 22,
            identity_file: None,
            extra_args: Vec::new(),
        }
    }

    pub fn with_port(mut self, port: u16) -> Self {
        self.port = port;
        self
    }

    pub fn with_identity_file(mut self, path: impl Into<PathBuf>) -> Self {
        self.identity_file = Some(path.into());
        self
    }

    pub fn extra_arg(mut self, arg: impl Into<String>) -> Self {
        self.extra_args.push(arg.into());
        self
    }

    pub fn extra_args<I, S>(mut self, args: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.extra_args
            .extend(args.into_iter().map(|value| value.into()));
        self
    }

    pub fn user(&self) -> &str {
        &self.user
    }

    pub fn host(&self) -> &str {
        &self.host
    }

    pub fn port(&self) -> u16 {
        self.port
    }

    pub fn identity_file(&self) -> Option<&Path> {
        self.identity_file.as_deref()
    }

    pub fn extra_ssh_args(&self) -> &[String] {
        &self.extra_args
    }
}

#[derive(Debug, Clone)]
pub struct CommandSpec {
    program: String,
    args: Vec<String>,
    cwd: Option<PathBuf>,
    env: BTreeMap<String, String>,
    redacted_env: BTreeSet<String>,
    stdin: StdinConfig,
    stdout: OutputConfig,
    stderr: OutputConfig,
    ssh: Option<SshOptions>,
}

impl CommandSpec {
    pub fn new(program: impl Into<String>) -> Self {
        Self {
            program: program.into(),
            args: Vec::new(),
            cwd: None,
            env: BTreeMap::new(),
            redacted_env: BTreeSet::new(),
            stdin: StdinConfig::Inherit,
            stdout: OutputConfig::Inherit,
            stderr: OutputConfig::Inherit,
            ssh: None,
        }
    }

    pub fn arg(mut self, arg: impl Into<String>) -> Self {
        self.args.push(arg.into());
        self
    }

    pub fn args<I, S>(mut self, args: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.args.extend(args.into_iter().map(|value| value.into()));
        self
    }

    pub fn working_dir(mut self, dir: impl Into<PathBuf>) -> Self {
        self.cwd = Some(dir.into());
        self
    }

    pub fn env(mut self, key: impl Into<String>, value: impl Into<String>) -> Self {
        self.env.insert(key.into(), value.into());
        self
    }

    pub fn env_redacted(mut self, key: impl Into<String>, value: impl Into<String>) -> Self {
        let key_string = key.into();
        self.redacted_env.insert(key_string.clone());
        self.env.insert(key_string, value.into());
        self
    }

    pub fn redact_env_var(mut self, key: impl Into<String>) -> Self {
        self.redacted_env.insert(key.into());
        self
    }

    pub fn stdin(mut self, mode: StdinConfig) -> Self {
        self.stdin = mode;
        self
    }

    pub fn stdout(mut self, mode: OutputConfig) -> Self {
        self.stdout = mode;
        self
    }

    pub fn stderr(mut self, mode: OutputConfig) -> Self {
        self.stderr = mode;
        self
    }

    pub fn capture_stdout(self) -> Self {
        self.stdout(OutputConfig::Capture)
    }

    pub fn capture_stderr(self) -> Self {
        self.stderr(OutputConfig::Capture)
    }

    pub fn pipe_stdout(self) -> Self {
        self.stdout(OutputConfig::Pipe)
    }

    pub fn pipe_stderr(self) -> Self {
        self.stderr(OutputConfig::Pipe)
    }

    pub fn pipe_stdin(self) -> Self {
        self.stdin(StdinConfig::Pipe)
    }

    pub fn with_ssh(mut self, options: SshOptions) -> Self {
        self.ssh = Some(options);
        self
    }

    pub fn program(&self) -> &str {
        &self.program
    }

    pub fn args_list(&self) -> &[String] {
        &self.args
    }

    pub fn env_vars(&self) -> &BTreeMap<String, String> {
        &self.env
    }

    pub fn redacted_env(&self) -> &BTreeSet<String> {
        &self.redacted_env
    }

    pub fn working_directory(&self) -> Option<&Path> {
        self.cwd.as_deref()
    }

    pub fn stdin_config(&self) -> StdinConfig {
        self.stdin
    }

    pub fn stdout_config(&self) -> OutputConfig {
        self.stdout
    }

    pub fn stderr_config(&self) -> OutputConfig {
        self.stderr
    }

    pub fn ssh_options(&self) -> Option<&SshOptions> {
        self.ssh.as_ref()
    }

    pub fn requires_pipes(&self) -> bool {
        matches!(self.stdin, StdinConfig::Pipe)
            || matches!(self.stdout, OutputConfig::Pipe)
            || matches!(self.stderr, OutputConfig::Pipe)
    }

    fn describe(&self, context: &str) -> String {
        describe_command(
            context,
            self.cwd.as_deref(),
            &self.env,
            &self.redacted_env,
            &self.program,
            &self.args,
        )
    }
}

#[derive(Debug)]
pub struct ExecResult {
    invocation: String,
    status: ExitStatus,
    stdout: Option<String>,
    stderr: Option<String>,
}

impl ExecResult {
    pub fn invocation(&self) -> &str {
        &self.invocation
    }

    pub fn status(&self) -> ExitStatus {
        self.status
    }

    pub fn stdout(&self) -> Option<&str> {
        self.stdout.as_deref()
    }

    pub fn stderr(&self) -> Option<&str> {
        self.stderr.as_deref()
    }

    pub fn ensure_success(&self) -> Result<()> {
        if self.status.success() {
            return Ok(());
        }

        let code = self
            .status
            .code()
            .map(|value| value.to_string())
            .unwrap_or_else(|| "unknown".to_string());
        let stderr_preview = self
            .stderr()
            .filter(|msg| !msg.trim().is_empty())
            .map(|msg| msg.trim().to_string())
            .unwrap_or_else(|| "no stderr captured".to_string());
        eyre::bail!(
            "Command '{invocation}' failed with exit code {code}: {stderr_preview}",
            invocation = self.invocation
        );
    }
}

pub struct CommandChild {
    invocation: String,
    child: Child,
    capture_stdout: bool,
    capture_stderr: bool,
}

impl CommandChild {
    fn new(invocation: String, child: Child, capture_stdout: bool, capture_stderr: bool) -> Self {
        Self {
            invocation,
            child,
            capture_stdout,
            capture_stderr,
        }
    }

    pub fn stdin(&mut self) -> Option<&mut ChildStdin> {
        self.child.stdin.as_mut()
    }

    pub fn take_stdin(&mut self) -> Option<ChildStdin> {
        self.child.stdin.take()
    }

    pub fn take_stdout(&mut self) -> Option<ChildStdout> {
        self.child.stdout.take()
    }

    pub fn take_stderr(&mut self) -> Option<ChildStderr> {
        self.child.stderr.take()
    }

    pub async fn wait(mut self) -> Result<ExitStatus> {
        let invocation = self.invocation.clone();
        self.child
            .wait()
            .await
            .wrap_err_with(|| format!("failed to wait for '{invocation}'"))
    }

    pub async fn wait_with_output(self) -> Result<ExecResult> {
        let invocation = self.invocation.clone();
        let mut child = self.child;
        let capture_stdout = self.capture_stdout;
        let capture_stderr = self.capture_stderr;

        let stdout_pipe = if capture_stdout {
            child.stdout.take()
        } else {
            None
        };
        let stderr_pipe = if capture_stderr {
            child.stderr.take()
        } else {
            None
        };

        let stdout_future = async move {
            capture_optionally(stdout_pipe)
                .await
                .wrap_err("failed to read stdout pipe")
        };

        let stderr_future = async move {
            capture_optionally(stderr_pipe)
                .await
                .wrap_err("failed to read stderr pipe")
        };

        let wait_future = async move {
            child
                .wait()
                .await
                .wrap_err("failed to wait for spawned process")
        };

        let (stdout, stderr, status) = tokio::try_join!(stdout_future, stderr_future, wait_future)
            .wrap_err_with(|| format!("failed while running '{invocation}'"))?;

        Ok(ExecResult {
            invocation,
            status,
            stdout: stdout.map(bytes_to_string),
            stderr: stderr.map(bytes_to_string),
        })
    }
}

#[async_trait]
pub trait CommandExecutor: Send + Sync {
    async fn exec(&self, spec: CommandSpec) -> Result<ExecResult>;

    async fn exec_checked(&self, spec: CommandSpec) -> Result<ExecResult> {
        let result = self.exec(spec).await?;
        result.ensure_success()?;
        Ok(result)
    }

    async fn spawn(&self, spec: CommandSpec) -> Result<CommandChild>;
}

#[derive(Clone)]
pub struct LocalCommandExecutor {
    verbose: bool,
}

impl LocalCommandExecutor {
    pub fn new(verbose: bool) -> Self {
        Self { verbose }
    }

    async fn spawn_command(&self, spec: CommandSpec) -> Result<CommandChild> {
        let invocation = spec.describe("local");
        if self.verbose {
            eprintln!("{invocation}");
        }

        let mut command = TokioCommand::new(&spec.program);
        command.args(&spec.args);
        if let Some(dir) = spec.cwd.as_deref() {
            command.current_dir(dir);
        }
        for (key, value) in &spec.env {
            command.env(key, value);
        }

        command.stdin(map_stdin(spec.stdin));
        command.stdout(map_output(spec.stdout));
        command.stderr(map_output(spec.stderr));

        let capture_stdout = matches!(spec.stdout, OutputConfig::Capture);
        let capture_stderr = matches!(spec.stderr, OutputConfig::Capture);
        let child = command
            .spawn()
            .wrap_err_with(|| format!("failed to start '{invocation}'"))?;
        Ok(CommandChild::new(
            invocation,
            child,
            capture_stdout,
            capture_stderr,
        ))
    }
}

#[async_trait]
impl CommandExecutor for LocalCommandExecutor {
    async fn exec(&self, spec: CommandSpec) -> Result<ExecResult> {
        if spec.requires_pipes() {
            eyre::bail!(
                "Command with piped stdio must be spawned via spawn(); exec() cannot stream pipes"
            );
        }
        self.spawn_command(spec).await?.wait_with_output().await
    }

    async fn spawn(&self, spec: CommandSpec) -> Result<CommandChild> {
        self.spawn_command(spec).await
    }
}

pub struct SshCommandExecutor {
    ssh_binary: String,
    local: LocalCommandExecutor,
}

impl SshCommandExecutor {
    pub fn new(verbose: bool) -> Self {
        Self::with_ssh_binary("ssh", verbose)
    }

    pub fn with_ssh_binary(binary: impl Into<String>, verbose: bool) -> Self {
        Self {
            ssh_binary: binary.into(),
            local: LocalCommandExecutor::new(verbose),
        }
    }

    fn build_invocation(&self, spec: &CommandSpec) -> Result<(CommandSpec, String)> {
        let ssh = spec
            .ssh_options()
            .ok_or_else(|| eyre::eyre!("SSH options must be provided for SSH execution"))?;

        let remote_cmd = render_remote_command(spec);
        let mut local_spec = CommandSpec::new(self.ssh_binary.clone())
            .stdin(spec.stdin_config())
            .stdout(spec.stdout_config())
            .stderr(spec.stderr_config());

        let mut args = Vec::new();
        if ssh.port() != 22 {
            args.push("-p".to_string());
            args.push(ssh.port().to_string());
        }
        if let Some(identity) = ssh.identity_file() {
            args.push("-i".to_string());
            args.push(identity.display().to_string());
        }
        args.extend(ssh.extra_ssh_args().iter().cloned());
        args.push(format!("{}@{}", ssh.user(), ssh.host()));
        args.push(remote_cmd.clone());

        local_spec = local_spec.args(args);
        Ok((
            local_spec,
            format!("ssh {}@{}:{}", ssh.user(), ssh.host(), ssh.port()),
        ))
    }
}

#[async_trait]
impl CommandExecutor for SshCommandExecutor {
    async fn exec(&self, spec: CommandSpec) -> Result<ExecResult> {
        if spec.requires_pipes() {
            eyre::bail!(
                "Command with piped stdio must be spawned via spawn(); exec() cannot stream pipes"
            );
        }
        let (local_spec, context) = self.build_invocation(&spec)?;
        let remote_invocation = spec.describe(&context);
        if self.local.verbose {
            eprintln!("{remote_invocation}");
        }
        self.local.exec(local_spec).await
    }

    async fn spawn(&self, spec: CommandSpec) -> Result<CommandChild> {
        let (local_spec, context) = self.build_invocation(&spec)?;
        if self.local.verbose {
            eprintln!("{}", spec.describe(&context));
        }
        self.local.spawn(local_spec).await
    }
}

fn map_stdin(config: StdinConfig) -> std::process::Stdio {
    match config {
        StdinConfig::Inherit => std::process::Stdio::inherit(),
        StdinConfig::Null => std::process::Stdio::null(),
        StdinConfig::Pipe => std::process::Stdio::piped(),
    }
}

fn map_output(config: OutputConfig) -> std::process::Stdio {
    match config {
        OutputConfig::Inherit => std::process::Stdio::inherit(),
        OutputConfig::Capture | OutputConfig::Pipe => std::process::Stdio::piped(),
    }
}

async fn capture_optionally<R>(pipe: Option<R>) -> std::io::Result<Option<Vec<u8>>>
where
    R: AsyncRead + Unpin,
{
    match pipe {
        Some(mut reader) => {
            let mut buf = Vec::new();
            reader.read_to_end(&mut buf).await?;
            Ok(Some(buf))
        }
        None => Ok(None),
    }
}

fn bytes_to_string(bytes: Vec<u8>) -> String {
    String::from_utf8_lossy(&bytes).to_string()
}

fn describe_command(
    context: &str,
    cwd: Option<&Path>,
    env: &BTreeMap<String, String>,
    redacted: &BTreeSet<String>,
    program: &str,
    args: &[String],
) -> String {
    let mut parts = Vec::new();
    if let Some(dir) = cwd {
        parts.push(format!("(cd {})", dir.display()));
    }
    for (key, value) in env {
        let display = if redacted.contains(key) {
            Cow::Borrowed("****")
        } else {
            Cow::Borrowed(value.as_str())
        };
        parts.push(format!("{key}={value}", value = display));
    }
    parts.push(shell_escape(program));
    for arg in args {
        parts.push(shell_escape(arg));
    }
    format!("[{context}] {}", parts.join(" "))
}

fn render_remote_command(spec: &CommandSpec) -> String {
    let mut segments = Vec::new();
    if let Some(dir) = spec.working_directory() {
        segments.push(format!("cd {}", shell_escape(&dir.to_string_lossy())));
        segments.push("&&".to_string());
    }
    for (key, value) in spec.env_vars() {
        segments.push(format!("{key}={value}", value = shell_escape(value)));
    }
    segments.push(shell_escape(spec.program()));
    for arg in spec.args_list() {
        segments.push(shell_escape(arg));
    }
    segments.join(" ")
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
    use tokio::io::{AsyncReadExt, AsyncWriteExt};

    #[tokio::test]
    async fn local_exec_captures_stdout_and_env() {
        let executor = LocalCommandExecutor::new(false);
        let spec = CommandSpec::new("bash")
            .arg("-c")
            .arg("printf '%s' \"$FOO\"")
            .env("FOO", "hello-world")
            .capture_stdout();

        let result = executor.exec(spec).await.expect("command runs");
        assert_eq!(result.stdout(), Some("hello-world"));
        assert!(result.status().success());
    }

    #[tokio::test]
    async fn local_exec_reports_failure_with_stderr() {
        let executor = LocalCommandExecutor::new(false);
        let spec = CommandSpec::new("bash")
            .arg("-c")
            .arg("echo boom >&2; exit 42")
            .capture_stderr();

        let result = executor.exec(spec).await.expect("command completes");
        assert_eq!(result.status().code(), Some(42));
        let err = result.ensure_success().unwrap_err();
        let message = err.to_string();
        assert!(message.contains("exit code 42"));
        assert!(message.contains("boom"));
    }

    #[tokio::test]
    async fn spawn_exposes_pipes_for_streaming() {
        let executor = LocalCommandExecutor::new(false);
        let spec = CommandSpec::new("cat").pipe_stdin().pipe_stdout();

        let mut child = executor.spawn(spec).await.expect("spawns child");
        {
            let mut stdin = child.take_stdin().expect("stdin available");
            stdin
                .write_all(b"streaming test")
                .await
                .expect("writes to stdin");
        }

        let mut stdout = child.take_stdout().expect("stdout handle");
        let mut buf = Vec::new();
        stdout.read_to_end(&mut buf).await.expect("reads stdout");
        assert_eq!(String::from_utf8_lossy(&buf), "streaming test");
        let status = child.wait().await.expect("waits for child");
        assert!(status.success());
    }

    #[test]
    fn ssh_command_builder_forms_expected_args() {
        let remote_spec = CommandSpec::new("wp")
            .arg("cli")
            .arg("--info")
            .env("WP_ENV", "staging")
            .working_dir("/var/www/site")
            .with_ssh(
                SshOptions::new("deploy", "example.com")
                    .with_port(2222)
                    .with_identity_file("/home/user/.ssh/id_rsa")
                    .extra_arg("-oStrictHostKeyChecking=no"),
            )
            .capture_stdout();

        let executor = SshCommandExecutor::with_ssh_binary("ssh", false);
        let (ssh_spec, context) = executor
            .build_invocation(&remote_spec)
            .expect("builds command");
        assert_eq!(context, "ssh deploy@example.com:2222");
        assert_eq!(ssh_spec.program(), "ssh");
        assert_eq!(
            ssh_spec.args_list(),
            &[
                "-p".to_string(),
                "2222".to_string(),
                "-i".to_string(),
                "/home/user/.ssh/id_rsa".to_string(),
                "-oStrictHostKeyChecking=no".to_string(),
                "deploy@example.com".to_string(),
                "cd '/var/www/site' && WP_ENV='staging' 'wp' 'cli' '--info'".to_string(),
            ]
        );
    }

    #[test]
    fn logging_redacts_env_values() {
        let spec = CommandSpec::new("env")
            .env_redacted("SECRET", "top-secret")
            .env("MODE", "safe")
            .capture_stdout();
        let rendered = spec.describe("local");
        assert!(rendered.contains("SECRET=****"));
        assert!(rendered.contains("MODE=safe"));
    }
}
