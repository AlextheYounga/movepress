---
id: "007"
title: All Operation Orchestrator & Observability
branch: feat/007-all-operation-orchestrator
status: OPEN
blocking: synchronous
---

## Objective

- Create the high-level push/pull orchestrator that sequences file + DB pipelines, enforces dry-run/confirmation policies, and emits structured logs/metrics aligning with **Implementation Blueprint › Feature: `all` Operation Orchestration & Observability** and **System Design › Components 9-10**.

## Scope

- Compose Tickets 004-006 within command handlers from Ticket 001 to execute `db|uploads|content|all` operations, ensuring `all` runs file sync before DB sync for safer rollback.
- Implement standardized progress/logging events (counts, bytes, timings, command traces) and wire them to verbose/dry-run settings.
- Ensure failures short-circuit subsequent stages, surfacing clear exit codes/messages back to the CLI per Observability notes.
- Capture metrics summaries for completion (e.g., files transferred, DB size) for eventual JSON/log output compatibility.

## Out of Scope

- Low-level implementations of manifests, transfers, or DB pipelines (covered by preceding tickets).
- CI/packaging considerations beyond logging/exit codes.

## Requirements

- `movepress push all <src> <dst> --dry-run` produces a combined summary describing file and DB operations drawn from downstream components without performing work.
- Real runs execute file sync first, proceed to DB sync only on success, and stop immediately with a clear error on any failure (including direct `db` or `uploads` invocations).
- Verbose logs show command traces (sanitized) and structured metrics aligned with Observability guidance; non-verbose mode emits concise progress.
- Tests or integration harness validate sequencing, error propagation, and logging output formats (snapshot or structured assertions).
