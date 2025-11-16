# Examples

Common use cases and workflow examples for Movepress.

> **💡 Using Docker?** See the [Docker Setup Guide](DOCKER.md) for configuration examples with Docker Compose.

---

## Initial Setup

### First-Time Configuration

```bash
# Initialize Movepress in your WordPress project
cd /path/to/wordpress
movepress init

# Edit movefile.yml and .env with your environment details
nano movefile.yml
nano .env

# Set up Git deployment for production (one-time)
movepress git:setup production

# Set up Git deployment for staging (one-time)
movepress git:setup staging
```

---

## Basic Workflows

### Deploy to Production

Modern Git-based deployment workflow:

```bash
# Commit your code changes
git add .
git commit -m "Update theme and plugins"

# Deploy code via Git
git push production master

# Sync database and untracked files
movepress push local production --db --untracked-files

# Or preview changes first
movepress push local production --db --untracked-files --dry-run

# Skip backup (not recommended)
movepress push local production --db --no-backup
```

---

### Pull from Production

Get a copy of your production site locally:

```bash
# Pull database and untracked files
movepress pull production local --db --untracked-files

# Pull database only (most common)
movepress pull production local --db

# Pull with verbose output
movepress pull production local --db -v
```

---

### Sync to Staging

Push changes to staging before production:

```bash
# Deploy code to staging
git push staging develop

# Sync database and uploads
movepress push local staging --db --untracked-files

# If it looks good, push to production
git push production master
movepress push staging production --db --untracked-files
```

---

## File Deployment Patterns

### Code vs. Uploads

Movepress uses **Git for code** (themes, plugins, core files) and **rsync for untracked files** (uploads, caches):

- **Code changes** (themes/plugins): Use `git push` to deploy
- **Media uploads**: Use `--untracked-files` flag with movepress
- **Database**: Use `--db` flag with movepress

---

### Deploy Theme or Plugin Changes

Code is managed via Git commits:

```bash
# Make changes to your theme/plugin
git add wp-content/themes/mytheme
git commit -m "Update theme"

# Deploy to production
git push production master
```

---

### Sync Uploads Only

Transfer media files without touching code:

```bash
# Push local uploads to production
movepress push local production --untracked-files

# Pull production uploads to local
movepress pull production local --untracked-files
```

---

### Selective Upload Sync

Use --include to sync specific upload directories:

```bash
# Current year only
movepress push local production --untracked-files \
  --include="wp-content/uploads/2024"

# Specific months
movepress push local production --untracked-files \
  --include="wp-content/uploads/2024/01" \
  --include="wp-content/uploads/2024/02"
```

---

## Database Patterns

```bash
# Pull production database (most common)
movepress pull production local --db

# Push local database to staging
movepress push local staging --db

# Manual backup (automatic by default)
movepress backup local

# Skip backup (use carefully)
movepress pull production local --db --no-backup
```

---

## Development Workflows

```bash
# Start new feature: pull fresh production data
movepress pull production local --db --untracked-files

# Safe deployment: backup, commit, deploy, test
movepress backup production
git push production master
movepress push local production --db  # if needed
```

---

## Team Workflows

```bash
# Share database between team members via staging
movepress push local staging --db      # Developer A
movepress pull staging local --db      # Developer B

# Deploy client changes from staging to production
movepress pull staging local --db --untracked-files  # Review
git push production master
movepress push staging production --db --untracked-files
```

---

## Maintenance Workflows

```bash
# Regular backups (uses backup_path from movefile.yml)
movepress backup production

# Backup to custom location
movepress backup production --output=/custom/backup/path

# Restore database and uploads
movepress push local production --db --untracked-files

# Restore code to previous commit
git push production <commit-hash>:refs/heads/master --force
```

Configure `backup_path` in `movefile.yml` for automated backup locations. Can be added to cron for scheduled backups.

---

## Multi-Environment Workflows

### Local → Staging → Production

Safe three-tier deployment:

```bash
# Step 1: Deploy code and data to staging
git push staging develop
movepress push local staging --db --untracked-files

# Step 2: Test on staging
# ... manual testing ...

# Step 3: Deploy staging to production
git push production master
movepress push staging production --db --untracked-files
```

