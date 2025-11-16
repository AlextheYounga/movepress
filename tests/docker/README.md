# Docker Test Environment

This directory contains a complete Docker-based integration testing environment for Movepress.

## Overview

The test environment simulates a real-world WordPress deployment scenario with two environments:

- **Local**: Source environment (simulates dev machine)
- **Remote**: Destination environment with SSH access (simulates production server)

## Architecture

```
┌─────────────────────┐         ┌─────────────────────┐
│  Local Environment  │         │ Remote Environment  │
│                     │         │                     │
│  WordPress          │         │  WordPress          │
│  MySQL (port 3306)  │  ────▶  │  MySQL (port 3307)  │
│  http://localhost:8080        │  SSH (port 2222)    │
│                     │         │  Git bare repo      │
│                     │         │  http://localhost:8081
└─────────────────────┘         └─────────────────────┘
           │                               │
           └───────── Movepress ───────────┘
                  (runs from host)
```

## Prerequisites

- Docker and Docker Compose
- Movepress built as PHAR: `composer install && ./vendor/bin/box compile`
- Standard command-line tools: `ssh`, `rsync`, `mysql` (should be on host)

## Quick Start

### Option 1: PHPUnit (Recommended)

```bash
# From project root
composer install
./vendor/bin/box compile

# Run Docker integration tests
./vendor/bin/phpunit --testsuite=Docker

# Or run all tests including Docker
./vendor/bin/phpunit --group=docker
```

### Option 2: Bash Script

```bash
# From project root
cd tests/docker

# Run the full test suite
bash run-tests.sh
```

Both approaches will:

1. Generate SSH keys for testing
2. Build and start all containers
3. Install WordPress in both environments
4. Run movepress commands (push, pull, backup)
5. Validate results
6. Clean up containers

## Manual Testing

### Start the environment

```bash
cd tests/docker

# Generate SSH keys (first time only)
bash setup-ssh.sh

# Start containers
docker compose up -d

# Watch logs
docker compose logs -f
```

### Access the environments

**Local WordPress:**

- URL: http://localhost:8080
- Admin: admin / admin
- Database: localhost:3306

**Remote WordPress:**

- URL: http://localhost:8081
- Admin: admin / admin
- SSH: `ssh -i ssh/id_rsa -p 2222 root@localhost`
- Database: localhost:3307
- Git repo: `/var/repos/movepress-test.git`

### Run Movepress commands

```bash
# From project root
cd ../..

# Validate configuration
./build/movepress.phar validate --config=tests/docker/movefile.yml

# Check status
./build/movepress.phar status --config=tests/docker/movefile.yml

# Push database
./build/movepress.phar push local remote --db --config=tests/docker/movefile.yml

# Push files
./build/movepress.phar push local remote --files --config=tests/docker/movefile.yml

# Pull database
./build/movepress.phar pull local remote --db --config=tests/docker/movefile.yml

# Backup
./build/movepress.phar backup local --config=tests/docker/movefile.yml
```

### Inspect containers

```bash
# View logs
docker logs movepress-local
docker logs movepress-remote

# Execute commands in containers
docker exec -it movepress-local bash
docker exec -it movepress-remote bash

# Check database
docker exec movepress-mysql-local mysql -uwordpress -pwordpress wordpress_local -e "SELECT * FROM wp_posts"
docker exec movepress-mysql-remote mysql -uwordpress -pwordpress wordpress_remote -e "SELECT * FROM wp_posts"
```

## Test Data

Both environments are pre-populated with:

- WordPress core installation
- Admin user: `admin` / `admin`
- Test posts with environment-specific content
- Sample upload files in `wp-content/uploads/2024/11/`

**Local environment:**

- URL: `http://localhost:8080`
- Test file: `test-local.txt`

**Remote environment:**

- URL: `http://localhost:8081`
- Test file: `test-remote.txt`
- Git bare repository at `/var/repos/movepress-test.git`

## What Gets Tested

1. **Configuration validation** - `movefile.yml` parsing and validation
2. **System status** - Checking for required binaries (rsync, mysql, etc.)
3. **Database push** - Export, transfer, import, search-replace
4. **File sync** - Rsync over SSH with proper excludes
5. **Database pull** - Reverse direction database transfer
6. **Backup** - Local database backup creation

## Cleanup

```bash
# Stop and remove containers
cd tests/docker
docker compose down

# Remove all data (including volumes)
docker compose down -v

# Remove SSH keys (optional)
rm -rf ssh/
```

## Troubleshooting

### SSH connection fails

```bash
# Verify SSH service is running
docker exec movepress-remote service ssh status

# Check SSH key permissions
ls -la tests/docker/ssh/

# Test SSH manually
ssh -vvv -i tests/docker/ssh/id_rsa -p 2222 root@localhost
```

### WordPress not installing

```bash
# Check container logs
docker logs movepress-local
docker logs movepress-remote

# Verify MySQL is ready
docker exec movepress-mysql-local mysqladmin ping -uroot -proot
```

### Permission errors

```bash
# Fix WordPress file permissions
docker exec movepress-remote chown -R www-data:www-data /var/www/html
docker exec movepress-remote chmod -R 755 /var/www/html
```

### Database connection fails

```bash
# Verify databases exist
docker exec movepress-mysql-local mysql -uroot -proot -e "SHOW DATABASES"
docker exec movepress-mysql-remote mysql -uroot -proot -e "SHOW DATABASES"

# Check if WordPress is installed
docker exec movepress-local php /usr/local/bin/movepress core is-installed --path=/var/www/html
```

## Files

- `docker-compose.yml` - Container orchestration
- `movefile.yml` - Movepress test configuration
- `run-tests.sh` - Automated test runner
- `setup-ssh.sh` - SSH key generator
- `local/entrypoint.sh` - Local environment setup
- `remote/Dockerfile` - Remote container image
- `remote/entrypoint.sh` - Remote environment setup

## Notes

- Containers use ephemeral storage (no persistent volumes)
- SSH uses password `movepress` and key-based auth
- Host key checking is disabled for testing
- All data is reset on `docker-compose down -v`
