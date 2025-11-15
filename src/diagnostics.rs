use color_eyre::eyre::{self, Result};
use std::collections::BTreeSet;
use std::env;
use std::io;
use std::path::{Path, PathBuf};
use which::which;

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub(crate) enum Dependency {
    Rsync,
    Ssh,
    Mysqldump,
    Mysql,
    Gzip,
    WpCli,
}

#[derive(Debug, Clone)]
pub(crate) enum WpCliRequirement {
    Command { launcher: String },
    PhpWrapper { php: String, script: String },
}

pub(crate) fn ensure_dependencies<I>(deps: I) -> Result<()>
where
    I: IntoIterator<Item = Dependency>,
{
    let mut requested = BTreeSet::new();
    for dep in deps {
        requested.insert(dep);
    }

    let missing: Vec<_> = requested
        .into_iter()
        .filter(|dep| !dependency_present(*dep))
        .collect();

    if missing.is_empty() {
        return Ok(());
    }

    let mut message = String::from("Required tools are missing:\n");
    for dep in missing {
        message.push_str(&dep.render_hint("not found on PATH."));
        message.push('\n');
    }
    Err(eyre::eyre!(message.trim_end().to_string()))
}

pub(crate) fn ensure_wp_cli(requirement: &WpCliRequirement) -> Result<()> {
    match requirement {
        WpCliRequirement::Command { launcher } => {
            if command_exists(launcher) {
                Ok(())
            } else {
                Err(Dependency::WpCli
                    .missing_error_with_detail(&format!("WP-CLI binary '{launcher}' is missing.")))
            }
        }
        WpCliRequirement::PhpWrapper { php, script } => {
            if !command_exists(php) {
                return Err(Dependency::WpCli.missing_error_with_detail(&format!(
                    "PHP launcher '{php}' required for WP-CLI is missing."
                )));
            }
            if !command_exists(script) {
                return Err(Dependency::WpCli.missing_error_with_detail(&format!(
                    "WP-CLI script '{script}' was not found."
                )));
            }
            Ok(())
        }
    }
}

pub(crate) fn map_spawn_error(err: eyre::Report, dependency: Dependency) -> eyre::Report {
    if err.chain().any(|cause| {
        cause
            .downcast_ref::<io::Error>()
            .is_some_and(|io_err| io_err.kind() == io::ErrorKind::NotFound)
    }) {
        dependency.missing_error()
    } else {
        err
    }
}

impl Dependency {
    fn display_name(&self) -> &'static str {
        match self {
            Dependency::Rsync => "rsync",
            Dependency::Ssh => "ssh",
            Dependency::Mysqldump => "mysqldump",
            Dependency::Mysql => "mysql",
            Dependency::Gzip => "gzip",
            Dependency::WpCli => "wp-cli",
        }
    }

    fn binary_name(&self) -> &'static str {
        match self {
            Dependency::Rsync => "rsync",
            Dependency::Ssh => "ssh",
            Dependency::Mysqldump => "mysqldump",
            Dependency::Mysql => "mysql",
            Dependency::Gzip => "gzip",
            Dependency::WpCli => "wp",
        }
    }

    fn install_hint(&self) -> InstallHint {
        match self {
            Dependency::Rsync => InstallHint {
                macos: "brew install rsync (or use the system copy).",
                linux: "apt install rsync or yum install rsync.",
                windows: "Install via WSL (Ubuntu: apt install rsync) or msys2 `pacman -S rsync`.",
            },
            Dependency::Ssh => InstallHint {
                macos: "OpenSSH ships with macOS; reinstall via `xcode-select --install` or `brew install openssh`.",
                linux: "apt install openssh-client or yum install openssh-clients.",
                windows: "Enable the built-in OpenSSH Client (Apps & Features) or use WSL/msys2 `pacman -S openssh`.",
            },
            Dependency::Mysqldump | Dependency::Mysql => InstallHint {
                macos: "brew install mysql-client and add $(brew --prefix mysql-client)/bin to PATH.",
                linux: "apt install mysql-client or yum install mysql.",
                windows: "Install MySQL Shell/Client via the MySQL Installer or run via WSL (apt install mysql-client).",
            },
            Dependency::Gzip => InstallHint {
                macos: "gzip ships with macOS; reinstall via `brew install gzip` if missing.",
                linux: "apt install gzip or yum install gzip.",
                windows: "Use WSL (apt install gzip) or msys2 `pacman -S gzip`.",
            },
            Dependency::WpCli => InstallHint {
                macos: "brew install wp-cli or download wp-cli.phar and place it in /usr/local/bin.",
                linux: "curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp.",
                windows: "Install via WSL (Ubuntu apt install php wget && curl the phar) or use msys2/PHP and place wp-cli.phar on PATH.",
            },
        }
    }

    fn render_hint(&self, detail: &str) -> String {
        let hint = self.install_hint();
        format!(
            "- {}: {}\n    macOS  : {}\n    Linux  : {}\n    Windows: {}",
            self.display_name(),
            detail,
            hint.macos,
            hint.linux,
            hint.windows
        )
    }

    pub(crate) fn missing_error(&self) -> eyre::Report {
        self.missing_error_with_detail("not found on PATH.")
    }

    pub(crate) fn missing_error_with_detail(&self, detail: &str) -> eyre::Report {
        eyre::eyre!(self.render_hint(detail))
    }

    pub(crate) fn from_program_hint(program: &str) -> Option<Self> {
        match program {
            "rsync" => Some(Dependency::Rsync),
            "ssh" => Some(Dependency::Ssh),
            "mysqldump" => Some(Dependency::Mysqldump),
            "mysql" => Some(Dependency::Mysql),
            "gzip" => Some(Dependency::Gzip),
            "wp" => Some(Dependency::WpCli),
            _ => None,
        }
    }
}

fn dependency_present(dep: Dependency) -> bool {
    match dep {
        Dependency::WpCli => command_exists(dep.binary_name()),
        _ => which(dep.binary_name()).is_ok(),
    }
}

fn command_exists(candidate: &str) -> bool {
    if looks_like_path(candidate) {
        expand_path(candidate).map(|path| path.exists()).unwrap_or(false)
    } else {
        which(candidate).is_ok()
    }
}

fn looks_like_path(candidate: &str) -> bool {
    candidate.contains('/')
        || candidate.contains('\\')
        || (cfg!(windows) && candidate.contains(':'))
}

fn expand_path(candidate: &str) -> Option<PathBuf> {
    if let Some(stripped) = candidate.strip_prefix("~/") {
        home_dir().map(|home| home.join(stripped))
    } else if let Some(stripped) = candidate.strip_prefix("~\\") {
        home_dir().map(|home| home.join(stripped))
    } else {
        Some(Path::new(candidate).to_path_buf())
    }
}

fn home_dir() -> Option<PathBuf> {
    env::var("HOME")
        .map(PathBuf::from)
        .or_else(|_| env::var("USERPROFILE").map(PathBuf::from))
        .ok()
}

struct InstallHint {
    macos: &'static str,
    linux: &'static str,
    windows: &'static str,
}
