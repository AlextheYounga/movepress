# Architecture Overview
- **Project state:** net-new Rust CLI; repo currently docs-only, so all components will be greenfield while remaining compatible with future workspace structure.
- **Goals:** deliver a single-binary tool that syncs WordPress environments (DB, wp-content subsets, or both) over SSH with delta-aware transfers, dry-run/confirm safety, Movefile-driven configuration, and optional WP-CLI search/replace.
- **Constraints:** SSH-only transport (no rsync), remote targets always Linux but clients must run on macOS (ARM/x86), Linux, Windows; wp-content directory always host-accessible even when WordPress uses Docker; must avoid hidden abstractions and keep operations idempotent/observable; safe handling of DB overwrites.
- **Assumptions:** mysqldump/mysql binaries and zip/unzip exist on each environment or the user accepts actionable errors; SSH auth uses system ssh/scp + agent; Movefile secrets are stored locally with OS-level protection; `wp-cli` is optional and invoked only when configured; remote file system clocks are “close enough” for mtime comparisons, falling back to hashes later if needed.

# System Design
## Components & Responsibilities
1. **CLI & Command Router** (clap): parses `push|pull <what> <src> <dst>` with global flags, renders dry-run summaries, and dispatches typed operations.
2. **Config Loader & Environment Registry:** parses `Movefile.toml` (serde + toml) into strongly-typed `Environment` structs (local vs ssh) plus sync defaults/excludes; exposes resolution helpers that validate requested envs.
3. **Path & Scope Resolver:** derives wp-content roots, subdirectories (`uploads`, `content`), exclusion matchers, and temporary staging directories per operation.
4. **SSH Client Abstraction:** async trait with adapters for local shell commands and remote ssh/scp invocations; default implementation shells out to system binaries to inherit user SSH configuration; future implementations can swap via trait objects.
5. **Manifest Builder:** produces `FileEntry { rel_path, size, mtime }` collections by walking local FS (walkdir + ignore) or remote FS (`find -type f -printf ...` over SSH) while applying unified exclusion filters.
6. **Diff Engine:** computes deltas (`to_upload`) by comparing manifests on rel_path plus size/mtime (with hash hook); drives both compressed and direct transfers.
7. **Transfer Engine:** orchestrates compressed (zip archive + single SCP + remote unzip) and direct (per-file SCP) flows, cleans up temp artifacts, and emits progress stats for verbose mode.
8. **Database Sync Pipeline:** encapsulates dump/compress/transfer/import phases with environment-aware mysqldump/mysql invocations, streaming via SSH when needed, and optional WP-CLI search-replace steps.
9. **Operation Orchestrators:** higher-level handlers for `db|uploads|content|all` push/pull, combining file + DB pipelines, enforcing confirmation policies, dry-run guards, and shared logging/error semantics.
10. **Observability & UX Layer:** structured logging, exit codes, confirmation prompts, and error taxonomy so the CLI can clearly communicate failure modes (missing binaries, SSH errors, validation failures).

## Data Flow
1. **CLI invocation** → parse args → load Movefile → resolve env pair + scope.
2. **Environment resolution** returns local/ssh descriptors including roots, db credentials, excludes, transfer mode defaults.
3. **Operation orchestrator** chooses pipeline: files, db, or combined `all` (files first, DB second for safer rollback), and enforces dry-run/confirm gating.
4. **File sync path:** manifest builder (src/dst) → diff engine → transfer engine (compressed or direct) → remote/local apply → optional wp-cli cache flush command.
5. **Database sync path:** mysqldump command (local or ssh exec) → streaming compression → transfer (local FS pipe or SSH) → mysql import (local/remote) → optional WP-CLI search-replace.
6. **Logging & telemetry** layer collects timings, counts, and emitted commands for verbose output; errors propagate with context back to CLI for user-friendly rendering.

## Data Models & Storage
- **Movefile schema:** typed structures for global sync config, per-environment credentials, excludes, transfer defaults.
- **Environment enum:** `LocalEnvironment` vs `SshEnvironment` capturing root paths, DB info, php/wp-cli overrides.
- **Manifest:** `Vec<FileEntry>` plus metadata (scope, generated_at) persisted only in-memory per run; optional serde for future caching.
- **Diff:** `FileDiff { to_upload: Vec<FileEntry> }` with counts/total_bytes for reporting; extendable for deletions later.
- **DB job descriptors:** records of dump/import commands, used for dry-run previews and for rehydrating logs.

