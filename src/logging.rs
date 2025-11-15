use std::path::Path;
use std::sync::OnceLock;

#[derive(Clone, Debug, Default)]
pub struct VerboseLogger {
    enabled: bool,
}

impl VerboseLogger {
    pub fn new(enabled: bool) -> Self {
        Self { enabled }
    }

    pub fn enabled(&self) -> bool {
        self.enabled
    }

    pub fn log(&self, message: impl AsRef<str>) {
        if self.enabled {
            eprintln!("{}", message.as_ref());
        }
    }

    pub fn log_temp_creation(&self, label: &str, path: &Path) {
        if self.enabled {
            eprintln!(
                "[tmp] Created {} at {} (will be cleaned automatically).",
                label,
                render_temp_path(path)
            );
        }
    }

    pub fn log_temp_cleanup_ok(&self, label: &str, path: &Path) {
        if self.enabled {
            eprintln!("[tmp] Removed {} at {}.", label, render_temp_path(path));
        }
    }

    pub fn log_temp_cleanup_failed(&self, label: &str, path: &Path, error: &std::io::Error) {
        if self.enabled {
            eprintln!(
                "[tmp] Failed to remove {} at {}: {}. File left on disk for inspection.",
                label,
                render_temp_path(path),
                error
            );
        }
    }
}

fn render_temp_path(path: &Path) -> String {
    if mask_temp_paths() {
        "[tempfile]".to_string()
    } else {
        path.display().to_string()
    }
}

fn mask_temp_paths() -> bool {
    static FLAG: OnceLock<bool> = OnceLock::new();
    *FLAG.get_or_init(|| {
        matches!(
            std::env::var("MOVEPRESS_MASK_TEMP")
                .unwrap_or_default()
                .trim()
                .to_ascii_lowercase()
                .as_str(),
            "1" | "true" | "yes" | "on"
        )
    })
}
