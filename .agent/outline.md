# Architecture Overview
- **Goals:** Deliver a single-binary, cross-platform Rust CLI that syncs WordPress environments (db, uploads, full wp-content, or all) between local/staging/production via push/pull commands with rsync for files and mysqldump/mysql over SSH for databases; provide dry-run, verbose, and confirmation safeguards.
- **Constraints:** Use rsync; DB transfer over SSH; remote envs are Linux; wp-content must reside on host even if Docker is used; configuration lives in `Movefile.toml`; prioritize safety, observability, extensibility; binary must run on macOS (x86/ARM), Linux, Windows.
- **Assumptions:** MySQL-compatible tooling (mysqldump/mysql) is available on all envs; Windows users have SSH/rsync via WSL or bundled deps; `Movefile` secrets are managed locally and not echoed in logs; remote→remote DB/file sync can stage through the caller host when direct SSH between remotes is unavailable.
- **Project state:** Existing Rust CLI scaffold with Clap commands, confirmation handling, dry-run summaries, and `Movefile` parsing/validation (with tests). No actual sync engines are wired yet.
- **Migration Notes:** Preserve current CLI contract and config schema (Movefile version=1). Extend the new engines under the existing parsing/plan structures to keep trycmd tests stable; add functionality without breaking Movefile validation semantics.

# System Design
## Components & Responsibilities
- **CLI & Operation Planner (`main.rs`):** Parse commands/flags; build `OperationPlan` with direction/scope/source/target; enforce confirmation for DB scopes; orchestrate dry-run vs execution.
- **Config Loader (`Movefile` in `config.rs`):** Parse/validate Movefile v1; merge global and env excludes; resolve defaults (wp-content path, transfer mode); expose resolved env pairs with DB and wp-content metadata.
- **Path & Target Resolver:** Build absolute paths for local roots and remote wp-content/child scopes (`uploads`, `content`); derive rsync source/destination strings and temporary staging directories.
- **Command Execution Abstraction:** Trait-based executor wrapping `tokio::process::Command` for local commands plus SSH wrappers; captures stdout/stderr/status; supports streaming pipelines and verbose logging of shell commands.
- **File Sync Service:** Drives rsync for `uploads`/`content` scopes; applies excludes; honors transfer mode (compressed -> `-z`, direct omits); supports dry-run (`--dry-run`); builds include/exclude files; reports summary stats parsed from rsync output.
- **Database Sync Service:** Orchestrates mysqldump on source, optional gzip, transport over SSH or local pipes, import via mysql on destination; optional WP-CLI search-replace if path provided; enforces confirmation gates; surfaces size/progress logs.
- **All-Operation Orchestrator:** Runs DB sync then file sync (or vice versa if needed) for `all` scope, sharing resolved env metadata and respecting dry-run.
- **Logging & Error Handling:** color-eyre errors with contextual messages; verbose flag surfaces executed commands and temp paths; dry-run mode prints planned actions without side effects.

## Interfaces & Data Flow
- CLI input → `OperationPlan` (direction, scope, src, dst) → `Movefile::resolve_pair` to get `ResolvedEnvironment`s → orchestrator dispatches to DB/File services.
- **CommandExecutor trait:** `async fn exec(cmd: CommandSpec) -> Result<ExecResult>` where `CommandSpec` includes argv, working dir, env, whether to stream stdin/stdout, and ssh options for remote commands.
- **Rsync invocation:** Build argv with `-a` (archive), `-v` when verbose, `-z` for compressed mode, `--delete` optional later, `--exclude` entries from merged excludes; sources expressed as `/path/` or `user@host:/path/`. Dry-run adds `--dry-run` and `--stats` for summary parsing.
- **DB pipeline:** For local→remote: `mysqldump | gzip | ssh user@host 'zcat | mysql ...'`; for remote→local: `ssh user@host 'mysqldump ...' | gzip? | mysql ...`; for remote→remote: stream through caller host (dump via ssh, optionally gzip, then ssh into dest mysql). WP-CLI search-replace runs on destination host after import when configured.
- **Temp artifacts:** Use OS temp dirs with unique prefixes for dump files when streaming is not feasible; clean up on success/failure with scoped guards.

