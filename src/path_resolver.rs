#![allow(dead_code)]
use crate::config::{EnvironmentKind, ResolvedEnvironment, TransferMode};
use crate::{Direction, OperationPlan, SyncScope};
use color_eyre::eyre::{self, Context, Result};
use std::fs;
use std::path::{Path, PathBuf};
use tempfile::{Builder, TempDir};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FileScope {
    Uploads,
    Content,
}

impl FileScope {
    pub fn label(&self) -> &'static str {
        match self {
            FileScope::Uploads => "uploads",
            FileScope::Content => "wp-content",
        }
    }
}

#[derive(Debug, Clone)]
pub struct FileTargets {
    pub source: EnvironmentPaths,
    pub target: EnvironmentPaths,
}

impl FileTargets {
    pub fn for_scope(&self, scope: FileScope) -> FileScopeTargets {
        FileScopeTargets {
            scope,
            source: self.source.endpoint(scope),
            target: self.target.endpoint(scope),
        }
    }
}

#[derive(Debug, Clone)]
pub struct EnvironmentPaths {
    name: String,
    excludes: Vec<String>,
    transfer_mode: TransferMode,
    location: EnvironmentLocation,
}

impl EnvironmentPaths {
    fn from_environment(env: &ResolvedEnvironment) -> Result<Self> {
        let excludes = env
            .excludes
            .iter()
            .map(|pattern| normalize_glob(pattern))
            .collect();
        let location = match &env.kind {
            EnvironmentKind::Local { root } => EnvironmentLocation::Local(LocalPaths::build(
                &env.name,
                root,
                &env.wp_content_subdir,
            )?),
            EnvironmentKind::Ssh {
                host,
                user,
                port,
                root,
            } => EnvironmentLocation::Remote(RemotePaths::build(
                &env.name,
                root,
                user,
                host,
                *port,
                &env.wp_content_subdir,
            )?),
        };

        Ok(Self {
            name: env.name.clone(),
            excludes,
            transfer_mode: env.transfer_mode,
            location,
        })
    }

    pub fn endpoint(&self, scope: FileScope) -> RsyncEndpoint {
        let location = match &self.location {
            EnvironmentLocation::Local(paths) => {
                let path = match scope {
                    FileScope::Uploads => paths.uploads.clone(),
                    FileScope::Content => paths.wp_content.clone(),
                };
                EndpointLocation::Local { path }
            }
            EnvironmentLocation::Remote(paths) => {
                let path = match scope {
                    FileScope::Uploads => paths.uploads.clone(),
                    FileScope::Content => paths.wp_content.clone(),
                };
                EndpointLocation::Remote(RemoteEndpoint {
                    user: paths.connection.user.clone(),
                    host: paths.connection.host.clone(),
                    port: paths.connection.port,
                    path,
                })
            }
        };

        RsyncEndpoint {
            name: self.name.clone(),
            location,
            excludes: self.excludes.clone(),
            transfer_mode: self.transfer_mode,
        }
    }

    #[cfg(test)]
    fn as_location(&self) -> &EnvironmentLocation {
        &self.location
    }
}

#[derive(Debug, Clone)]
enum EnvironmentLocation {
    Local(LocalPaths),
    Remote(RemotePaths),
}

#[derive(Debug, Clone)]
struct LocalPaths {
    root: PathBuf,
    wp_content: PathBuf,
    uploads: PathBuf,
}

impl LocalPaths {
    fn build(env_name: &str, root: &Path, wp_content_subdir: &str) -> Result<Self> {
        let validated_root = ensure_local_dir(root).with_context(|| {
            format!(
                "Environment '{env_name}' root '{}' does not exist or is not a directory. Check Movefile.{env_name}.root.",
                root.display()
            )
        })?;
        let wp_content_raw =
            resolve_local_path(&validated_root, wp_content_subdir).with_context(|| {
                format!(
                    "Environment '{env_name}' wp-content path '{wp_content_subdir}' is invalid."
                )
            })?;
        let wp_content = ensure_local_dir(&wp_content_raw).with_context(|| {
            format!(
                "Environment '{env_name}' wp-content directory '{}' does not exist. Verify Movefile defaults.wp_content_subdir or {env_name}.wp_content.",
                wp_content_raw.display()
            )
        })?;
        let uploads = wp_content.join("uploads");
        Ok(Self {
            root: validated_root,
            wp_content,
            uploads,
        })
    }
}

