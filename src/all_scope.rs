use crate::config::ResolvedEnvironment;
use crate::db_sync::{DatabaseSyncReport, DatabaseSyncService};
use crate::file_sync::{FileSyncResult, FileSyncService};
use crate::path_resolver::FileScopeTargets;
use crate::OperationPlan;
use color_eyre::eyre::{self, Context, Result};

pub struct AllScopeOrchestrator<'a> {
    plan: &'a OperationPlan,
    source_env: &'a ResolvedEnvironment,
    target_env: &'a ResolvedEnvironment,
    file_targets: FileScopeTargets,
    db_service: &'a DatabaseSyncService,
    file_service: &'a FileSyncService,
    progress: StageProgress,
}

impl<'a> AllScopeOrchestrator<'a> {
    pub fn new(
        plan: &'a OperationPlan,
        source_env: &'a ResolvedEnvironment,
        target_env: &'a ResolvedEnvironment,
        file_targets: FileScopeTargets,
        db_service: &'a DatabaseSyncService,
        file_service: &'a FileSyncService,
    ) -> Self {
        Self {
            plan,
            source_env,
            target_env,
            file_targets,
            db_service,
            file_service,
            progress: StageProgress::Ready,
        }
    }

    pub async fn run_database_stage(&mut self, dry_run: bool) -> Result<DatabaseSyncReport> {
        if !matches!(self.progress, StageProgress::Ready) {
            eyre::bail!("database stage already executed for this operation");
        }
        self.log_stage_intro(StageKind::Database, dry_run);
        let db_plan = self
            .db_service
            .build_plan(self.plan, self.source_env, self.target_env)?;
        match self.db_service.sync(db_plan, dry_run).await {
            Ok(report) => {
                self.progress = StageProgress::DatabaseComplete;
                Ok(report)
            }
            Err(err) => {
                self.log_stage_skip(StageKind::File, "the database stage failed");
                Err(err.wrap_err("all scope stage 1 (database sync) failed"))
            }
        }
    }

    pub async fn run_file_stage(&mut self, dry_run: bool) -> Result<FileSyncResult> {
        match self.progress {
            StageProgress::DatabaseComplete => {
                self.log_stage_intro(StageKind::File, dry_run);
                let report = self
                    .file_service
                    .sync(self.plan, &self.file_targets, dry_run)
                    .await
                    .wrap_err("all scope stage 2 (file sync) failed")?;
                self.progress = StageProgress::Finished;
                Ok(report)
            }
            StageProgress::Ready => {
                eyre::bail!("database stage must succeed before running file sync")
            }
            StageProgress::Finished => {
                eyre::bail!("all scope orchestrator already completed both stages")
            }
        }
    }

    pub fn file_targets(&self) -> &FileScopeTargets {
        &self.file_targets
    }

    fn log_stage_intro(&self, stage: StageKind, dry_run: bool) {
        let mode = if dry_run { "dry-run" } else { "active" };
        println!(
            "[all] Stage {}/2 ({mode}): {} for '{}' -> '{}'.",
            stage.index(),
            stage.description(),
            self.plan.source,
            self.plan.target
        );
        if matches!(stage, StageKind::Database) {
            println!(
                "[all] Reusing resolved metadata for '{}' and '{}' across all stages.",
                self.source_env.name, self.target_env.name
            );
        }
    }

    fn log_stage_skip(&self, stage: StageKind, reason: &str) {
        println!(
            "[all] Skipping stage {}/2 ({}) because {}.",
            stage.index(),
            stage.description(),
            reason
        );
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum StageProgress {
    Ready,
    DatabaseComplete,
    Finished,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum StageKind {
    Database,
    File,
}

impl StageKind {
    fn index(&self) -> usize {
        match self {
            StageKind::Database => 1,
            StageKind::File => 2,
        }
    }

    fn description(&self) -> &'static str {
        match self {
            StageKind::Database => "database sync",
            StageKind::File => "file sync",
        }
    }
}