## Data Model & Storage
- **Movefile v1:** `version`; `[sync]` with `default_transfer_mode` (`compressed|direct`) and global `exclude` globs; `[defaults]` with `wp_content_subdir`; per-env sections keyed by name with `type` (`local|ssh`), roots, host/user/port for SSH, DB creds/host/port, optional `wp_content`, `php`, `wp_cli`, and `[env.sync.exclude]`.
- **Runtime state:** Resolved environments include merged excludes, derived wp-content path, DB config, transfer mode, and optional tool paths; no persistent state beyond Movefile and temp working files.

## Trade-offs
- **System tools over libraries:** Using rsync/ssh/mysqldump/mysql avoids reimplementing transfer logic and aligns with user setups; alternative (pure Rust SSH/DB libs) rejected for complexity and incompatibility with existing WP ops.
- **Streaming vs temp files:** Prefer pipelines to avoid disk churn and speed up transfers; temp files remain for resilience when streaming is unstable or for retries/debug output.
- **Single executor abstraction:** Centralizing command execution keeps SSH/local handling consistent; alternative bespoke calls would duplicate error/log handling.
- **DB-first vs files-first for `all`:** Default DB-first to prevent URL/content drift; swapping order is possible but yields risk of uploads pointing to stale DB entries.

# Implementation Blueprint
- **Feature 1: CLI & planning layer**  
  intent: keep current command grammar (`push|pull <what> <src> <dst>`) with flags (`--config`, `--verbose`, `--dry-run`, `--yes`) and produce internal plans for orchestrators.  
  deps: clap parser; config loader; confirmation handler.  
  execution_order_hint: 1.  
  done_when: commands map to plans with correct scope/direction; production targets still require interactive confirmation; dry-run short-circuits execution.  
  test_notes: extend trycmd cases for new scopes and flags; unit-test plan construction and confirmation branches.
- **Feature 2: Movefile parsing & resolution**  
  intent: robustly parse Movefile v1, merge sync excludes (global + env), derive wp-content path, and expose DB/tool metadata.  
  deps: serde/toml; validation rules (required fields by env type).  
  execution_order_hint: 2.  
  done_when: invalid files yield clear errors; resolve_pair returns both envs with merged excludes and transfer mode.  
  test_notes: add fixtures for missing fields, unsupported versions, wp_content overrides, env-specific excludes.
- **Feature 3: Command executor abstraction**  
  intent: provide async wrapper for local and SSH commands with stdout/stderr capture, streaming, and verbose logging of argv; reusable by DB/File services.  
  deps: tokio; ssh availability.  
  execution_order_hint: 3.  
  done_when: executor can run local, ssh remote, and piped commands; errors include exit codes and stderr; respects working dirs and env.  
  test_notes: integration-style tests with harmless commands (`echo`, `true`/`false`), plus mock/spy layer for unit services.
- **Feature 4: File sync via rsync**  
  intent: sync `uploads` or `content` using rsync with excludes and transfer mode; support push/pull between local and SSH envs; dry-run returns summary without changes.  
  deps: executor; Movefile resolution for roots and excludes; rsync installed.  
  execution_order_hint: 4.  
  done_when: rsync argv assembled correctly (source/dest, `-a`, `-z` for compressed, `--exclude` list, `--dry-run` when requested); summary output parsed for stats; errors show guidance when rsync missing.  
  test_notes: command-construction unit tests; integration tests gated/skipped unless rsync available; dry-run output snapshot tests.
- **Feature 5: Database sync pipeline**  
  intent: dump source DB, compress when pushing to non-local, transfer over SSH as needed, import into destination, optionally run WP-CLI search-replace; confirmations enforced.  
  deps: executor; Movefile DB config; gzip; mysqldump/mysql; wp-cli (optional).  
  execution_order_hint: 5.  
  done_when: supports local↔ssh and ssh↔ssh via streaming or temp file; honors `--dry-run` by emitting plan without side effects; surfaces clear errors for missing tools or failed commands; cleans temp artifacts.  
  test_notes: unit-test command assembly for each direction; simulate pipelines with `cat`/`gzip -c` in tests; WP-CLI invocation covered when configured.
- **Feature 6: `all` orchestration**  
  intent: combine DB sync and file sync respecting dependencies (DB first), shared env resolution, and dry-run/verbose flags.  
  deps: Features 4–5; planner.  
  execution_order_hint: 6.  
  done_when: `all` runs both stages with consistent logging and aborts on first failure; dry-run shows both plans.  
  test_notes: integration test ordering expectations; dry-run snapshot showing both sections.
