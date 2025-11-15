use crate::logging::VerboseLogger;
use color_eyre::eyre::{self, WrapErr};
use std::fs::File;
use std::path::{Path, PathBuf};
use tempfile::NamedTempFile;

#[derive(Debug)]
pub(crate) struct TrackedTempFile {
    label: String,
    file: Option<NamedTempFile>,
    logger: VerboseLogger,
}

impl TrackedTempFile {
    pub(crate) fn new(
        label: impl Into<String>,
        handle: NamedTempFile,
        logger: VerboseLogger,
    ) -> Self {
        let label_string = label.into();
        let path = handle.path().to_path_buf();
        logger.log_temp_creation(&label_string, &path);
        Self {
            label: label_string,
            file: Some(handle),
            logger,
        }
    }

    pub(crate) fn path(&self) -> &Path {
        self.file
            .as_ref()
            .expect("tracked temp file missing handle")
            .path()
    }

    pub(crate) fn reopen(&self) -> eyre::Result<File> {
        let handle = self
            .file
            .as_ref()
            .ok_or_else(|| eyre::eyre!("temporary file handle has been closed"))?;
        handle
            .reopen()
            .wrap_err("failed to reopen tracked temporary file")
    }
}

impl Drop for TrackedTempFile {
    fn drop(&mut self) {
        if let Some(handle) = self.file.take() {
            let path = PathBuf::from(handle.path());
            match handle.close() {
                Ok(()) => self.logger.log_temp_cleanup_ok(&self.label, &path),
                Err(err) => self
                    .logger
                    .log_temp_cleanup_failed(&self.label, &path, &err),
            }
        }
    }
}
