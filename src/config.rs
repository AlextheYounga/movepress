use std::collections::BTreeMap;
use std::fs;
use std::path::{Path, PathBuf};

use color_eyre::eyre::{self, Context, Result};
use serde::Deserialize;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TransferMode {
    Compressed,
    Direct,
}

#[derive(Debug, Clone)]
pub struct Movefile {
    sync: SyncSettings,
    defaults: Defaults,
    environments: BTreeMap<String, EnvironmentDefinition>,
}

impl Movefile {
    pub fn from_path(path: &Path) -> Result<Self> {
        let contents = fs::read_to_string(path).with_context(|| {
            format!(
                "Failed to read Movefile configuration from '{}'",
                path.display()
            )
        })?;
        Self::parse(&contents).with_context(|| {
            format!(
                "Movefile at '{}' is invalid. See the above errors for details.",
                path.display()
            )
        })
    }

    #[cfg(test)]
    pub(crate) fn from_str(contents: &str) -> Result<Self> {
        Self::parse(contents)
    }

    fn parse(contents: &str) -> Result<Self> {
        let raw: RawMovefile =
            toml::from_str(contents).wrap_err("Movefile could not be parsed as valid TOML data")?;
        if raw.version != 1 {
            eyre::bail!(
                "Unsupported Movefile version '{}'. Only version 1 is supported.",
                raw.version
            );
        }

        if raw.environments.is_empty() {
            eyre::bail!("Movefile must define at least one environment section (e.g. [local]).");
        }

        let sync = SyncSettings::try_from(raw.sync)?;
        let defaults = Defaults::from(raw.defaults);
        let mut environments = BTreeMap::new();
        for (name, env) in raw.environments {
            let definition = EnvironmentDefinition::try_from_raw(name.clone(), env)?;
            environments.insert(name, definition);
        }

        Ok(Self {
            sync,
            defaults,
            environments,
        })
    }

    pub fn resolve_environment(&self, name: &str) -> Result<ResolvedEnvironment> {
        let env = self.environments.get(name).ok_or_else(|| {
            if self.environments.is_empty() {
                eyre::eyre!("Environment '{name}' is not defined.")
            } else {
                let available = self
                    .environments
                    .keys()
                    .map(|env_name| format!("'{env_name}'"))
                    .collect::<Vec<_>>()
                    .join(", ");
                eyre::eyre!(
                    "Environment '{name}' is not defined. Available environments: {available}"
                )
            }
        })?;

        let mut excludes = self.sync.exclude.clone();
        excludes.extend(env.excludes.clone());
        let wp_content_subdir = env
            .wp_content_override
            .clone()
            .unwrap_or_else(|| self.defaults.wp_content_subdir.clone());

        Ok(ResolvedEnvironment {
            name: name.to_string(),
            kind: env.kind.clone(),
            wp_content_subdir,
            excludes,
            transfer_mode: self.sync.default_transfer_mode,
            db: env.db.clone(),
            php: env.php.clone(),
            wp_cli: env.wp_cli.clone(),
        })
    }

    pub fn resolve_pair(
        &self,
        source: &str,
        target: &str,
    ) -> Result<(ResolvedEnvironment, ResolvedEnvironment)> {
        let source_env = self.resolve_environment(source)?;
        let target_env = self.resolve_environment(target)?;
        Ok((source_env, target_env))
    }
}

#[derive(Debug, Clone)]
struct SyncSettings {
    default_transfer_mode: TransferMode,
    exclude: Vec<String>,
}

impl SyncSettings {
    fn try_from(raw: RawSync) -> Result<Self> {
        let mode = match raw.default_transfer_mode {
            Some(value) => TransferMode::parse(&value).ok_or_else(|| {
                eyre::eyre!("Invalid transfer mode '{value}'. Expected 'compressed' or 'direct'.")
            })?,
            None => TransferMode::Compressed,
        };
        Ok(Self {
            default_transfer_mode: mode,
            exclude: raw.exclude,
        })
    }
}

#[derive(Debug, Clone)]
struct Defaults {
    wp_content_subdir: String,
}

impl Defaults {
    fn from(raw: RawDefaults) -> Self {
        let wp_content_subdir = raw
            .wp_content_subdir
            .unwrap_or_else(|| "wp-content".to_string());
        Self { wp_content_subdir }
    }
}

