---
id: "003"
title: Command Executor Abstraction
branch: feat/003-command-executor
status: CLOSED
blocking: synchronous
---

## Objective

- Implement the reusable command execution layer described in **System Design › Command Execution Abstraction** and **Implementation Blueprint › Feature 3**, enabling consistent local and SSH command launches for downstream sync services.

## Scope

- Define a `CommandExecutor` trait plus supporting `CommandSpec`/`ExecResult` structs that capture argv, working dir, env vars, ssh metadata, and stdout/stderr handling.
- Provide concrete executors for local commands (wrapping `tokio::process::Command`) and SSH commands (wrapping ssh invocation with argv escaping), including streaming pipeline support where needed.
- Ensure verbose mode hooks expose constructed command lines while redacting sensitive env values, and errors include exit status + stderr context using the color-eyre stack already in place.
- Add focused tests (unit or lightweight integration via harmless commands like `echo`, `true`, `false`) that exercise happy path, failure path, environment overrides, and SSH argument formation.

## Out of Scope

- Rsync/mysqldump/mysql specific command building (Tickets 005–006).
- CLI flag parsing or plan selection logic (Ticket 001 already covers this).

## Requirements

- Trait consumers can execute commands asynchronously, inspect exit codes/stdout/stderr, and opt into streaming stdin/stdout pipes where needed for DB transfer pipelines.
- SSH execution supports user/host/port overrides per environment definition and cleanly reports connection failures.
- Verbose logging is centralized in the executor layer with redaction helpers so downstream services inherit consistent visibility.
- Tests document expected argv formation and error propagation for both local and SSH variants.

## Dev Notes

- Added the reusable `command` module exposing `CommandSpec`, `ExecResult`, `CommandChild`, and the `CommandExecutor` trait with `LocalCommandExecutor` and `SshCommandExecutor` concrete impls over `tokio::process::Command`.
- Command specs capture argv, working directory, env vars (with opt-in redaction), SSH metadata, and stdio choices; executors centralize verbose logging and build ssh command lines with safe shell escaping.
- `ExecResult` keeps stdout/stderr/status plus helpers for checked execution, and `CommandChild` enables streaming by exposing pipe handles before awaiting completion.
- Added async unit tests covering success/failure cases, environment overrides, streaming pipes, log redaction, and SSH argv formation; all existing CLI tests continue to pass under `cargo test`.
