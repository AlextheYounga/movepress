# Movepress

A modern WordPress deployment tool for pushing and pulling databases and files between environments via SSH.

## Features

- 🚀 Push/pull WordPress databases with automatic search-replace
- 📁 Sync files using rsync over SSH
- 🔐 Environment variable support in configuration
- 🎯 Flexible exclude patterns (global and per-environment)
- 📦 Bundled with wp-cli - no separate installation needed
- ⚡ Single executable `.phar` file

## Installation

### Download the PHAR (recommended)

```bash
curl -O https://example.com/movepress.phar
chmod +x movepress.phar
sudo mv movepress.phar /usr/local/bin/movepress
```

### Build from source

```bash
git clone https://github.com/movepress/movepress.git
cd movepress
composer install
composer build
```

## Quick Start

1. Create a `movefile.yml` in your WordPress root:

```bash
cp movefile.yml.example movefile.yml
```

2. Create a `.env` file with your credentials:

```bash
cp .env.example .env
```

3. Edit both files with your environment details

4. Deploy:

```bash
# Push database and files to production
movepress push production --db --files

# Pull database from staging
movepress pull staging --db

# Pull only uploads from production
movepress pull production --files --include="wp-content/uploads/"
```

## Commands

### Core Commands

- `movepress push <source> <destination>` - Push files/database from source to destination
- `movepress pull <source> <destination>` - Pull files/database from source to destination
- `movepress init` - Initialize a new movefile.yml configuration
- `movepress status` - Show system tools availability and configured environments
- `movepress validate` - Validate your movefile.yml configuration
- `movepress ssh <environment>` - Test SSH connectivity to an environment
- `movepress backup <environment>` - Create a database backup for an environment

### Push/Pull Options

- `--db` - Sync database only
- `--files` - Sync all files
- `--content` - Sync themes and plugins only (excludes uploads)
- `--uploads` - Sync uploads only
- `--include=<pattern>` - Include specific files/folders
- `--dry-run` - Preview changes without making them
- `--no-backup` - Skip backup before database import
- `-v, --verbose` - Show detailed output

### Examples

```bash
# Push everything to production
movepress push local production --db --files

# Pull database only from staging
movepress pull staging local --db

# Sync only uploads from production
movepress pull production local --uploads

# Preview what would be pushed (dry run)
movepress push local staging --db --files --dry-run

# Push with verbose output
movepress push local production --files -v

# Create a backup before making changes
movepress backup production
```

## Configuration

The `movefile.yml` file defines your environments and sync settings:

```yaml
local:
  path: /path/to/wordpress
  url: http://local.test
  database:
    name: wp_local
    user: root
    password: ""
    host: localhost

production:
  path: /var/www/html
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

# Global exclude patterns
exclude:
  - ".git/"
  - "node_modules/"
  - ".env"
```

### Configuration Options

**Required for each environment:**
- `path` - WordPress installation path
- `url` - WordPress site URL
- `database` - Database credentials (name, user, password, host)

**Optional:**
- `ssh` - SSH connection details (host, user, port, key) for remote environments
- `wordpress_path` - Path to WordPress core files (if different from `path`)
- `exclude` - Environment-specific exclude patterns

**Environment Variables:**
Use `${VAR_NAME}` syntax to reference variables from your `.env` file.

## Requirements

- PHP 8.1 or higher
- SSH access to remote servers
- rsync installed on local and remote systems
- mysql/mysqldump command-line tools
- wp-cli (bundled with Movepress)

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
- **[Troubleshooting](docs/TROUBLESHOOTING.md)** - Solutions to common problems

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

MIT
