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

## Configuration

See `movefile.yml.example` for a complete configuration reference.

## Requirements

- PHP 8.1 or higher
- SSH access to remote servers
- rsync installed on local and remote systems

## License

MIT
