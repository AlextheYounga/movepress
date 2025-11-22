# Movepress

A modern WordPress deployment tool for pushing and pulling databases and files between environments via SSH.

**Built as a modern successor to [Wordmove](https://github.com/welaika/wordmove)** - the beloved but unmaintained Ruby gem that revolutionized WordPress deployments. Movepress brings the same powerful workflow to modern PHP with improved reliability, better performance, and zero Ruby dependencies.

I translated the logic of the [go-search-replace](https://github.com/Automattic/go-search-replace) algorithm developed by [Automattic] into PHP and incorporated that into this utility. You can find that PHP-ported logic here: [php-search-replace](https://github.com/AlextheYounga/php-search-replace). This allows us to search and replace serialized php inside sql dump files, avoiding the constraints of wp-cli for search-replace functions.

## Features

- 🚀 Push/pull WordPress databases with automatic search-replace
- 📁 Sync files (uploads, caches) using rsync over SSH (git-tracked files excluded by default)
- 🔧 Git-based deployment for tracked files (themes, plugins, core)
- 🔐 Environment variable support in configuration
- 🎯 Flexible exclude patterns (global and per-environment)
- 🧠 Native SQL search-replace (ported from Automattic's go-search-replace)
- ⚡ Single executable `.phar` file
- 🔁 Automatic URL updates in synced files and databases

## Installation

### Build from source

```bash
git clone https://github.com/AlextheYounga/movepress.git
cd movepress
composer install --no-dev
./vendor/bin/box compile

# Use the compiled PHAR
./build/movepress.phar --version

# Optionally install globally
sudo mv ./build/movepress.phar /usr/local/bin/movepress
```

> **Note:** Pre-built PHAR releases coming soon!

## Quick Start

1. Initialize configuration in your WordPress root:

```bash
cd /path/to/your/wordpress
movepress init
```

2. Edit `movefile.yml` and `.env` with your environment details

3. Set up Git deployment (one-time):

```bash
# Configure Git deployment for production
movepress git-setup production
```

4. Deploy:

```bash
# Deploy code changes via Git
git push production master

# Sync database and files
movepress push local production --db --files

# Pull database from staging
movepress pull staging local --db

# Pull only files (uploads, etc.) from production
movepress pull production local --files
```

## Commands

### Core Commands

- `movepress push <source> <destination>` - Push database/files from source to destination
- `movepress pull <source> <destination>` - Pull database/files from source to destination
- `movepress git-setup <environment>` - Set up Git deployment for remote environment
- `movepress init` - Initialize a new movefile.yml configuration
- `movepress status` - Show system tools availability and configured environments
- `movepress validate` - Validate your movefile.yml configuration
- `movepress ssh <environment>` - Test SSH connectivity to an environment
- `movepress backup <environment>` - Create a database backup for an environment

### Push/Pull Options

- `--db` - Sync database only
- `--files` - Sync files (uploads, caches, etc.), excluding git-tracked files by default
- `--dry-run` - Preview changes without making them
- `--no-backup` - Skip backup before database import
- `--delete` - Delete destination files missing from source during file syncs (destructive)
- `--include-git-tracked` - Include git-tracked files in file syncs (disables automatic git exclusions)
- `-v, --verbose` - Show detailed output

**File Sync Process:**

When syncing files, Movepress uses a **staged confirmation workflow** for safety and accuracy:

1. **Staging** - Files are copied to a temporary directory with exclusions applied via a temporary `--exclude-from` file (silent, avoids argument-length issues)
2. **Search-Replace** - URLs are updated in staged files before transfer, so previews match final content
3. **Preview** - You see exactly what will be synced based on the staged files (works the same for push and pull)
4. **Confirmation** - Required for every file sync before any changes are made
5. **Transfer** - Only after confirmation are files synced to the destination; staging temp dirs are cleaned up automatically

This ensures you always know exactly what's being deployed. The `wp-content/uploads/` directory is shown as a single entry with a total file count to keep the preview clean. WordPress core paths are always excluded for safety (`wp-admin/`, `wp-includes/`, `wp-content/plugins/`, `wp-content/mu-plugins/`, `wp-content/themes/`, `index.php`, `wp-*.php`, `license.txt`, `readme.html`, `wp-config-sample.php`).

**Note:** Tracked files (themes, plugins, WordPress core) should be deployed via Git. Use `git push <environment> <branch>` after running `movepress git-setup`.
When syncing files, Movepress reads the local Git repo to auto-exclude tracked files; if Git isn't available, it falls back to excluding common code patterns (themes, plugins, core). Use `--include-git-tracked` to override and send everything not covered by movefile excludes.

File syncs are non-destructive by default—Movepress only removes destination files when you explicitly pass `--delete`, and it will warn you before doing so. Database syncs create a backup automatically unless `--no-backup` is provided, and the backup path is printed for easy reference.

### Examples

```bash
# One-time Git setup for production
movepress git-setup production

# Deploy code changes via Git
git push production master

# Sync database and files to production
movepress push local production --db --files

# Pull database only from staging
movepress pull staging local --db

# Sync only files (uploads, etc.) from production
movepress pull production local --files

# Preview what would be pushed (dry run)
movepress push local staging --db --files --dry-run

# Create a backup before making changes
movepress backup production
```

## Configuration

The `movefile.yml` file defines your environments and sync settings:

```yaml
local:
    wordpress_path: /path/to/wordpress
    url: http://local.test
    database:
        name: wp_local
        user: root
        password: ''
        host: localhost
        port: 3306

production:
    wordpress_path: /var/www/html
    url: https://example.com
    ssh:
        host: server.example.com
        user: deploy
        port: 22
        key: ~/.ssh/id_rsa
    database:
        name: wp_production
        user: ${DB_USER}
        password: ${DB_PASSWORD}
        host: localhost
        port: 3306
    # Optional: Git deployment configuration
    git:
        repo_path: /var/repos/mysite.git

# Global exclude patterns (for rsync)
global:
    exclude:
        - '.git/'
        - 'node_modules/'
        - '.env'
```

### Configuration Options

**Required for each environment:**

- `wordpress_path` - WordPress installation path
- `url` - WordPress site URL
- `database` - Database credentials (name, user, password, host, port)
    - `port` defaults to `3306` if omitted

**Optional:**

- `ssh` - SSH connection details (host, user, port, key) for remote environments
- `git` - Git deployment configuration (repo_path defaults to `/var/repos/{site-name}.git`)
- `exclude` - Environment-specific exclude patterns for rsync

**Environment Variables:**
Use `${VAR_NAME}` syntax to reference variables from your `.env` file.

### Deployment Workflow

Movepress uses a **hybrid approach** for managing WordPress sites:

1. **Git for tracked files** - Deploy themes, plugins, and WordPress core via Git
2. **Rsync for files** - Sync uploads, caches, and other generated content (requires at least one local endpoint so Movepress can update URLs locally)
3. **Database sync** - Export, search-replace, and import databases between environments

**Typical workflow:**

```bash
# One-time setup
movepress git-setup production

# Regular deployments
git commit -am "Update theme"
git push production master              # Deploy code
movepress push local production --db    # Sync database
movepress push local production --files # Sync uploads
```

## Requirements

- PHP 8.1 or higher
- SSH access to remote servers
- rsync installed on local and remote systems
- mysql/mysqldump command-line tools

## Development

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run with code coverage
php -dxdebug.mode=coverage ./vendor/bin/phpunit
```

### Building the PHAR

```bash
# Install dependencies
composer install --no-dev

# Build the PHAR
./vendor/bin/box compile

# Test the PHAR
./build/movepress.phar --version
```

## Troubleshooting

### SSH Connection Issues

If you're having trouble connecting to a remote server:

```bash
# Test SSH connectivity
movepress ssh production

# Verify your SSH key works manually
ssh -i ~/.ssh/id_rsa user@host
```

### Database Sync Issues

- Ensure mysql/mysqldump are installed on both local and remote systems
- Verify database credentials in movefile.yml
- Check that the database user has appropriate permissions

### File Sync Issues

- Ensure rsync is installed on both systems
- Check file permissions on remote server
- Verify paths in movefile.yml are correct

### Validation

```bash
# Check your configuration for errors
movepress validate

# View system tool availability
movepress status
```

## Documentation

- **[Command Reference](docs/COMMANDS.md)** - Complete guide to all commands and options
- **[Configuration Reference](docs/CONFIGURATION.md)** - Detailed movefile.yml documentation
- **[Examples](docs/EXAMPLES.md)** - Common workflows and use cases
- **[Docker Setup](docs/DOCKER.md)** - Using Movepress with Docker environments
- **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Solutions to common problems
- **[Migration from Wordmove](docs/MIGRATION.md)** - Step-by-step guide for Wordmove users

## Development & Testing

### Running Tests

```bash
# Run unit and integration tests (fast)
./vendor/bin/phpunit

# Run Docker integration tests (requires Docker + built PHAR)
./vendor/bin/phpunit --group=docker
# Equivalent: ./vendor/bin/phpunit --testsuite=Docker
```

Docker tests are skipped unless you explicitly request them with `--group docker`/`--testsuite Docker` or export `MOVEPRESS_RUN_DOCKER_TESTS=1`.

### Docker Test Environment

A complete Docker-based integration testing environment spins up two WordPress environments (local and remote) and tests the full push/pull/backup workflow end-to-end.

```bash
# Via PHPUnit (recommended)
./vendor/bin/phpunit --group=docker

# Or via bash script
cd tests/docker && bash run-tests.sh
```

See [tests/Docker/README.md](tests/Docker/README.md) for details.

## Why Movepress?

### Moving Beyond Wordmove

[Wordmove](https://github.com/welaika/wordmove) has been the go-to WordPress deployment tool for years, but it hasn't been actively maintained and relies on Ruby dependencies that can be challenging to manage. Movepress is built from the ground up as a modern alternative that:

**Advantages over Wordmove:**

- ✅ **Zero Ruby dependencies** - Pure PHP, runs anywhere PHP runs
- ✅ **Single executable** - Distributed as a self-contained `.phar` file
- ✅ **Git-based deployments** - Modern workflow with Git for code, rsync for uploads
- ✅ **Actively maintained** - Modern codebase with ongoing support
- ✅ **Built-in SQL search-replace** - No wp-cli dependency
- ✅ **Better validation** - Comprehensive config validation and diagnostics
- ✅ **Improved error handling** - Clear error messages and troubleshooting tips
- ✅ **Modern PHP** - Takes advantage of PHP 8.1+ features

**Familiar workflow with improvements:**

- 📝 Same `movefile.yml` configuration format (mostly compatible!)
- 🔄 Same push/pull command structure
- 🎯 Simplified sync options (--db, --files)
- ⚙️ Same exclude pattern system
- 🔧 New git-setup command for modern deployments

**Migration from Wordmove:**
Your existing `movefile.yml` should work with minimal changes! The configuration format is designed to be compatible, so you can switch from `wordmove` to `movepress` commands with the same config file.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT
