---
id: "009"
title: Logging & Dependency Diagnostics
branch: feat/009-logging-dependency-diagnostics
status: OPEN
blocking: synchronous
---

## Objective

- Provide the observability and error-reporting polish described in **System Design › Logging & Error Handling**, **Implementation Blueprint › Feature 8**, and **Operational Notes › Observability**, so operators get actionable logs and guidance when dependencies are missing.

## Scope

- Centralize verbose logging so every command executed by the executor/services emits its argv, working dir, and temp path usage when `--verbose` is enabled, with secrets redacted.
- Improve quiet-mode output to remain concise (success summaries, rsync stats, db stage completion) while still surfacing color-eyre context on errors.
- Detect missing external tools (rsync, ssh, mysqldump, mysql, gzip, wp-cli) before execution where possible and emit platform-specific installation hints (e.g., WSL/msys tips for Windows).
- Ensure temp file/directory paths are logged in verbose mode and cleaned up automatically, with failure cases noting leftover artifacts for inspection.
- Add snapshot/unit tests that lock down verbose output structure, error messages for missing binaries, and redaction logic.

## Out of Scope

- Functional behavior of file/db/all operations (Tickets 005–007) aside from their logging hooks.
- Confirmation/dry-run gating (Ticket 008).

## Requirements

- Verbose runs clearly display each external command string, environment redactions, and any temp directories used, enabling users to reproduce steps manually.
- When required binaries are absent, the CLI exits early with actionable text citing the binary name and installation hints per OS family.
- Error paths include enough context (env names, direction, stage) so users can pinpoint failures without re-running in debug builds.
- Tests guard the logging contract, ensuring future refactors do not silently drop context or leak secrets.
