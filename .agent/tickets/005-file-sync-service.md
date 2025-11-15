---
id: "005"
title: File Sync Service
branch: feat/005-file-sync-service
status: QA_REVIEW
blocking: synchronous
---

## Objective

- Deliver the rsync-driven file sync engine from **System Design › File Sync Service** and **Implementation Blueprint › Feature 4**, wiring it into the CLI so `push|pull uploads|content` operations perform real transfers with dry-run/verbose support.

## Scope

- Implement a `FileSyncService` that consumes an `OperationPlan`, resolved env metadata, and path/exclude helpers (Ticket 004) to build rsync argv for each direction (local→ssh, ssh→local, local→local).
- Apply transfer-mode specific flags (`-z` for compressed, omit for direct), merged exclude files, and `--dry-run`/`--stats` toggles; parse rsync output to emit summary stats for verbose/dry-run reporting.
- Use the command executor (Ticket 003) for invocation, surfacing actionable errors when rsync is missing or returns non-zero exit codes; redact sensitive paths in logs.
- Integrate the service into the CLI dispatcher so `movepress push|pull uploads` and `content` scopes trigger actual rsync runs (or descriptions in dry-run mode).
- Add unit tests for argv construction/exclude handling plus integration-style tests gated/skipped unless rsync is available; update trycmd snapshots for new verbose/dry-run messaging.

## Out of Scope

- Database transfers (Ticket 006) or combined `all` runs (Ticket 007).
- Environment parsing or confirmation UX already handled in Tickets 001–002.

## Requirements

- File scope commands honor dry-run by emitting planned rsync command + stats without touching the filesystem and execute rsync otherwise.
- Transfer direction respects the requested push/pull semantics, ensuring sources/destinations line up with Movefile env definitions and excludes are enforced.
- Errors clearly differentiate between missing rsync, authentication failures, or rsync exit codes, and instruct users how to install required tools per platform.
- Tests cover at least: include/exclude assembly, compressed vs direct flags, dry-run output, and CLI invocation path for uploads/content scopes.

## Dev Notes
- Added the async `FileSyncService` (rsync argv builder, exclude file handling, stats parsing, dry-run remote preview shortcut) plus CLI wiring so uploads/content scopes now invoke rsync or emit a preview section in dry-run mode.
- Extended CLI output helpers to show sanitized paths and command previews, integrate verbose output handling, and added redaction helpers for local paths.
- Added unit/integration tests for the service (argv composition, compression/exclude handling, dry-run remote skip, rsync execution) and refreshed trycmd snapshots for the new preview block.