---

### Separate Database and Code

Deploy database and code separately:

```bash
# Deploy code first (safer)
git push production master

# Test the site

# Deploy database if needed
movepress push local production --db
```

---

## Emergency Workflows

```bash
# Quick rollback: revert to previous commit
git push production HEAD~1:refs/heads/master --force
movepress push staging production --db  # restore DB from staging

# Restore single file
movepress pull production local --untracked-files \
  --include="wp-content/uploads/2024/01/logo.png"
```

---

## Advanced Patterns

```bash
# Preview changes before deployment
movepress push local production --untracked-files --dry-run

# Verbose debugging (shows rsync commands, DB operations, etc.)
movepress push local production --db --untracked-files -v

# Test SSH connection before deployment
movepress ssh production
movepress status production
```

---

## Automation Examples

### Cron Job for Backups

Add to crontab (`crontab -e`):

```bash
# Backup production database daily at 2 AM
0 2 * * * /usr/local/bin/movepress backup production

# Pull production to staging weekly
0 3 * * 0 /usr/local/bin/movepress pull production staging --db --no-interaction
```

---

### Deployment Script

Create a deployment script (`deploy.sh`):

```bash
#!/bin/bash

# Deploy to production with safety checks
echo "Deploying to production..."

# Backup first
echo "Creating backup..."
movepress backup production

# Check for uncommitted changes
if [[ -n $(git status -s) ]]; then
    echo "Error: You have uncommitted changes"
    exit 1
fi

# Confirm
read -p "Deploy code and uploads? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]
then
    git push production master
    movepress push local production --untracked-files
    echo "Deployment complete!"
fi
```

---

### CI/CD Integration

Example GitHub Actions workflow:

```yaml
name: Deploy to Staging

on:
    push:
        branches: [main]

jobs:
    deploy:
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v2

            - name: Setup SSH
              env:
                  SSH_KEY: ${{ secrets.SSH_PRIVATE_KEY }}
              run: |
                  mkdir -p ~/.ssh
                  echo "$SSH_KEY" > ~/.ssh/id_rsa
                  chmod 600 ~/.ssh/id_rsa

            - name: Deploy Code
              run: |
                  git remote add staging ssh://deploy@staging.example.com/var/repos/mysite.git
                  git push staging main:master

            - name: Setup PHP
              uses: shivammathur/setup-php@v2
              with:
                  php-version: '8.1'

            - name: Download Movepress
              run: |
                  curl -O https://example.com/movepress.phar
                  chmod +x movepress.phar

            - name: Sync Database
              run: |
                  ./movepress.phar push local staging --db --no-interaction
```

---

## Configuration Examples

### Simple Two-Environment Setup

```yaml
local:
    path: /Users/alex/Sites/mysite
    url: http://mysite.test
    database:
        name: wp_local
        user: root
        password: ''
        host: localhost

production:
    path: /var/www/html
    url: https://example.com
    ssh:
        host: server.example.com
        user: deploy
        key: ~/.ssh/id_rsa
    database:
        name: ${PROD_DB_NAME}
        user: ${PROD_DB_USER}
        password: ${PROD_DB_PASSWORD}
        host: localhost
```

---

### Multi-Environment Setup

```yaml
local:
    # ... local config

dev:
    # Shared development server
    path: /var/www/dev
    url: https://dev.example.com
    ssh:
        host: dev.example.com
        user: developer
    database:
        name: wp_dev
        user: dev_user
        password: ${DEV_DB_PASSWORD}
        host: localhost

staging:
    # Pre-production testing
    path: /var/www/staging
    url: https://staging.example.com
    ssh:
        host: staging.example.com
        user: deploy
    database:
        name: wp_staging
        user: staging_user
        password: ${STAGING_DB_PASSWORD}
        host: localhost

production:
    # Live site
    path: /var/www/production
    url: https://example.com
    ssh:
        host: production.example.com
        user: deploy
    database:
        name: wp_production
        user: prod_user
        password: ${PROD_DB_PASSWORD}
        host: localhost
```

---

## See Also

- [Command Reference](COMMANDS.md)
- [Configuration Reference](CONFIGURATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
