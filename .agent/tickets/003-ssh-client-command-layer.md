---
id: "003"
title: SSH Client & Command Layer
branch: feat/003-ssh-client-command-layer
status: OPEN
blocking: synchronous
---

## Objective

- Build an async `SshClient` abstraction plus adapters for local shell execution and remote ssh/scp invocations that leverage system binaries while exposing streaming/logging hooks per **Implementation Blueprint › Feature: SSH Client & Command Execution Layer**.

## Scope

- Define the trait(s) and data structures required to represent local vs remote command execution, timeouts, and verbose logging settings referenced in **System Design › Components 4 & 10**.
- Implement default adapters that shell out to `ssh`, `scp`, and local shell commands, inheriting user SSH config and agent auth.
- Provide error mapping utilities that capture exit status, stderr/stdout snippets, and classify failures for CLI consumption.
- Add lightweight tests/mocks to assert invoked commands and to support downstream components (manifest builder, transfer engine, DB pipeline).

## Out of Scope

- Manifest generation, diffing, or transfer orchestration (Tickets 004-005).
- Database-specific command construction (Ticket 006).

## Requirements

- Trait API supports executing arbitrary commands locally or over SSH, streaming stdout/stderr when requested, and returning structured results with exit codes.
- SCP helper handles uploads/downloads with configurable paths, ports, and compression flags, surfaced through the same abstraction.
- Verbose mode toggles ensure invoked command strings are emitted to the logging layer without leaking credentials (per Risks & Mitigations).
- Unit/integration tests (mock-based or loopback SSH) confirm that constructed command lines match expectations and that errors include command + stderr context.
