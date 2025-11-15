---
id: "004"
title: Path & Target Resolver
branch: feat/004-path-target-resolver
status: OPEN
blocking: synchronous
---

## Objective

- Build the path/target resolution utilities from **System Design › Path & Target Resolver** so sync plans can derive canonical local/remote wp-content roots, sub-scope paths, and temp staging directories.

## Scope

- Given a resolved environment pair, compute absolute wp-content, `uploads`, and `content` paths for both local and SSH environments, honoring overrides like `wp_content_subdir` and env-specific `wp_content`.
- Merge global + env excludes into ready-to-use lists for rsync include/exclude files, ensuring globs are expressed correctly for both local and remote contexts.
- Derive rsync-ready source/destination strings (`/path/` vs `user@host:/path/`) plus helper functions that expose temp directory paths for staging (with unique prefixes per operation plan).
- Validate paths (existence for local; structural sanity for remote) and surface actionable errors if configuration is invalid before kicking off transfers.
- Provide unit tests covering local-only, ssh-only, and mixed env scenarios, including edge cases like wp-content overrides outside the default root.

## Out of Scope

- Actually invoking rsync or database tooling (Tickets 005–006).
- CLI parsing/flag plumbing (Ticket 001) or Movefile parsing (Ticket 002).

## Requirements

- File scopes resolved from an `OperationPlan` can immediately access normalized structs that expose rsync source/destination strings, exclude files, and transfer modes.
- Temp directory helper returns per-operation paths under the OS temp dir and cleans up automatically when requested by callers.
- Errors clearly state which env/path is invalid and link back to the Movefile key that needs adjustment.
- Tests document derived paths for representative Movefile fixtures, preventing regressions as new scopes are added.
