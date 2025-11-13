---
id: "001"
title: CLI & Global UX
branch: feat/001-cli-global-ux
status: OPEN
blocking: synchronous
---

## Objective

- Deliver the core clap-driven CLI experience that enforces `push|pull <what> <src> <dst>` syntax, supports dry-run/verbose/global flags, and surfaces confirmation prompts for destructive DB actions per **Implementation Blueprint › Feature: CLI & Global UX**.

## Scope

- Define command/flag structure covering scopes `db|uploads|content|all`, config path overrides, verbosity, and dry-run switches.
- Render dry-run summaries describing upcoming file and DB operations without executing them.
- Enforce confirmation prompts (yes/no) for DB write operations unless explicitly bypassed for non-production targets.
- Provide coherent error handling for invalid argument combinations drawn from **System Design › Components 1 & 9** and **Operational Notes › Observability**.

## Out of Scope

- Reading `Movefile.toml` contents beyond validating the configured path (covered by Ticket 002).
- Executing sync operations or invoking SSH/DB/file transfer work, which belong to downstream tickets.

## Requirements

- `movepress --help` lists commands, positional args, scopes, and global flags described above, with clap validation covering invalid combinations.
- Dry-run flag produces a structured summary (e.g., impending file counts, DB plan) without touching remote/local resources.
- Attempting a DB push/pull without confirmation results in an interactive prompt or fails in non-interactive contexts with clear messaging.
- CLI errors return non-zero exit codes and render user-friendly diagnostics for missing/invalid args, flag conflicts, or unsupported scopes.
