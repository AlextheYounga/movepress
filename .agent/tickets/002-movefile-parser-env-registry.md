---
id: "002"
title: Movefile Parser & Environment Registry
branch: feat/002-movefile-parser-env-registry
status: OPEN
blocking: synchronous
---

## Objective

- Parse `Movefile.toml` into strongly-typed environment/config data, merge global and per-environment defaults/excludes, and expose helpers to resolve env pairs in line with **Implementation Blueprint › Feature: Movefile Parser & Environment Registry**.

## Scope

- Define Rust data structures that represent global sync options, per-environment credentials (local vs SSH), exclusions, and transfer defaults described under **System Design › Data Models & Storage**.
- Implement Movefile loading with path overrides (from Ticket 001 CLI args) and emit descriptive errors for invalid schemas or missing envs.
- Provide resolution helpers that accept env names, return `Environment` descriptors, and combine exclude lists/transfer defaults.
- Validate env definitions (e.g., ensuring SSH entries include host/user, DB info complete when required).

## Out of Scope

- Executing SSH commands or transfers (Ticket 003+).
- Manifest/diff/transfer computation logic (Tickets 004-005).

## Requirements

- Loading a valid Movefile produces typed structs accessible throughout the app, with unit tests covering happy path and missing-field scenarios.
- Resolving environment names (local/ssh) returns a normalized structure with merged excludes and transfer defaults ready for downstream components.
- Invalid Movefile schemas or unknown env references produce actionable error messages surfaced through the CLI.
- Documentation or inline schema comments outline required/optional fields for future tooling.
