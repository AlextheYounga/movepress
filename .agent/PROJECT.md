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

* Use `rsync` for efficient file synchronization.
* Database transfer over **SSH-based protocols**.
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

* Use `rsync` to efficiently transfer files between environments.
* Rsync automatically handles:
  * Delta sync (only changed files)
  * Compression during transfer
  * Preserving timestamps and permissions
* Apply exclusion patterns via rsync's `--exclude` flags.
* Support for dry-run via rsync's `--dry-run` flag.

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

### 5.1 Rsync Integration

* Use `rsync` command-line tool for all file synchronization.
* Rsync handles:
  * Delta transfers (only changed portions of files)
  * Compression during transfer (`-z` flag)
  * Preserving permissions and timestamps
  * Efficient directory synchronization

* Command construction:
  * Local to remote: `rsync -avz --exclude-from=... /local/path/ user@host:/remote/path/`
  * Remote to local: `rsync -avz --exclude-from=... user@host:/remote/path/ /local/path/`
  * Local to local: `rsync -av --exclude-from=... /src/path/ /dst/path/`

### 5.2 Exclusion Logic

* Exclusion patterns from config are converted to rsync `--exclude` flags.
* Patterns are passed to rsync via:
  * Multiple `--exclude` flags, or
  * `--exclude-from` with a temporary file containing all patterns.
* Rsync uses its own pattern matching (similar to gitignore):
  * `*` matches any path component
  * `**` matches any number of directories
  * Trailing `/` means directory only
* Global and per-environment exclusions are merged before invoking rsync.

---

## 6. File Transfer Protocol (via Rsync)

### 6.1 Rsync Command Construction

For all file transfers, movepress constructs and executes `rsync` commands:

**Common rsync flags:**
* `-a` (archive mode: recursive, preserve permissions, times, etc.)
* `-v` (verbose, when `--verbose` flag is used)
* `-z` (compress during transfer for remote operations)
* `--delete` (optional: remove files on destination that don't exist on source)
* `--dry-run` (when `--dry-run` flag is used)
* `--exclude` or `--exclude-from` (for exclusion patterns)
* `--stats` (show transfer statistics)

**Push (local → remote):**
```bash
rsync -avz --exclude-from=/tmp/excludes.txt \
  /Users/alex/sites/mysite/wp-content/ \
  deploy@prod.example.com:/var/www/mysite/wp-content/
```

**Pull (remote → local):**
```bash
rsync -avz --exclude-from=/tmp/excludes.txt \
  deploy@prod.example.com:/var/www/mysite/wp-content/ \
  /Users/alex/sites/mysite/wp-content/
```

**Local to local:**
```bash
rsync -av --exclude-from=/tmp/excludes.txt \
  /path/to/source/wp-content/ \
  /path/to/dest/wp-content/
```

### 6.2 Dry-run Output

* When `--dry-run` is specified:
  * Pass `--dry-run` to rsync.
  * Parse rsync output to show summary:
    * Files to be transferred
    * Total size
    * Operations that would be performed
  * No actual file changes occur.

### 6.3 Error Handling

* If `rsync` is not found:
  * Clear error message: "rsync is required but not found. Please install rsync."
  * Installation instructions for each OS:
    * macOS: `brew install rsync` (often pre-installed)
    * Linux: `apt-get install rsync` or `yum install rsync`
    * Windows: Install via WSL, Cygwin, or msys2
* If rsync fails (non-zero exit):
  * Display stderr output
  * Show rsync exit code and meaning

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

## 8. SSH & Command Execution

### 8.1 Command Execution Abstraction

Define a trait for executing commands:

```rust
#[async_trait]
trait CommandExecutor {
    async fn exec(&self, cmd: &str) -> Result<ExecResult>;
}

struct ExecResult {
    stdout: String,
    stderr: String,
    status: i32,
}
```

### 8.2 MVP Implementation

* Use native command execution via `tokio::process::Command`:
  * For rsync: spawn `rsync` process directly
  * For SSH commands (DB operations): spawn `ssh` process
  * For DB transfers: spawn `mysqldump`, `mysql`, etc.
* Benefits:
  * Honors system SSH config, keys, and agent
  * Uses system's rsync installation
  * No additional dependencies

### 8.3 Rsync over SSH

* Rsync natively supports SSH protocol:
  * Format: `user@host:/path/`
  * Uses system SSH configuration automatically
  * Respects SSH keys, config files (~/.ssh/config)
  * Can specify port via `-e "ssh -p 2222"`

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