## Trade-offs
- **System ssh/scp vs embedded library:** chosen to keep dependencies light, honor user SSH config/agent, and ensure cross-platform parity; trade-off is tighter coupling to installed binaries but errors remain actionable and future trait impls can swap in libssh2 when needed.
- **Compressed delta transfer vs rsync:** zip archives minimize round-trips without rsync dependency and work symmetrically for push/pull; trade-off is higher local CPU usage and reliance on remote zip/unzip availability, mitigated by direct-mode fallback and explicit diagnostics.
- **mtime/size diffing vs hashing:** faster for large trees and avoids reading entire files; risk of missing edge cases mitigated by optional hash mode later and by encouraging consistent clocks (documented assumption).
- **Single Movefile for config:** ensures transparency and Git-friendly config; secret exposure risk mitigated by advising env vars or .gitignore for sensitive Movefiles.
- **Combined `all` operation order:** syncing files before DB keeps WP references consistent and allows early exit if large file transfer fails, though it means DB updates can only start after file diff completes.

# Implementation Blueprint
## Features
1. **Feature: CLI & Global UX**
   - intent: Provide a clap-driven CLI that enforces `push|pull <what> <src> <dst>` syntax, handles `--config/--verbose/--dry-run`, prints dry-run summaries, and surfaces confirmation prompts for DB writes.
   - deps: none.
   - execution_order_hint: 1.
   - done_when: Running `movepress --help` lists commands/flags, invalid combinations fail with clear errors, dry-run renders planned actions without effects.
   - test_notes: CLI snapshot tests (trycmd) covering valid/invalid invocations, dry-run outputs, and confirmation prompt gating for production targets.

2. **Feature: Movefile Parser & Environment Registry**
   - intent: Parse `Movefile.toml` into strongly typed structures, resolve env names, merge global + per-env excludes, and expose transfer-mode defaults plus db credentials.
   - deps: Feature 1 (CLI provides config path), TOML schema documentation.
   - execution_order_hint: 2.
   - done_when: Invalid configs yield descriptive errors, env resolution works for local/ssh entries, and excludes combine predictably.
   - test_notes: Unit tests for parsing (happy path + missing fields), exclude precedence, and fixture-based round trips.

3. **Feature: SSH Client & Command Execution Layer**
   - intent: Implement `SshClient` trait with adapters for local shell calls and remote ssh/scp invocations, including timeout/error mapping and streaming hooks for stdout piping.
   - deps: Features 1-2 (env metadata, CLI flags for verbose logging).
   - execution_order_hint: 3.
   - done_when: Commands can be executed on remote hosts using system ssh/scp and respect configured ports/users; failures include stderr and exit status.
   - test_notes: Integration tests with mocked commands (assert invoked args) plus contract tests using local loopback SSH when available.

4. **Feature: Manifest & Diff Engine**
   - intent: Walk wp-content scopes locally and remotely, apply unified exclude rules, and compute delta sets (new + changed files) with counts/sizes for reporting and dry-run output.
   - deps: Feature 2 (paths/excludes), Feature 3 (remote exec for find/stat).
   - execution_order_hint: 4.
   - done_when: Identical trees yield empty diffs, modifications detected by size/mtime, and dry-run lists reflect computed counts.
   - test_notes: Unit tests on artificial directory trees, mocking remote outputs; ensure excludes work and diffs stable across platforms.

5. **Feature: Transfer Engine (Compressed + Direct)**
   - intent: Package `to_upload` files into scoped zip archives by default, upload/download once via SCP, unpack in place, and fall back to per-file SCP when zip/unzip unavailable.
   - deps: Feature 4 (diff data), Feature 3 (scp invocations).
   - execution_order_hint: 5.
   - done_when: Push/pull of uploads/content transfers only changed files, cleans temp artifacts, and surfaces actionable errors when remote tools missing.
   - test_notes: Integration tests using temporary directories + local SSH to confirm file parity; unit tests for archive builder/detector fallback logic.

