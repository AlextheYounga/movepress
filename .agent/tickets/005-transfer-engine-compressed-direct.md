---
id: "005"
title: Transfer Engine (Compressed & Direct)
branch: feat/005-transfer-engine
status: OPEN
blocking: synchronous
---

## Objective

- Implement the file transfer workflows that package delta sets into zip archives for single SCP hops, fall back to per-file SCP when prerequisites are missing, and clean up staging artifacts, per **Implementation Blueprint › Feature: Transfer Engine**.

## Scope

- Consume `FileDiff` data from Ticket 004 to orchestrate compressed transfers: stage files into scoped temp dirs, zip them, upload/download via Ticket 003 SSH layer, and unpack in destination roots.
- Detect remote/local zip/unzip availability, switching to direct per-file SCP when unavailable while still respecting excludes/path resolution from Ticket 002.
- Emit progress/logging metrics (counts, bytes, timing) for verbose output as described in **System Design › Components 7 & Observability Notes**.
- Handle cleanup of temp dirs and partial artifacts on both success and failure paths.

## Out of Scope

- Database dump/import flows (Ticket 006).
- Higher-level orchestration of push/pull/all commands (Ticket 007).

## Requirements

- Changed files identified by Ticket 004 are transferred exactly once per operation, preserving relative paths and permissions compatible with Linux targets.
- Zip-based path cleans up staging directories and remote archives even when failures happen mid-transfer, returning actionable errors when prerequisites missing.
- Direct SCP mode can be triggered automatically when tooling absent or via future flags, ensuring parity for push/pull directions.
- Tests (unit/integration) validate archive assembly, fallback selection, and that after transfers local/remote trees match expected contents for sample fixtures.
