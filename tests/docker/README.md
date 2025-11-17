# Docker Test Environment

This directory contains a complete Docker-based integration testing environment for Movepress.

## Overview

The test environment simulates a real-world WordPress deployment scenario with two environments:

- **Local Container**: Represents your local development machine with WordPress + movepress
- **Remote Container**: Represents a remote production server with SSH access

This mirrors the real-world use case where movepress runs on your local machine and connects to a remote server.

## Architecture

```
┌─────────────────────────────┐         ┌─────────────────────┐
│  Local Container            │         │ Remote Container    │
│  (Your Dev Machine)         │         │ (Production Server) │
│                             │         │                     │
│  WordPress                  │         │  WordPress          │
│  MySQL (mysql-local:3306)   │  ────▶  │  MySQL (remote)     │
│  Movepress CLI              │   SSH   │  SSH Server (port 22)│
│  SSH/Rsync/Git clients      │         │  Git bare repo      │
│  http://localhost:8080      │         │  http://localhost:8081
│                             │         │                     │
│  movefile.yml mounted       │         │                     │
│  /var/www/html/             │         │  /var/www/html/     │
└─────────────────────────────┘         └─────────────────────┘
         Movepress runs HERE
```

**Key Points:**

- Movepress runs **inside** the local container (at `/usr/local/bin/movepress`)
- Configuration file is mounted at `/var/www/html/movefile.yml`
- Both containers are on the same Docker network
- SSH keys are mounted into local container at `/root/.ssh/`
- All commands execute from within the local container

## Prerequisites

- Docker and Docker Compose
- Movepress built as PHAR: `composer install && ./vendor/bin/box compile`

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

- URL: http://localhost:8080 (host machine can access)
- Admin: admin / admin
- Container: `docker exec -it movepress-local bash`

**Remote WordPress:**

- URL: http://localhost:8081 (host machine can access)
- Admin: admin / admin
- SSH from local container: `docker exec movepress-local ssh root@wordpress-remote`
- Container: `docker exec -it movepress-remote bash`
- Git repo: `/var/repos/movepress-test.git`

### Run Movepress commands

All movepress commands run **inside the local container**:

```bash
# Validate configuration
docker exec movepress-local movepress validate

# Check status
docker exec movepress-local movepress status

# Push database
docker exec movepress-local movepress push local remote --db

# Push files
docker exec movepress-local movepress push local remote --files

# Pull database
docker exec movepress-local movepress pull local remote --db

# Backup
docker exec movepress-local movepress backup local

# Test SSH connectivity
docker exec movepress-local ssh -o StrictHostKeyChecking=no root@wordpress-remote "echo 'SSH works'"
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

# Check movefile.yml is mounted correctly
docker exec movepress-local cat /var/www/html/movefile.yml
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
ls -la tests/Docker/ssh/

# Test SSH from local container to remote
docker exec movepress-local ssh -vvv -o StrictHostKeyChecking=no -i /root/.ssh/id_rsa root@wordpress-remote
```

### WordPress not installing

```bash
# Check container logs
docker logs movepress-local
docker logs movepress-remote

# Verify MySQL is ready
docker exec movepress-mysql-local mysql -uroot -proot -e "SELECT 1"
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
docker exec movepress-mysql-local mysql -uwordpress -pwordpress -e "SHOW DATABASES"
docker exec movepress-mysql-remote mysql -uwordpress -pwordpress -e "SHOW DATABASES"

# Check if WordPress is installed
docker exec movepress-local ls -la /var/www/html/wp-config.php
```

### Movepress command not found

```bash
# Verify movepress is mounted
docker exec movepress-local ls -la /usr/local/bin/movepress

# Check if phar exists on host
ls -la build/movepress.phar
```

### Configuration file not found

```bash
# Verify movefile.yml is mounted
docker exec movepress-local cat /var/www/html/movefile.yml
```

## Files

- `docker-compose.yml` - Container orchestration
- `movefile.yml` - Movepress test configuration (mounted into local container)
- `run-tests.sh` - Automated test runner
- `setup-ssh.sh` - SSH key generator
- `local/Dockerfile` - Local container image with SSH/rsync/git clients
- `local/entrypoint.sh` - Local environment setup
- `remote/Dockerfile` - Remote container image with SSH server
- `remote/entrypoint.sh` - Remote environment setup

## Notes

- Containers use ephemeral storage (no persistent volumes)
- SSH uses password `movepress` and key-based auth
- Host key checking is disabled for testing
- All data is reset on `docker-compose down -v`
