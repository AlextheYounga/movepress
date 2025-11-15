---
id: "006"
title: Database Sync Service
branch: feat/006-database-sync-service
status: QA_REVIEW
blocking: synchronous
---

## Objective

- Implement the mysqldump/mysql-based database pipeline outlined in **System Design › Database Sync Service** and **Implementation Blueprint › Feature 5**, enabling `push|pull db` operations (and future `all`) with confirmation and dry-run semantics intact.

## Scope

- Build a `DatabaseSyncService` that orchestrates mysqldump on the source env, optional gzip compression, transport over SSH when required, and mysql import on the destination; support local↔ssh, ssh↔local, and ssh↔ssh (streamed through the operator host).
- Integrate WP-CLI search-replace invocation on the destination when configured in the Movefile, and expose hooks for specifying php/wp-cli paths per env.
- Use scoped temp files when streaming is not viable, ensuring they are created under secure OS temp dirs and cleaned up via RAII guards.
- Respect dry-run mode by logging planned dump/import commands without executing them, and surface confirmation prompts (from Ticket 001) before destructive imports run.
- Wire the service into the CLI dispatcher so `movepress push|pull db` executes real transfers post-confirmation; update trycmd/unit tests to cover new messaging and failure cases.

## Out of Scope

- Rsync operations (`uploads|content`) and combined orchestration (Tickets 005 & 007).
- Low-level Movefile parsing or path calculations (Tickets 002 & 004).

## Requirements

- Pipelines cover all direction permutations, correctly arranging ssh/mysqldump/mysql/gzip commands and passing credentials/env vars securely.
- Dry-run output enumerates each stage (dump, transport, import, optional wp-cli) with enough detail for operators to verify without executing.
- Errors distinguish between missing binaries, command failures, authentication issues, or WP-CLI absence, and provide remediation hints per **System Design › Logging & Error Handling**.
- Tests exercise command/spec construction for each direction, WP-CLI invocation toggles, and temp-file cleanup logic; trycmd coverage ensures CLI flow enforces confirmations and reports dry-run plans.

## QA Notes

- The new `DatabaseSyncService` implementation always streams mysqldump → filters → mysql (see `src/db_sync.rs:28-150`) and never creates or manages scoped temp files. The ticket explicitly requires a temp-file fallback “when streaming is not viable” and RAII cleanup, so scenarios that need staging to disk currently have no path to run successfully.
- Unit coverage for the database pipeline is limited to two happy-path plan assertions (`src/db_sync.rs:732-760`). There are no tests for the required transport permutations (local↔ssh, ssh↔local, ssh↔ssh), WP-CLI on/off toggles, or any temp-file cleanup logic, even though those were explicit acceptance criteria. This gap means regressions in most permutations would go unnoticed.

## Dev Notes

- Added temp-file staging fallback for remote→remote flows and made the service record staging metadata for reporting, while keeping streaming paths for other directions.
- Refactored the pipeline runner to support both streaming and staged execution, introduced RAII-managed dump files, and surfaced staging info in CLI output plus trycmd snapshots.
- Expanded database unit tests to cover all transport permutations, WP-CLI enable/disable reasons, and temp-file cleanup.
