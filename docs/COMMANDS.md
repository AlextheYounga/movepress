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
- `--files` - Sync all files
- `--content` - Sync themes and plugins only (excludes uploads)
- `--uploads` - Sync uploads only (wp-content/uploads)
- `--include=<pattern>` - Include specific files/folders (can be used multiple times)

**Safety Options:**
- `--dry-run` - Preview changes without executing them
- `--no-backup` - Skip database backup before import (not recommended)

**Output Options:**
- `-v, --verbose` - Show detailed output including rsync/database commands

### Examples

```bash
# Push everything to production
movepress push local production --db --files

# Push database only
movepress push local staging --db

# Push only uploads
movepress push local production --uploads

# Push specific folder
movepress push local production --files --include="wp-content/themes/mytheme"

# Preview changes without executing
movepress push local production --db --files --dry-run

# Push with verbose output
movepress push local production --files -v
```

### Notes

- At least one of `--db`, `--files`, `--content`, or `--uploads` must be specified
- Database operations automatically perform search-replace for URLs
- A backup is created before database import unless `--no-backup` is used
- You'll be prompted to confirm before destructive database operations

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
# Pull everything from production
movepress pull production local --db --files

# Pull database only
movepress pull staging local --db

# Pull only uploads
movepress pull production local --uploads

# Pull specific folder
movepress pull production local --files --include="wp-content/uploads/2024"

# Preview changes without executing
movepress pull production local --db --files --dry-run
```

### Notes

- Works exactly like `push` but in reverse direction
- Same safety features and backup behavior as `push`

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
movepress backup <environment>
```

### Arguments

- `environment` - Environment name to backup

### Examples

```bash
# Backup local database
movepress backup local

# Backup production database (via SSH)
movepress backup production
```

### Output

- Shows backup file location
- Displays file size after creation
- Confirms successful backup

### Notes

- Works for both local and remote (SSH) environments
- Backups are created in the format: `<database_name>_backup_YYYYMMDD_HHMMSS.sql`
- You'll be prompted to confirm before creating the backup

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