#[derive(Debug, Clone)]
struct RemotePaths {
    connection: RemoteConnection,
    root: String,
    wp_content: String,
    uploads: String,
}

#[derive(Debug, Clone)]
struct RemoteConnection {
    user: String,
    host: String,
    port: u16,
}

impl RemotePaths {
    fn build(
        env_name: &str,
        root: &str,
        user: &str,
        host: &str,
        port: u16,
        wp_content_subdir: &str,
    ) -> Result<Self> {
        let normalized_root = normalize_remote_root(root).ok_or_else(|| {
            eyre::eyre!(
                "Environment '{env_name}' root '{root}' must be an absolute path starting with '/'."
            )
        })?;
        let wp_content =
            resolve_remote_path(&normalized_root, wp_content_subdir).ok_or_else(|| {
                eyre::eyre!(
                    "Environment '{env_name}' wp-content path '{wp_content_subdir}' must resolve to an absolute path."
                )
            })?;

        let uploads = join_remote_path(&wp_content, "uploads");

        Ok(Self {
            connection: RemoteConnection {
                user: user.to_string(),
                host: host.to_string(),
                port,
            },
            root: normalized_root,
            wp_content,
            uploads,
        })
    }
}

#[derive(Debug, Clone)]
pub struct FileScopeTargets {
    scope: FileScope,
    pub source: RsyncEndpoint,
    pub target: RsyncEndpoint,
}

impl FileScopeTargets {
    pub fn scope(&self) -> FileScope {
        self.scope
    }
}

#[derive(Debug, Clone)]
pub struct RsyncEndpoint {
    name: String,
    location: EndpointLocation,
    excludes: Vec<String>,
    transfer_mode: TransferMode,
}

impl RsyncEndpoint {
    pub fn name(&self) -> &str {
        &self.name
    }

    pub fn rsync_path(&self) -> String {
        match &self.location {
            EndpointLocation::Local { path } => ensure_trailing_slash(&path_to_string(path)),
            EndpointLocation::Remote(remote) => format!(
                "{}@{}:{}",
                remote.user,
                remote.host,
                ensure_trailing_slash(&remote.path)
            ),
        }
    }

    pub fn excludes(&self) -> &[String] {
        &self.excludes
    }

    pub fn transfer_mode(&self) -> TransferMode {
        self.transfer_mode
    }

    pub fn ssh_port(&self) -> Option<u16> {
        match &self.location {
            EndpointLocation::Remote(remote) => Some(remote.port),
            EndpointLocation::Local { .. } => None,
        }
    }

    pub fn local_path(&self) -> Option<&Path> {
        match &self.location {
            EndpointLocation::Local { path } => Some(path),
            _ => None,
        }
    }

    pub fn remote_details(&self) -> Option<(&str, &str, u16, &str)> {
        match &self.location {
            EndpointLocation::Remote(remote) => Some((
                remote.user.as_str(),
                remote.host.as_str(),
                remote.port,
                remote.path.as_str(),
            )),
            _ => None,
        }
    }
}

#[derive(Debug, Clone)]
enum EndpointLocation {
    Local { path: PathBuf },
    Remote(RemoteEndpoint),
}

#[derive(Debug, Clone)]
pub struct RemoteEndpoint {
    user: String,
    host: String,
    port: u16,
    path: String,
}

#[derive(Debug)]
pub struct StagingArea {
    label: String,
    dir: TempDir,
}