- **Feature 7: Safety, dry-run, and confirmations**  
  intent: enforce human-in-the-loop for production DB writes, provide idempotent dry-run reporting, and optional `--yes` bypass for non-production.  
  deps: planner; DB/file services for dry-run descriptions.  
  execution_order_hint: parallel with Features 4–6 (shared concern).  
  done_when: production targets require interactive confirmation; dry-run disables side effects across services; messaging mirrors PROJECT.md guidance.  
  test_notes: trycmd cases for confirmations, failures on non-interactive production runs, and dry-run outputs across scopes.
- **Feature 8: UX, logging, and dependency diagnostics**  
  intent: consistent verbose logging of commands/temp paths, human-readable summaries, and explicit guidance when rsync/mysqldump/mysql/wp-cli/ssh are missing.  
  deps: executor; error handling layer.  
  execution_order_hint: 7.  
  done_when: errors include install hints per OS; verbose shows full command lines with redacted secrets; logs note temp file locations; quiet mode remains concise.  
  test_notes: snapshot error messages for missing binaries; check redaction and verbosity toggles.

- **Risks & Mitigations:**  
  - Missing system tools (rsync/mysql/ssh) on Windows → emit actionable install guidance (WSL/msys2) and skip destructive actions.  
  - Large DB dumps consuming disk during temp staging → prefer streaming, document limits, expose configurable temp dir.  
  - Remote-to-remote latency/failure mid-stream → make operations resumable via reruns; surface partial-failure messages; allow switch to temp file mode.  
  - Credential leakage in logs → redact passwords/connection strings; avoid echoing Movefile secrets.  
  - Path mismatches when wp-content lives outside root → rely on Movefile wp_content overrides and validate existence before running rsync/mysql import.

- **Migration Notes:** Build new services around existing CLI/config APIs; keep Movefile schema stable; adjust trycmd snapshots when new messaging is added but preserve current flag set and confirmation behavior.

# Operational Notes
- **Runtime/Tooling:** Rust 2024; tokio for async processes; external commands required: rsync, ssh, mysqldump, mysql, gzip/zcat, optional wp-cli and php binary per env. Ensure PATH includes these; on Windows prefer WSL.  
- **Build/Dist:** `cargo build --release` produces static-ish binaries per target; use cross-compilation for macOS ARM/x86 and Linux; for Windows ship with instructions to install rsync/ssh stack or bundle minimal msys2 tools.  
- **Config:** Default `Movefile.toml` in cwd; `--config` overrides path; Movefile holds env creds/paths; secrets stay local (no telemetry).  
- **CI/CD:** Run `cargo fmt && cargo clippy && cargo test`; include trycmd snapshots; optional integration jobs gated on availability of rsync/mysql binaries.  
- **Security:** Favor SSH keys/agent; avoid storing passwords in logs; confirm production DB writes interactively; restrict temp files to user-only permissions.  
- **Observability:** Verbose flag emits command lines and rsync stats; errors include context via color-eyre; consider structured JSON logs behind env flag for automation.  
- **Rollout/rollback:** Dry-run for preview; `--yes` only for non-production; rerun commands to achieve idempotent state (rsync + full DB imports overwrite destination). No background daemons needed.  
- **Scalability/extensibility:** Component boundaries allow future transfer modes (e.g., S3 bucket staging), alternative DB engines, or additional scopes without altering CLI grammar.

# Verification Checklist
- DB sync commands (push/pull any env pair) dump via mysqldump, transfer over SSH when remote, import with mysql, optional WP-CLI search-replace, confirmations enforced.  
- File sync commands for uploads/content use rsync with excludes, compression toggle via transfer mode, and dry-run support.  
- `all` scope performs both DB and file sync honoring order and shared config.  
- CLI supports global flags (`--config`, `--verbose`, `--dry-run`, `--yes`) and subcommands (`push|pull <what> <src> <dst>`).  
- Movefile v1 schema respected: global defaults, env overrides, excludes merged, wp_content override handled.  
- Cross-platform delivery: Rust single binary; relies on system rsync/ssh/mysql with Windows relying on WSL/mingw guidance; remote hosts assumed Linux.  
- Safety and observability: dry-run summaries, verbose command logs, clear errors for missing deps, confirmation for production DB targets.  
- Alignment with philosophy: minimal abstraction (system tools via thin executor), idempotent reruns, SQLite-first preference not applicable but storage kept minimal; incremental features via separated services.*** End Patch**"