6. **Feature: Database Sync Pipeline**
   - intent: Encapsulate mysqldump/mysql workflows for push/pull, automatically compress dumps when remote involved, stream via SSH to avoid intermediate files, and optionally invoke WP-CLI search-replace per destination config.
   - deps: Features 2-3 (env details & SSH), Feature 1 (confirm prompts, logging).
   - execution_order_hint: 6.
   - done_when: DB push/pull works for local↔ssh combinations, dry-run prints SQL command summary, and WP-CLI step can be skipped or enabled per config.
   - test_notes: Contract tests using dockerized MySQL fixtures or sqlite-based fakes; mock command runner for verifying invocation strings; ensure destructive operations require confirmation.

7. **Feature: `all` Operation Orchestration & Observability**
   - intent: Provide high-level push/pull `all` workflow that sequences file + DB sync with shared progress/logging, enforces dry-run semantics, and emits structured logs/metrics for each stage.
   - deps: Features 1,4,5,6.
   - execution_order_hint: 7.
   - done_when: `movepress push all local production --dry-run` summarizes both file + DB actions, real runs stop on first failure with clear message, verbose mode shows command traces.
   - test_notes: End-to-end smoke tests stitching feature mocks together; logging snapshot tests to ensure consistent formatting.

## Risks & Mitigations
- **Remote tool availability (zip, mysqldump, mysql, wp-cli):** Provide pre-flight checks and explicit instructions plus direct-mode fallback for files.
- **Large site sizes impacting memory/time:** Stream archives and DB dumps, chunk SCP transfers when possible, and expose progress + ability to cancel.
- **Clock skew causing missed deltas:** Document assumption, allow optional hash verification flag, and warn when diffs rely solely on mtime.
- **Credential leakage in logs:** Scrub sensitive args before logging; confine Movefile to local disk and support env-var overrides.
- **Windows path quirks:** Normalize path separators early and rely on Rust’s Path APIs; keep archive entries POSIX-style for remote extraction.

## Migration Notes
- Wordmove previously used Ruby + rsync; movepress intentionally replaces rsync with SSH+zip/scp to avoid external dependencies and improve cross-platform reliability.
- Existing Movefile YAMLs from Wordmove will need a conversion script to the new TOML schema (future utility); document key-field mapping for users.

# Operational Notes
- **Environment setup:** Rust stable (1.75+) with Cargo; requires system `ssh`, `scp`, `zip`, `unzip`, `mysqldump`, `mysql`, and optional `wp` binaries in PATH. Provide `install.sh` that verifies prerequisites.
- **Build tooling:** Use Cargo workspaces if future subcrates emerge; produce release binaries via `cargo build --release` and cross-compile using `cross` or GitHub Actions matrix for macOS/Linux/Windows.
- **Configuration & secrets:** `Movefile.toml` defaults to project root; support `MOVEPRESS_CONFIG` env override. Encourage users to keep Movefiles outside version control or encrypt secrets.
- **Testing & CI:** GitHub Actions pipeline running fmt/clippy/tests plus integration job with dockerized MySQL/SSH containers; nightly smoke test for push/pull permutations.
- **Security & compliance:** SSH-public-key auth preferred; no passwords echoed. Validate remote paths before exec to avoid command injection. Offer `--dry-run` as default suggestion before production writes.
- **Observability:** Structured logs (env, action, file counts, bytes) with verbosity levels; emit exit codes per failure class; optional `--json` output later for automation.
- **Rollout & rollback:** Release binaries via GitHub Releases; encourage canary by running `--dry-run` then enabling live pushes. Since operations are destructive, instruct users to take DB snapshots before push; include `movepress db backup` helper later.
- **Scalability/extensibility:** Modular trait-based SSH + pipeline layers allow swapping to libssh2 or parallel transfers; config schema leaves room for future env types (e.g., container exec) without rewrites.

# Verification Checklist
- **DB sync goals** → Features 6 & 7 ensure mysqldump-based push/pull with compression and optional WP-CLI.
- **File sync goals** → Features 4 & 5 deliver manifest-based delta transfers for uploads/content via SSH without rsync.
- **Full environment sync (`all`)** → Feature 7 sequences DB + wp-content with dry-run support.
- **CLI & config requirements** → Features 1 & 2 cover command shape, flags, and Movefile schema.
- **Transfer constraints (SSH-only, compressed default, direct fallback)** → Features 3 & 5 plus trade-offs detail approach.
- **Safety/observability (dry-run, confirmations, logging)** → Features 1 & 7 and Operational Notes document behaviors.
- **Cross-platform single binary** → Architecture + Operational Notes call for Rust build targets and reliance on system ssh/scp ensuring parity.