impl StagingArea {
    pub fn for_plan(plan: &OperationPlan) -> Result<Self> {
        let label = staging_prefix(plan);
        let dir = Builder::new()
            .prefix(&label)
            .tempdir()
            .wrap_err("Failed to create staging directory")?;
        Ok(Self { label, dir })
    }

    pub fn path(&self) -> &Path {
        self.dir.path()
    }

    pub fn join(&self, component: impl AsRef<Path>) -> PathBuf {
        self.dir.path().join(component)
    }

    pub fn label(&self) -> &str {
        &self.label
    }

    pub fn close(self) -> Result<()> {
        self.dir
            .close()
            .wrap_err("Failed to remove staging directory")
    }
}

pub fn resolve_file_targets(
    source: &ResolvedEnvironment,
    target: &ResolvedEnvironment,
) -> Result<FileTargets> {
    let source_paths = EnvironmentPaths::from_environment(source)?;
    let target_paths = EnvironmentPaths::from_environment(target)?;
    Ok(FileTargets {
        source: source_paths,
        target: target_paths,
    })
}

fn ensure_local_dir(path: &Path) -> Result<PathBuf> {
    if !path.exists() {
        eyre::bail!("path '{}' does not exist", path.display());
    }
    let metadata = fs::metadata(path)?;
    if !metadata.is_dir() {
        eyre::bail!("path '{}' is not a directory", path.display());
    }
    let canonical = fs::canonicalize(path).unwrap_or_else(|_| path.to_path_buf());
    Ok(canonical)
}

fn resolve_local_path(root: &Path, child: &str) -> Result<PathBuf> {
    if child.trim().is_empty() {
        eyre::bail!("wp-content path cannot be empty");
    }
    let child_path = PathBuf::from(child);
    let combined = if child_path.is_absolute() {
        child_path
    } else {
        root.join(child_path)
    };
    Ok(combined)
}

fn normalize_remote_root(root: &str) -> Option<String> {
    let trimmed = root.trim();
    if trimmed.is_empty() {
        return None;
    }
    if !trimmed.starts_with('/') {
        return None;
    }
    if trimmed == "/" {
        return Some("/".to_string());
    }
    let without_trailing = trimmed.trim_end_matches('/');
    Some(without_trailing.to_string())
}

fn resolve_remote_path(root: &str, child: &str) -> Option<String> {
    let normalized_child = child.trim().replace('\\', "/");
    if normalized_child.is_empty() {
        return None;
    }
    if normalized_child.starts_with('/') {
        normalize_remote_root(&normalized_child)
    } else if root == "/" {
        Some(format!("/{}", normalized_child.trim_start_matches('/')))
    } else {
        Some(format!(
            "{}/{}",
            root.trim_end_matches('/'),
            normalized_child.trim_start_matches('/')
        ))
    }
}

fn join_remote_path(base: &str, child: &str) -> String {
    if base == "/" {
        format!("/{}", child.trim_start_matches('/'))
    } else {
        format!(
            "{}/{}",
            base.trim_end_matches('/'),
            child.trim_start_matches('/')
        )
    }
}

fn normalize_glob(pattern: &str) -> String {
    let replaced = pattern.trim().replace('\\', "/");
    let mut cleaned = String::with_capacity(replaced.len());
    let mut prev_slash = false;
    for ch in replaced.chars() {
        if ch == '/' {
            if !prev_slash {
                cleaned.push(ch);
            }
            prev_slash = true;
        } else {
            cleaned.push(ch);
            prev_slash = false;
        }
    }
    cleaned
}

fn path_to_string(path: &Path) -> String {
    path.to_string_lossy().into_owned()
}

fn ensure_trailing_slash(value: &str) -> String {
    if value.ends_with('/') {
        value.to_string()
    } else {
        format!("{value}/")
    }
}

fn staging_prefix(plan: &OperationPlan) -> String {
    let dir_slug = match plan.direction {
        Direction::Push => "push",
        Direction::Pull => "pull",
    };
    let scope_slug = match plan.scope {
        SyncScope::Db => "db",
        SyncScope::Uploads => "uploads",
        SyncScope::Content => "content",
        SyncScope::All => "all",
    };
    format!(
        "movepress-{}-{}-{}-to-{}-",
        dir_slug,
        scope_slug,
        sanitize_component(&plan.source),
        sanitize_component(&plan.target)
    )
}

