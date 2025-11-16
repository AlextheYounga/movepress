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
- `--untracked-files` - Sync files not tracked by Git (uploads, caches, etc.)

**Safety Options:**

- `--dry-run` - Preview changes without executing them
- `--no-backup` - Skip database backup before import (not recommended)

**Output Options:**

- `-v, --verbose` - Show detailed output including rsync/database commands

**Note:** Tracked files (themes, plugins, WordPress core) should be deployed via Git. See `git:setup` command below.

### Examples

```bash
# Push database and untracked files to production
movepress push local production --db --untracked-files

# Push database only
movepress push local staging --db

# Push only untracked files (uploads, etc.)
movepress push local production --untracked-files

# Preview changes without executing
movepress push local production --db --untracked-files --dry-run

# Push with verbose output
movepress push local production --untracked-files -v
```

### Notes

- If no flags are specified, both `--db` and `--untracked-files` are synced by default
- Database operations automatically perform search-replace for URLs
- A backup is created before database import unless `--no-backup` is used
- You'll be prompted to confirm before destructive database operations
- For tracked files (themes, plugins), use `git push <environment> <branch>` after running `git:setup`

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
movepress pull production local --db --untracked-files

# Pull database only
movepress pull staging local --db

# Pull only untracked files (uploads, etc.)
movepress pull production local --untracked-files

# Preview changes without executing
movepress pull production local --db --untracked-files --dry-run
```

### Notes

- Works exactly like `push` but in reverse direction
- Same safety features and backup behavior as `push`
- Remember to use `git pull` or `git fetch` for tracked code files

---

## movepress git:setup

Set up Git-based deployment for a remote environment. This is a one-time setup command that configures a bare Git repository on the remote server with automatic deployment hooks.

### Syntax

```bash
movepress git:setup <environment>
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
movepress git:setup production

# Set up Git deployment for staging
movepress git:setup staging
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

- System tools availability (rsync, mysql, mysqldump, wp-cli)
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
movepress backup <environment> [--output=<directory>]
```

### Arguments

- `environment` - Environment name to backup

### Options

- `--output` or `-o` - Directory where backup should be saved (overrides `backup_path` from config)

### Examples

```bash
# Backup local database (uses backup_path from movefile.yml or /tmp)
movepress backup local

# Backup production database (via SSH)
movepress backup production

# Save backup to specific directory
movepress backup production --output=/backups/critical
```

### Output

- Shows backup file location
- Displays file size after creation
- Confirms successful backup

### Notes

- Works for both local and remote (SSH) environments
- Backups are created in the format: `backup_{database_name}_YYYY-MM-DD_HH-mm-ss.sql.gz`
- You'll be prompted to confirm before creating the backup
- Backup location priority: `--output` flag > `backup_path` in config > system temp directory
- Configure default backup location in `movefile.yml`:
    ```yaml
    production:
        backup_path: /var/backups/movepress
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
```

Define them in your `.env` file:

```
DB_USER=production_user
DB_PASSWORD=secret123
```

---

## See Also

- [Configuration Reference](CONFIGURATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [Examples](EXAMPLES.md)
