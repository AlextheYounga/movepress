---
id: "007"
title: All-Scope Orchestrator
branch: feat/007-all-scope-orchestrator
status: CLOSED
blocking: synchronous
---

## Objective

- Create the orchestration layer described in **System Design › All-Operation Orchestrator** and **Implementation Blueprint › Feature 6**, ensuring the CLI can execute the `all` scope by chaining the database and file sync services with shared context.

## Scope

- Implement an `AllScopeOrchestrator` (or equivalent runner) that accepts an `OperationPlan`, resolves both environments once, and sequentially invokes `DatabaseSyncService` then `FileSyncService` while sharing derived metadata (paths, excludes, transfer modes).
- Guarantee that failures in the first stage abort the second, propagating structured errors so users understand which stage failed.
- Integrate this orchestrator into the CLI dispatcher so `movepress push|pull all` performs DB sync followed by file sync, with dry-run mode surfacing both planned sections.
- Ensure verbose/dry-run logs demonstrate stage ordering and reuse of computed context to reduce redundant Movefile parsing.
- Add integration tests (preferably via trycmd) covering `all` dry-run output, success ordering, and failure propagation when either stage errors.

## Dev Notes
- Added an `AllScopeOrchestrator` that shares resolved environments/file targets, logs stage progress, and enforces DB-before-file execution with explicit skip messaging on failures.
- Updated the CLI dispatcher so `all` scope routes through the orchestrator, maps to wp-content file targets, and surfaces proper dry-run + execution output.
- Extended test fixtures (rsync/mysqldump stubs) plus new trycmd cases for `all` dry-run, success, and failure propagation (DB + file stages).
- Added complementary `movepress pull all` trycmd coverage (dry-run, success, DB/files failures) to exercise the orchestrator for both directions.

## Out of Scope

- Implementing the underlying DB or file services themselves (Tickets 005–006).
- Additional safety/logging refinements (Tickets 008–009).

## Requirements

- Running `movepress ... all` first enforces DB confirmation gates, then continues to file sync only on success, matching the ordering rationale in the outline.
- Dry-run output and verbose logging clearly delineate DB vs file stages and note when commands are skipped because of upstream failures.
- Shared resolution ensures env metadata (paths, excludes, transfer mode) is computed once and reused, preventing double validation work.
- Tests verify orchestrator behavior for push and pull directions, as well as behavior when dry-run is set or when DB stage fails.

## QA Notes

- `cargo test` (includes trycmd) now passes and `tests/cmd/cli.trycmd` covers `movepress push|pull all` dry-run, success, and failure propagation scenarios, satisfying the ticket’s orchestration coverage requirements.
