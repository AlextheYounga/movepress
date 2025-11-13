# Movepress - A Successor to wordmove

There is a Ruby gem called wordmove ([https://github.com/welaika/wordmove](https://github.com/welaika/wordmove)) but it is too old and tries to handle too much abstraction through Ruby. I have been trying to revive it and to no avail because the external dependencies themselves are breaking down with age.

I would like to recreate the functionality of this gem and potentially expand its availability.

## 1. Project Overview

**Goal:**
Build **movepress**, a modern, cross-platform, single-binary CLI tool (Rust) that synchronizes WordPress environments ( `local ↔ staging ↔ production`) by handling:

* **Databases**: dump, pull, push, import.
* `**wp-content**`: themes, plugins, uploads.
* **Full environments**: DB + `wp-content`.

**Design constraints:**

* No `rsync` dependency.
* All data transfer over **SSH-based protocols**.
* Local and remote environments can be Docker-based, but the wp-content folder should always exist on the host system in either case, (not isolated in the container).
* Cross-platform: macOS (ARM + x86), Linux, Windows.
* Remote environments are always **Linux**.
* Safe, observable, and extensible.

**Positioning:**
Movepress is a modern, Rust-based successor to the Ruby tool Wordmove, designed to be stable, fast, and predictable on all major OSes.

---

## 2. Core Features & Use Cases

### 2.1 Database Sync

Commands:

* `movepress push db local production`
* `movepress pull db production local`
* Support arbitrary env pairs: `<src_env> <dst_env>`.

Behavior:

* Run `mysqldump` (or equivalent) on **source** environment.
* Compress dump (e.g., gzip) by default for pushing to remote.
* Transfer dump via SSH if source or destination is remote.
* Import dump into **destination** DB using `mysql` (or equivalent).
* Optional **WP-CLI integration** for `search-replace` (e.g., domain changes).

### 2.2 File Sync ( wp-content and subdirs)

Commands:

* `movepress push uploads local production`
* `movepress pull uploads production local`
* `movepress push content local staging` (entire `wp-content`)
* `movepress push all local production` (DB + `wp-content`)
* `movepress pull all production local`

Behavior:

* Build file manifests on src and dst (relative paths, size, mtime, optional hash).
* Compute **delta**:

  * New files
  * Changed files
* Sync only changed files (delta sync).

---

## 3. CLI Design

### 3.1 Command Shape

Binary name: `movepress`.

Basic syntax:

```bash
movepress push db <src_env> <dst_env> [--dry-run]
movepress pull uploads <src_env> <dst_env> [--dry-run]
movepress push all <src_env> <dst_env> [--dry-run]
```

### 3.2 Subcommands and Options

* Global flags:

  * `--config <path>` (default: `Movefile.toml`)
  * `--verbose`
* Subcommands:

  * `push <what> <src_env> <dst_env> [--dry-run]`
  * `pull <what> <src_env> <dst_env> [--dry-run]`
* `what` enum:

  * `db` – database only
  * `uploads` – `wp-content/uploads`
  * `content` – entire `wp-content`
  * `all` – DB + entire `wp-content`
* Behavior flags:

  * `--dry-run` – show operations without executing
  * Future: `--transfer-mode=compressed|direct`, `--no-confirm`.

---

## 4. Configuration – Movefile.toml

### 4.1 Top-Level Structure

Config file: `Movefile.toml` (TOML format).

```toml
version = 1

[sync]
# Default file transfer strategy: "compressed" or "direct"
default_transfer_mode = "compressed"

# Global exclusions (applied to all environments)
# Patterns are globs, relative to the site root or wp-content (to be defined explicitly).
exclude = [
  "wp-content/cache/**",
  "wp-content/uploads/cache/**",
  "**/.DS_Store",
  "**/*.log",
]

[defaults]
# Default wp-content directory name if env-specific wp_content is not set.
wp_content_subdir = "wp-content"
```

### 4.2 Environments

Two environment types: `local` and `ssh`.

### Example: Local Environment

```toml
[local]
type = "local"
root = "/Users/alex/sites/mysite"
wp_content = "wp-content"      # optional; falls back to defaults.wp_content_subdir
db_name = "mysite_local"
db_user = "root"
db_password = ""
db_host = "127.0.0.1"
db_port = 3306

  [local.sync]
  # Per-environment exclude overrides for local
  exclude = [
    "wp-content/uploads/dev-only/**"
  ]
```

### Example: Staging (SSH)

```toml
[staging]
type = "ssh"
host = "staging.example.com"
user = "deploy"
port = 22
root = "/var/www/mysite"
wp_content = "wp-content"
db_name = "mysite_staging"
db_user = "mysite"
db_password = "secret"
db_host = "localhost"
db_port = 3306
php = "php"        # optional: path or command for PHP
wp_cli = "wp"      # optional: WP-CLI command/path

  [staging.sync]
  exclude = [
    # environment-specific excludes (can be empty)
  ]
```

### Example: Production (SSH)

```toml
[production]
type = "ssh"
host = "prod.example.com"
user = "deploy"
port = 22
root = "/var/www/mysite"
wp_content = "wp-content"
db_name = "mysite_prod"
db_user = "mysite"
db_password = "secret"
db_host = "localhost"
db_port = 3306

  [production.sync]
  exclude = [
    # e.g. keep some backup dir only on prod
    "wp-content/uploads/private-backups/**"
  ]
```

### 4.3 Exclude Resolution Rules

For any environment:

1. Start with **global** `sync.exclude` patterns.
2. Merge **per-environment** `[env.sync].exclude` (adds/overrides semantics as needed).
3. Apply combined excludes to manifests for both local and remote scans.

Result:
Every environment can have its own exclude patterns, while still inheriting global ones.

---

## 5. Core Concepts & Internal Model

### 5.1 Transfer Modes

Enum:

```rust
enum TransferMode {
    Direct,     // individual files
    Compressed, // zip archive of changed files
}
```

* Resolved from:

  * Config: `sync.default_transfer_mode` (default = `Compressed`).
  * Later: CLI overrides.

* **Local** manifest: built via filesystem walking ( `walkdir` + `ignore`).

* **Remote** manifest: built via SSH commands ( `find`, `stat`, etc.), then parsed.

### 5.2 Exclusion Logic

* Use glob patterns (e.g., via `ignore` crate).
* Exclusions are applied when building manifests:

  * If `Excluder.is_excluded(path)` → skip entry.
* Same exclusion logic on both sides ensures symmetric view of the filesystem.

### 5.3 Diff Model

Given `src_manifest` and `dst_manifest`:

* `to_upload`: files that exist in src and:

  * Don’t exist in dst; or
  * Diff in `size` or `mtime` (or hash if implemented).

Internal type:

```rust
struct FileDiff {
    to_upload: Vec<FileEntry>,
}
```

---

## 6. File Transfer Protocol

### 6.1 Compressed Mode (Default for Pushes)

For **push** (local → remote is the primary target case):

1. Generate manifests for src & dst.
2. Apply exclusions.
3. Compute diff.
4. If `--dry-run`:

   * Print summary and optionally list files.
   * Exit.
5. Else:

   * Create a **local zip archive** containing all `to_upload` files:

     * Paths inside the zip are relative to `wp-content` (or the subdir being synced).
   * Upload the zip to remote via **SCP over SSH** (e.g., `/tmp/movepress-<id>.zip`).
   * On remote, run:

   ```bash
   cd /var/www/mysite/wp-content && unzip -o /tmp/movepress-<id>.zip && rm /tmp/movepress-<id>.zip
   ```

For **pull** (remote → local):

* Optional: use compressed mode as well:

  * Create `zip` on remote (same set of `to_upload`).
  * Download to local over SSH/SCP.
  * Extract locally.

Behavior when `zip`/ `unzip` missing on remote:

* Clear error message suggesting:

  * Install `zip`/ `unzip`, or
  * Use `--transfer-mode=direct` (future CLI flag).

### 6.2 Direct Mode (Fallback / Alternative)

* For each file in `to_upload`:

  * Use **SCP over SSH** to transfer individual file.

Direct mode is less efficient but simpler and less dependent on remote packages.

---

## 7. Database Sync Protocol

### 7.1 Source DB Dump

* **Local source:**

  * Use local `mysqldump` (configurable path later if needed).
* **Remote source:**

  * Use SSH: `ssh user@host 'mysqldump ...'`.
  * Stream dump to local or to another remote environment as needed.

### 7.2 Compression

* By default, compress DB dumps when transferring **up** (or any non-local path):

  * e.g., `mysqldump | gzip` on source.
  * Transfer `.sql.gz`.
* On import:

  * `zcat dump.sql.gz | mysql ...` or equivalent.

### 7.3 Destination Import

* Run `mysql` with credentials from destination env.
* Optional: run `wp search-replace` to adjust URLs.

### 7.4 Safety

* By default, show **summary of DB operation** in `--dry-run`.
* Optional confirmation prompt for DB writes:

  * “About to overwrite DB on production. Continue? (y/N)”.

---

## 8. SSH & Transport Abstraction

### 8.1 SSH Client Abstraction

Define a trait:

```rust
#[async_trait]
trait SshClient {
    async fn exec(&self, cmd: &str) -> Result<ExecResult>;
    async fn upload_file(&self, local: &Path, remote: &Path) -> Result<()>;
    async fn download_file(&self, remote: &Path, local: &Path) -> Result<()>;
}

struct ExecResult {
    stdout: String,
    stderr: String,
    status: i32,
}
```

### 8.2 MVP Implementation

* Initial implementation uses native `ssh` & **`scp` binaries**:

  * Spawn via `tokio::process::Command`.
  * Honors system SSH config, keys, and agent.
* Later pluggable implementations:

  * `ssh2` (libssh2) based client.
  * Pure Rust libraries like `russh`, etc.

---

### 9.1 Dry-run Mode

* For both DB and file ops:

  * Show summary of planned actions.
  * Optional listing of individual files.
* No side effects when `--dry-run` is enabled.

### 9.2 Confirmation Prompts

* Especially for:

  * DB overwrites on non-local envs.

### 9.3 Logging

* Respect `--verbose`:

  * Show SSH commands being run.
  * Show paths of temporary archives.
  * Show counts of uploaded files.
* Structured or human-readable logs (at architect’s discretion).
