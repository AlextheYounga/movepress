---
id: "007"
title: All-Scope Orchestrator
branch: feat/007-all-scope-orchestrator
status: OPEN
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

## Out of Scope

- Implementing the underlying DB or file services themselves (Tickets 005–006).
- Additional safety/logging refinements (Tickets 008–009).

## Requirements

- Running `movepress ... all` first enforces DB confirmation gates, then continues to file sync only on success, matching the ordering rationale in the outline.
- Dry-run output and verbose logging clearly delineate DB vs file stages and note when commands are skipped because of upstream failures.
- Shared resolution ensures env metadata (paths, excludes, transfer mode) is computed once and reused, preventing double validation work.
- Tests verify orchestrator behavior for push and pull directions, as well as behavior when dry-run is set or when DB stage fails.
