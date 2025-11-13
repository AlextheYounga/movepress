---
id: "004"
title: Manifest & Diff Engine
branch: feat/004-manifest-and-diff-engine
status: OPEN
blocking: synchronous
---

## Objective

- Generate manifest listings for wp-content scopes on local and remote environments, apply unified exclude rules, and compute delta sets that feed dry-run summaries and transfers as detailed in **Implementation Blueprint › Feature: Manifest & Diff Engine**.

## Scope

- Walk local directories (using walkdir/ignore) and remote trees (via `find`/stat over SSH) to build `FileEntry { rel_path, size, mtime }` collections defined under **System Design › Components 5 & Data Models**.
- Apply merged exclude rules from Ticket 002 uniformly to both local and remote listings.
- Compare source vs destination manifests to produce `FileDiff` structures (new/changed files, counts, total bytes) surfaced to the CLI dry-run output (Ticket 001) and transfer engine (Ticket 005).
- Provide optional hooks for future hashing without implementing hashing yet, ensuring design extensibility.

## Out of Scope

- Actual file transfer logic (Ticket 005).
- Database-related manifests or diffing (Ticket 006).

## Requirements

- Generating manifests for identical trees results in zero diffs; modifications detected when size or mtime differs, matching assumptions in the outline.
- Unified exclusion filter ensures the same ignore patterns apply to both local and remote walkers, with tests covering glob/regex cases.
- Diff results include aggregate counts/bytes used by dry-run summaries, and serialization/logging hooks expose entries for verbose mode.
- Unit tests cover sample directory fixtures plus mocked remote output, ensuring deterministic ordering and platform-agnostic behavior.
