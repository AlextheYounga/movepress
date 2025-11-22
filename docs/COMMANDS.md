# Command Reference

Complete reference for all Movepress commands.

---

## movepress push

Push files and/or database from a source environment to a destination environment.

### Syntax

```bash
movepress push <source> <destination> [options]
```

### Arguments

- `source` - Source environment name (e.g., "local")
- `destination` - Destination environment name (e.g., "production")

### Options

**Sync Options:**

- `--db` - Sync database only
- `--files` - Sync files (uploads, caches, etc.), excluding git-tracked files by default
- `--include-git-tracked` - Include git-tracked files during file syncs

**Safety Options:**

- `--dry-run` - Preview changes without executing them
- `--no-backup` - Skip database backup before import (not recommended)
- `--delete` - Delete destination files that don't exist on the source when syncing files (destructive)

**Output Options:**

- `-v, --verbose` - Show detailed output including rsync/database commands

**Note:** Tracked files (themes, plugins, WordPress core) should be deployed via Git. For file syncs, Movepress auto-excludes git-tracked files from the local repo; if Git isn’t available, it falls back to excluding common code patterns. Use `--include-git-tracked` to override and include everything not covered by your movefile excludes. See `git-setup` command below.

### Examples

```bash
# Push database and files to production
movepress push local production --db --files

# Push database only
movepress push local staging --db

# Push only files (uploads, etc.)
movepress push local production --files

# Preview changes without executing
movepress push local production --db --files --dry-run

# Push with verbose output
movepress push local production --files -v
```

### Notes

- If no flags are specified, both `--db` and `--files` are synced by default
- Database operations automatically perform search-replace for URLs
- After files sync, Movepress scans text files in `wp-content/` and replaces the source URL with the destination URL (skips binaries/caches). At least one side of the sync must be local for this to occur.
- A backup is created before database import unless `--no-backup` is used, and the backup path is shown after creation
- File syncs are non-destructive by default. Use `--delete` to remove destination files that don't exist on the source.
- You'll be prompted to confirm only when a destructive option is in use (`--delete` or `--no-backup`)
- For tracked files (themes, plugins), use `git push <environment> <branch>` after running `git-setup`

---

## movepress pull

Pull files and/or database from a source environment to a destination environment.

### Syntax

```bash
movepress pull <source> <destination> [options]
```

### Arguments

- `source` - Source environment name (e.g., "production")
- `destination` - Destination environment name (e.g., "local")

### Options

Same options as `push` command (see above).

### Examples

```bash
# Pull database and uploads from production
movepress pull production local --db --files

# Pull database only
movepress pull staging local --db

# Pull only files (uploads, etc.)
movepress pull production local --files

# Preview changes without executing
movepress pull production local --db --files --dry-run
```

### Notes

- Works exactly like `push` but in reverse direction
- Same safety features, backup logging, and destructive-operation prompts as `push`
- After files sync, Movepress updates text files within `wp-content/` to swap the source URL for the destination URL. At least one side of the sync must be local for this to occur.
- Remember to use `git pull` or `git fetch` for tracked code files

---

## movepress git-setup

Set up Git-based deployment for a remote environment. This is a one-time setup command that configures a bare Git repository on the remote server with automatic deployment hooks.

### Syntax

```bash
movepress git-setup <environment>
```

### Arguments

- `environment` - Remote environment name (e.g., "staging", "production")

### What it does

1. Creates a bare Git repository on the remote server
2. Installs a post-receive hook that automatically deploys code to your WordPress directory
3. Adds a Git remote to your local repository

### Examples

```bash
# Set up Git deployment for production
movepress git-setup production

# Set up Git deployment for staging
movepress git-setup staging
```

### After setup

Once configured, deploy code changes using standard Git commands:

```bash
# Deploy to production
git push production master

# Deploy specific branch to staging
git push staging develop
```

### Notes

- Only works with remote environments (requires SSH configuration)
- The Git repository path defaults to `/var/repos/{site-name}.git` but can be customized in `movefile.yml` under `git.repo_path`
- This command is idempotent - safe to run multiple times
- Requires Git to be installed on both local and remote systems

---

## movepress init

Initialize a new Movepress configuration by creating template files.

### Syntax

```bash
movepress init
```

### What it creates

- `movefile.yml` - Main configuration file with example environments
- `.env` - Environment variables file for sensitive credentials

### Example

```bash
# Initialize configuration in current directory
movepress init

# Edit the generated files
vim movefile.yml
vim .env
```

### Notes

- Won't overwrite existing files
- Creates example configuration with local and production environments
- Includes comments explaining each option

---

## movepress status

Show system tools availability and configured environments.

### Syntax

```bash
movepress status [environment]
```

### Arguments

- `environment` (optional) - Show detailed information for specific environment

### Examples

```bash
# Show all environments and tool availability
movepress status

# Show detailed information for production environment
movepress status production
```

### Output

Displays:

