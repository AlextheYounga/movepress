---
id: "006"
title: Database Sync Pipeline
branch: feat/006-database-sync-pipeline
status: OPEN
blocking: synchronous
---

## Objective

- Build the mysqldump/mysql-based push/pull pipeline that streams dumps, compresses when remote hops involved, and optionally runs WP-CLI search/replace per destination config as described in **Implementation Blueprint › Feature: Database Sync Pipeline**.

## Scope

- Use environment metadata from Ticket 002 and SSH adapters from Ticket 003 to construct mysqldump/mysql command invocations for local↔ssh combinations.
- Implement streaming dump/import flow that minimizes intermediate files, compresses dumps when SSH is involved, and captures stdout/stderr for observability.
- Integrate optional WP-CLI search-replace steps per destination settings, respecting CLI dry-run and confirmation gates from Ticket 001.
- Provide dry-run previews of DB actions (commands, target DB, estimated sizes) without executing them.

## Out of Scope

- File transfer mechanics for wp-content (Tickets 004-005).
- High-level sequencing of DB + file steps for `all` operations (Ticket 007).

## Requirements

- Pushing or pulling DBs between local and remote envs works for at least local→ssh and ssh→local permutations, handling credentials, ports, and compression automatically.
- Dry-run mode outputs the intended mysqldump/mysql/WP-CLI commands with placeholders for sensitive fields, without touching databases.
- Confirmation prompts guard destructive DB writes, aborting if the user declines or environment policy forbids it.
- Tests (mock command runner or dockerized fixtures) cover command assembly, search-replace optionality, and error propagation with actionable context.
