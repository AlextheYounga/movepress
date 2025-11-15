use std::path::Path;

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
                path.display()
            );
        }
    }

    pub fn log_temp_cleanup_ok(&self, label: &str, path: &Path) {
        if self.enabled {
            eprintln!("[tmp] Removed {} at {}.", label, path.display());
        }
    }

    pub fn log_temp_cleanup_failed(&self, label: &str, path: &Path, error: &std::io::Error) {
        if self.enabled {
            eprintln!(
                "[tmp] Failed to remove {} at {}: {}. File left on disk for inspection.",
                label,
                path.display(),
                error
            );
        }
    }
}