- System tools availability (rsync, mysql, mysqldump)
- List of configured environments
- Environment details (when specific environment is provided)

---

## movepress validate

Validate your movefile.yml configuration file.

### Syntax

```bash
movepress validate
```

### What it checks

- YAML syntax errors
- Required fields for each environment
- Database configuration completeness
- SSH configuration (for remote environments)
- URL format validation
- Path accessibility

### Example

```bash
# Validate configuration
movepress validate
```

### Output

- Lists all validation errors with specific details
- Warnings for potential issues
- Success message if no problems found

---

## movepress ssh

Test SSH connectivity to a remote environment.

### Syntax

```bash
movepress ssh <environment>
```

### Arguments

- `environment` - Environment name to test (must be a remote environment)

### Example

```bash
# Test SSH connection to production
movepress ssh production

# Test SSH connection to staging
movepress ssh staging
```

### Output

Displays:

- SSH configuration details (host, user, port, key)
- Connection test result
- Troubleshooting tips if connection fails

### Notes

- Only works for remote environments with SSH configuration
- Local environments will show an error
- Helps diagnose SSH issues before attempting sync operations

---

## movepress backup

Create a database backup for an environment.

### Syntax

```bash
movepress backup < environment > [--output= < directory > ]
```

### Arguments

- `environment` - Environment name to backup

### Options

- `--output` or `-o` - Directory where backup should be saved (overrides `backup_path` from config)

### Examples

```bash
# Backup local database (uses backup_path from movefile.yml or <wordpress_path>/backups)
movepress backup local

# Backup production database (via SSH)
movepress backup production

# Save backup to specific directory
movepress backup production --output=/backups/critical
```

### Output

- Shows backup file location
- Displays file size after creation (local environments only)
- Confirms successful backup

### Notes

- Works for both local and remote (SSH) environments
- Backups are created in the format: `backup_{database_name}_YYYY-MM-DD_HH-mm-ss.sql.gz`
- You'll be prompted to confirm before creating the backup
- Backup location priority: `--output` flag > `backup_path` in config > `<wordpress_path>/backups` > system temp directory
- Configure default backup location in `movefile.yml`:
    ```yaml
    production:
        backup_path: /var/backups/movepress
    ```

---

## movepress post-files

Run the file-level search/replace manually. Normally Movepress invokes this automatically after syncing files, but you can execute it yourself (locally or remotely) for ad-hoc replacements.

### Syntax

```bash
movepress post-files <old-url> <new-url> [--path=<subdir>]
```

### Behaviour

- Should be executed from your WordPress root (Movepress scans the current working directory by default)
- Scans the working directory by default; `--path` lets you target a subdirectory such as `wp-content/uploads`
- Only processes known text extensions and skips files that appear binary

### Example

```bash
# Update theme files remotely (requires movepress installed on the server)
ssh deploy@example.com 'cd /var/www/html && movepress post-files https://example.com https://staging.example.com --path=wp-content/themes/mytheme'
```

### Notes

- Remote manual runs require the Movepress PHAR installed on the server (default `/usr/local/bin/movepress`)
- Manual runs are optional; `push`/`pull` already call this command locally when `--files` is used
- Untracked file syncs require at least one local endpoint so replacements happen on the machine running Movepress. For remote→remote replacements, run `post-files` manually on the destination after syncing via other tooling.

---

## Global Options

These options work with all commands:

- `-h, --help` - Display help for the command
- `-V, --version` - Display Movepress version
- `-q, --quiet` - Suppress output messages
- `-v, --verbose` - Increase verbosity of messages
- `-n, --no-interaction` - Do not ask any interactive question
- `--ansi` - Force ANSI output
- `--no-ansi` - Disable ANSI output

### Examples

```bash
# Show version
movepress --version

# Get help for push command
movepress push --help

# Run without any prompts
movepress push local production --db --no-interaction
```

---

## Global Options

These options work with all commands:

- `-h, --help` - Display help for the command
- `-V, --version` - Display Movepress version
- `-q, --quiet` - Suppress output messages
- `-v, --verbose` - Increase verbosity of messages
- `-n, --no-interaction` - Do not ask any interactive question
- `--ansi` - Force ANSI output
- `--no-ansi` - Disable ANSI output

### Examples

```bash
# Show version
movepress --version

# Get help for push command
movepress push --help

# Run without any prompts
movepress push local production --db --no-interaction
```

---

## Exit Codes

Movepress uses standard exit codes:

- `0` - Success
- `1` - General error
- `2` - Validation error (configuration issues)

---

## Environment Variables

You can use environment variables in your `movefile.yml`:

```yaml
production:
    database:
        user: ${DB_USER}
        password: ${DB_PASSWORD}
        port: ${DB_PORT}
```

Define them in your `.env` file:

```
DB_USER=production_user
DB_PASSWORD=secret123
DB_PORT=3306
```

---

## See Also

- [Configuration Reference](CONFIGURATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [Examples](EXAMPLES.md)