#[derive(Debug, Clone)]
struct EnvironmentDefinition {
    kind: EnvironmentKind,
    wp_content_override: Option<String>,
    excludes: Vec<String>,
    db: DatabaseConfig,
    php: Option<String>,
    wp_cli: Option<String>,
}

impl EnvironmentDefinition {
    fn try_from_raw(name: String, raw: RawEnvironment) -> Result<Self> {
        let RawEnvironment {
            kind,
            root,
            wp_content,
            host,
            user,
            port,
            db_name,
            db_user,
            db_password,
            db_host,
            db_port,
            php,
            wp_cli,
            sync,
        } = raw;

        let db = DatabaseConfig::new(&name, db_name, db_user, db_password, db_host, db_port)?;

        let kind = match kind {
            RawEnvironmentKind::Local => {
                let root = root.ok_or_else(|| missing_field(&name, "root"))?;
                EnvironmentKind::Local {
                    root: PathBuf::from(root),
                }
            }
            RawEnvironmentKind::Ssh => {
                let root = root.ok_or_else(|| missing_field(&name, "root"))?;
                let host = host.ok_or_else(|| missing_field(&name, "host"))?;
                let user = user.ok_or_else(|| missing_field(&name, "user"))?;
                let port = port.unwrap_or(22);
                EnvironmentKind::Ssh {
                    host,
                    user,
                    port,
                    root,
                }
            }
        };

        Ok(Self {
            kind,
            wp_content_override: wp_content,
            excludes: sync.exclude,
            db,
            php,
            wp_cli,
        })
    }
}

fn missing_field(env: &str, field: &str) -> eyre::Report {
    eyre::eyre!("Environment '{env}' is missing required field '{field}'.")
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DatabaseConfig {
    pub name: String,
    pub user: String,
    pub password: String,
    pub host: String,
    pub port: u16,
}

impl DatabaseConfig {
    fn new(
        env_name: &str,
        name: Option<String>,
        user: Option<String>,
        password: Option<String>,
        host: Option<String>,
        port: Option<u16>,
    ) -> Result<Self> {
        Ok(Self {
            name: name.ok_or_else(|| missing_field(env_name, "db_name"))?,
            user: user.ok_or_else(|| missing_field(env_name, "db_user"))?,
            password: password.unwrap_or_default(),
            host: host.ok_or_else(|| missing_field(env_name, "db_host"))?,
            port: port.unwrap_or(3306),
        })
    }
}

#[allow(dead_code)]
#[derive(Debug, Clone)]
pub enum EnvironmentKind {
    Local {
        root: PathBuf,
    },
    Ssh {
        host: String,
        user: String,
        port: u16,
        root: String,
    },
}

#[allow(dead_code)]
#[derive(Debug, Clone)]
pub struct ResolvedEnvironment {
    pub name: String,
    pub kind: EnvironmentKind,
    pub wp_content_subdir: String,
    pub excludes: Vec<String>,
    pub transfer_mode: TransferMode,
    pub db: DatabaseConfig,
    pub php: Option<String>,
    pub wp_cli: Option<String>,
}

#[derive(Debug, Deserialize)]
struct RawMovefile {
    version: u32,
    #[serde(default)]
    sync: RawSync,
    #[serde(default)]
    defaults: RawDefaults,
    #[serde(flatten)]
    environments: BTreeMap<String, RawEnvironment>,
}

#[derive(Debug, Deserialize, Default)]
struct RawSync {
    default_transfer_mode: Option<String>,
    #[serde(default)]
    exclude: Vec<String>,
}

#[derive(Debug, Deserialize, Default)]
struct RawDefaults {
    wp_content_subdir: Option<String>,
}

#[derive(Debug, Deserialize)]
struct RawEnvironment {
    #[serde(rename = "type")]
    kind: RawEnvironmentKind,
    root: Option<String>,
    wp_content: Option<String>,
    host: Option<String>,
    user: Option<String>,
    port: Option<u16>,
    db_name: Option<String>,
    db_user: Option<String>,
    db_password: Option<String>,
    db_host: Option<String>,
    db_port: Option<u16>,
    php: Option<String>,
    wp_cli: Option<String>,
    #[serde(default)]
    sync: RawEnvironmentSync,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "lowercase")]
