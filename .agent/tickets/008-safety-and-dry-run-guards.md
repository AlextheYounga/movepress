---
id: "008"
title: Safety & Dry-Run Guards
branch: feat/008-safety-dry-run-guards
status: OPEN
blocking: synchronous
---

## Objective

- Reinforce the safeguards detailed in **Implementation Blueprint › Feature 7** and **Verification Checklist › Safety and observability**, ensuring destructive operations only run with explicit confirmation and that dry-run mode becomes a first-class execution path across services.

## Scope

- Audit the new `FileSyncService`, `DatabaseSyncService`, and `AllScopeOrchestrator` entry points to confirm they short-circuit when `--dry-run` is set, producing consistent summaries without side effects.
- Extend confirmation gating so any operation that writes to a production environment requires an interactive approval even if invoked via orchestrator, and fails cleanly in non-interactive contexts unless explicitly allowed.
- Add guardrails that detect attempts to run `--yes` against production DB targets, ensuring the CLI refuses these runs per project philosophy.
- Document and emit user-facing guidance (e.g., "rerun with --dry-run first", "set MOVEPRESS_ALLOW_PROD" if such env exists) so operators understand why actions were blocked.
- Enhance trycmd/unit tests to cover: dry-run on each scope, refusal to run production DB writes without confirmation, and safe failure messaging when stdin is not a tty.

## Out of Scope

- Core functionality of file/db/all services (Tickets 005–007) beyond the safety hooks.
- Logging/diagnostics improvements unrelated to safety (Ticket 009).

## Requirements

- Dry-run execution path never spawns external commands and still returns success with detailed plan output for db/uploads/content/all scopes.
- Any attempt to bypass production confirmation (e.g., `--yes`) is rejected with a descriptive error and exit code >0.
- Blocking behavior propagates from orchestrators back to CLI exit codes so scripts can detect when a run was prevented.
- Tests capture these guardrails to prevent regressions as services evolve.