fn sanitize_component(component: &str) -> String {
    let mut slug = String::new();
    for ch in component.chars() {
        if ch.is_ascii_alphanumeric() {
            slug.push(ch.to_ascii_lowercase());
        } else if !slug.ends_with('-') {
            slug.push('-');
        }
        if slug.len() >= 24 {
            break;
        }
    }
    let trimmed = slug.trim_matches('-');
    if trimmed.is_empty() {
        "env".to_string()
    } else {
        trimmed.to_string()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::Movefile;
    use tempfile::tempdir;

    #[test]
    fn resolves_local_and_remote_paths() {
        let local_root = tempdir().expect("temp root");
        let wp_content = local_root.path().join("wp-content");
        let uploads = wp_content.join("uploads");
        fs::create_dir_all(&uploads).expect("create uploads");

        let movefile = Movefile::from_str(&format!(
            r#"
        version = 1

        [sync]
        exclude = ["**/.DS_Store", "**\\\\*.log"]

        [local]
        type = "local"
        root = "{}"
        db_name = "local"
        db_user = "root"
        db_password = ""
        db_host = "127.0.0.1"
        db_port = 3306

          [local.sync]
          exclude = ["cache/**"]

        [staging]
        type = "ssh"
        host = "staging.example.com"
        user = "deploy"
        port = 2222
        root = "/var/www/site"
        wp_content = "public/wp"
        db_name = "stage"
        db_user = "stage"
        db_password = ""
        db_host = "localhost"
        db_port = 3306
        "#,
            local_root.path().display(),
        ))
        .expect("parse movefile");
        let (local, staging) = movefile
            .resolve_pair("local", "staging")
            .expect("resolve envs");
        let targets = resolve_file_targets(&local, &staging).expect("resolve file targets");

        match targets.source.as_location() {
            EnvironmentLocation::Local(local_paths) => {
                assert_eq!(
                    &local_paths.root,
                    &fs::canonicalize(local_root.path()).expect("canonical root")
                );
                assert_eq!(
                    &local_paths.wp_content,
                    &fs::canonicalize(&wp_content).unwrap()
                );
                assert_eq!(
                    &local_paths.uploads,
                    &local_paths.wp_content.join("uploads")
                );
            }
            _ => panic!("expected local source paths"),
        }

        match targets.target.as_location() {
            EnvironmentLocation::Remote(remote_paths) => {
                assert_eq!(&remote_paths.root, "/var/www/site");
                assert_eq!(&remote_paths.wp_content, "/var/www/site/public/wp");
                assert_eq!(&remote_paths.uploads, "/var/www/site/public/wp/uploads");
            }
            _ => panic!("expected remote target paths"),
        }

        let source_uploads = targets.source.endpoint(FileScope::Uploads);
        assert!(source_uploads.rsync_path().ends_with("wp-content/uploads/"));
        assert_eq!(
            source_uploads.excludes(),
            &[
                "**/.DS_Store".to_string(),
                "**/*.log".to_string(),
                "cache/**".to_string()
            ]
        );
        assert!(source_uploads.ssh_port().is_none());

        let remote_uploads = targets.target.endpoint(FileScope::Uploads);
        assert_eq!(
            remote_uploads.rsync_path(),
            "deploy@staging.example.com:/var/www/site/public/wp/uploads/"
        );
        assert_eq!(remote_uploads.ssh_port(), Some(2222));
    }

    #[test]
    fn errors_when_local_wp_content_missing() {
        let local_root = tempdir().expect("temp dir");
        let movefile = Movefile::from_str(&format!(
            r#"
        version = 1

        [local]
        type = "local"
        root = "{}"
        db_name = "local"
        db_user = "root"
        db_password = ""
        db_host = "127.0.0.1"
        db_port = 3306

        [remote]
        type = "ssh"
        host = "remote.example.com"
        user = "deploy"
        root = "/var/www/site"
        db_name = "remote"
        db_user = "root"
        db_password = ""
        db_host = "localhost"
        db_port = 3306
        "#,
            local_root.path().display(),
        ))
        .expect("parse movefile");
        let (local, remote) = movefile
            .resolve_pair("local", "remote")
            .expect("resolve pair");
        let err = resolve_file_targets(&local, &remote).expect_err("missing wp-content");
        let message = format!("{err}");
        assert!(
            message.contains("wp-content"),
            "expected mention of wp-content error, got {message}"
        );
    }

    #[test]
    fn supports_wp_content_override_outside_root() {
        let base = tempdir().expect("base dir");
        let shared = base.path().join("shared/wp-overrides");
        fs::create_dir_all(shared.join("uploads")).expect("create shared directories");
        let site_root = base.path().join("site/current");
        fs::create_dir_all(&site_root).expect("create site root");

        let movefile = Movefile::from_str(&format!(
            r#"
        version = 1

        [local]
        type = "local"
        root = "{}"
        wp_content = "../../shared/wp-overrides"
        db_name = "local"
        db_user = "root"
        db_password = ""
        db_host = "127.0.0.1"
        db_port = 3306

        [remote]
        type = "ssh"
        host = "remote.example.com"
        user = "deploy"
        root = "/var/www/site"
        db_name = "remote"
        db_user = "root"
        db_password = ""
        db_host = "localhost"
        db_port = 3306
        "#,
            site_root.display(),
        ))
        .expect("parse movefile");
        let (local, remote) = movefile.resolve_pair("local", "remote").expect("envs");
        let targets = resolve_file_targets(&local, &remote).expect("resolve file targets");
        match targets.source.as_location() {
            EnvironmentLocation::Local(local_paths) => {
                assert_eq!(
                    &local_paths.wp_content,
                    &fs::canonicalize(shared).expect("canonical shared")
                );
                assert_eq!(
                    &local_paths.uploads,
                    &local_paths.wp_content.join("uploads")
                );
            }
            _ => panic!("expected local paths"),
        }
    }

    #[test]
    fn rejects_remote_root_without_leading_slash() {
        let local_root = tempdir().expect("temp dir");
        let wp_content = local_root.path().join("wp-content");
        fs::create_dir_all(wp_content.join("uploads")).expect("create local dirs");

        let movefile = Movefile::from_str(&format!(
            r#"
        version = 1

        [local]
        type = "local"
        root = "{}"
        db_name = "local"
        db_user = "root"
        db_password = ""
        db_host = "127.0.0.1"
        db_port = 3306

        [remote]
        type = "ssh"
        host = "remote.example.com"
        user = "deploy"
        root = "var/www/site"
        db_name = "remote"
        db_user = "root"
        db_password = ""
        db_host = "localhost"
        db_port = 3306
        "#,
            local_root.path().display(),
        ))
        .expect("parse movefile");
        let (local, remote) = movefile.resolve_pair("local", "remote").expect("envs");
        let err = resolve_file_targets(&local, &remote).expect_err("remote root invalid");
        assert!(
            format!("{err}").contains("absolute"),
            "expected mention of absolute path requirement"
        );
    }

    #[test]
    fn staging_area_reflects_plan_identity() {
        let plan =
            OperationPlan::for_test(Direction::Push, SyncScope::Content, "Local Dev", "Prod!");
        let staging = StagingArea::for_plan(&plan).expect("staging");
        assert!(
            staging
                .label()
                .starts_with("movepress-push-content-local-dev-to-prod"),
            "unexpected label {}",
            staging.label()
        );
        let staging_path = staging.path().to_path_buf();
        staging.close().expect("close staging");
        assert!(
            !staging_path.exists(),
            "staging directory {} should be removed",
            staging_path.display()
        );
    }
}