enum RawEnvironmentKind {
    Local,
    Ssh,
}

#[derive(Debug, Deserialize, Default)]
struct RawEnvironmentSync {
    #[serde(default)]
    exclude: Vec<String>,
}

impl TransferMode {
    fn parse(value: &str) -> Option<Self> {
        match value.trim().to_ascii_lowercase().as_str() {
            "compressed" => Some(Self::Compressed),
            "direct" => Some(Self::Direct),
            _ => None,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;

    #[test]
    fn parses_movefile_and_merges_excludes() {
        let contents = r#"
        version = 1

        [sync]
        default_transfer_mode = "direct"
        exclude = ["global"]

        [defaults]
        wp_content_subdir = "content"

        [local]
        type = "local"
        root = "/sites/local"
        db_name = "local_db"
        db_user = "root"
        db_password = ""
        db_host = "127.0.0.1"
        db_port = 3307

          [local.sync]
          exclude = ["local-only"]

        [staging]
        type = "ssh"
        host = "staging.example.com"
        user = "deploy"
        port = 2222
        root = "/var/www/site"
        wp_content = "public/wp"
        php = "/usr/bin/php81"
        wp_cli = "/usr/local/bin/wp"
        db_name = "staging_db"
        db_user = "stage"
        db_password = "secret"
        db_host = "localhost"
        db_port = 3306

          [staging.sync]
          exclude = ["staging-only"]
        "#;

        let movefile = Movefile::from_str(contents).expect("parses movefile");
        let (local, staging) = movefile
            .resolve_pair("local", "staging")
            .expect("resolves env pair");

        assert_eq!(local.name, "local");
        match &local.kind {
            EnvironmentKind::Local { root } => {
                assert_eq!(root, &PathBuf::from("/sites/local"));
            }
            other => panic!("expected local environment, got {other:?}"),
        }
        assert_eq!(local.wp_content_subdir, "content");
        assert_eq!(local.transfer_mode, TransferMode::Direct);
        assert_eq!(
            local.excludes,
            vec!["global".to_string(), "local-only".to_string()]
        );
        assert_eq!(local.db.port, 3307);

        assert_eq!(staging.name, "staging");
        match &staging.kind {
            EnvironmentKind::Ssh {
                host,
                user,
                port,
                root,
            } => {
                assert_eq!(host, "staging.example.com");
                assert_eq!(user, "deploy");
                assert_eq!(*port, 2222);
                assert_eq!(root, "/var/www/site");
            }
            other => panic!("expected ssh environment, got {other:?}"),
        }
        assert_eq!(staging.wp_content_subdir, "public/wp");
        assert_eq!(
            staging.excludes,
            vec!["global".to_string(), "staging-only".to_string()]
        );
        assert_eq!(staging.db.name, "staging_db");
        assert_eq!(staging.transfer_mode, TransferMode::Direct);
        assert_eq!(staging.php.as_deref(), Some("/usr/bin/php81"));
        assert_eq!(staging.wp_cli.as_deref(), Some("/usr/local/bin/wp"));
    }

    #[test]
    fn rejects_unknown_environment_names() {
        let contents = r#"
        version = 1

        [local]
        type = "local"
        root = "/sites/local"
        db_name = "local_db"
        db_user = "root"
        db_password = ""
        db_host = "127.0.0.1"
        db_port = 3306
        "#;

        let movefile = Movefile::from_str(contents).expect("parses minimal movefile");
        let err = movefile.resolve_environment("missing").unwrap_err();
        let message = format!("{err}");
        assert!(
            message.contains("missing"),
            "expected error mentioning env name, got {message}"
        );
    }

    #[test]
    fn rejects_incomplete_environment_definitions() {
        let contents = r#"
        version = 1

        [staging]
        type = "ssh"
        user = "deploy"
        root = "/var/www/site"
        db_name = "wp"
        db_user = "wp"
        db_host = "localhost"
        "#;

        let err = Movefile::from_str(contents).unwrap_err();
        let message = format!("{err}");
        assert!(
            message.contains("host"),
            "expected error mentioning required field, got {message}"
        );
    }
}
