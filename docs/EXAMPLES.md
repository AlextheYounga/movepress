# Examples

Common use cases and workflow examples for Movepress.

---

## Basic Workflows

### Deploy to Production

Push your local WordPress site to production:

```bash
# Push everything (with confirmation prompt)
movepress push local production --db --files

# Skip backup (not recommended)
movepress push local production --db --files --no-backup

# Preview changes first
movepress push local production --db --files --dry-run
```

---

### Pull from Production

Get a copy of your production site locally:

```bash
# Pull database and files
movepress pull production local --db --files

# Pull database only (most common)
movepress pull production local --db

# Pull with verbose output
movepress pull production local --db -v
```

---

### Sync to Staging

Push changes to staging before production:

```bash
# Push to staging for testing
movepress push local staging --db --files

# If it looks good, push to production
movepress push staging production --db --files
```

---

## File Syncing Patterns

### Sync Only Theme

Deploy just your custom theme:

```bash
movepress push local production --files --include="wp-content/themes/mytheme"
```

---

### Sync Only Plugin

Deploy a specific plugin:

```bash
movepress push local production --files --include="wp-content/plugins/myplugin"
```

---

### Sync Multiple Specific Items

Include multiple specific paths:

```bash
movepress push local production --files \
  --include="wp-content/themes/mytheme" \
  --include="wp-content/plugins/myplugin" \
  --include="wp-content/mu-plugins"
```

---

### Sync Content Without Uploads

Sync themes and plugins but exclude large uploads folder:

```bash
movepress push local production --content
```

This is equivalent to:
```bash
movepress push local production --files --exclude="wp-content/uploads/"
```

---

### Sync Only Uploads

Sync media files only:

```bash
movepress push local production --uploads
```

Useful when you've added new media locally and want to push it to production.

---

### Sync Recent Uploads

Sync only recent upload directories:

```bash
# Current year only
movepress push local production --files \
  --include="wp-content/uploads/2024"

# Specific months
movepress push local production --files \
  --include="wp-content/uploads/2024/01" \
  --include="wp-content/uploads/2024/02"
```

---

## Database Patterns

### Database Only Operations

Most common for pulling production data:

```bash
# Pull production database
movepress pull production local --db

# Push local database to staging
movepress push local staging --db
```

---

### Backup Before Pulling

Create a backup of local database before overwriting:

```bash
# Backup first (automatic, but can be manual)
movepress backup local

# Then pull
movepress pull production local --db
```

---

### Skip Backup (Use Carefully)

Skip automatic backup if you're sure:

```bash
movepress pull production local --db --no-backup
```

⚠️ **Warning:** Only use this if you have another backup or don't need the local data.

---

## Development Workflows

### Starting a New Feature

Pull fresh data from production:

```bash
# Get latest database
movepress pull production local --db

# Get any new uploads
movepress pull production local --uploads
```

---

### Testing Locally

Test changes with production data:

```bash
# Pull production database
movepress pull production local --db

# Work on your feature locally
# Test thoroughly

# Deploy when ready
movepress push local staging --files --include="wp-content/themes/mytheme"
```

---

### Deploying a Release

Safe deployment workflow:

```bash
# 1. Backup production first
movepress backup production

# 2. Preview the deployment
movepress push local production --files --dry-run

# 3. Deploy files only first
movepress push local production --files

# 4. Test the site

# 5. Deploy database if needed
movepress push local production --db
```

---

## Team Workflows

### Sharing Development Database

Team member shares their local database:

```bash
# Developer A: Push to staging
movepress push local staging --db

# Developer B: Pull from staging
movepress pull staging local --db
```

---

### Deploying Client Changes

Client made changes on staging, deploy to production:

```bash
# Pull from staging to local first
movepress pull staging local --db --files

# Review changes locally

# Push to production
movepress push staging production --db --files
```

---

## Maintenance Workflows

### Regular Backups

Create regular backups:

```bash
# Backup production database
movepress backup production

# Could be added to cron:
# 0 2 * * * /usr/local/bin/movepress backup production
```

---

### Pre-Update Backup

Before updating WordPress/plugins:

```bash
# Backup everything
movepress backup production

# Could also pull a full copy locally
movepress pull production local --db --files
```

---

### Restore from Backup

If you need to restore:

```bash
# Push your local backup to production
movepress push local production --db --files
```

---

## Multi-Environment Workflows

### Local → Staging → Production

Safe three-tier deployment:

```bash
# Step 1: Deploy to staging
movepress push local staging --db --files

# Step 2: Test on staging
# ... manual testing ...

# Step 3: Deploy staging to production
movepress push staging production --db --files
```

---

### Separate Database and Files

Deploy database and files separately:

```bash
# Deploy files first (safer)
movepress push local production --files

# Test the site

# Deploy database if needed
movepress push local production --db
```

---

## Emergency Workflows

### Quick Rollback

Production is broken, roll back to staging:

```bash
# Pull staging database back to production
movepress push staging production --db --files
```

---

### Restore Single File

Restore a single file from production:

```bash
movepress pull production local --files \
  --include="wp-content/themes/mytheme/functions.php"
```

---

## Advanced Patterns

### Conditional Deployment

Use dry-run to check before deploying:

```bash
# Check what would change
movepress push local production --files --dry-run

# Review output...

# Deploy if it looks good
movepress push local production --files
```

---

### Verbose Debugging

Debug sync issues with verbose output:

```bash
movepress push local production --db --files -v
```

This shows:
- Rsync commands being executed
- Database operations
- Search-replace operations
- File transfer progress

---

### Testing SSH Connection

Before a deployment, test the connection:

```bash
# Test connection
movepress ssh production

# Check configuration
movepress status production

# Then deploy
movepress push local production --files
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

# Preview
echo "Preview of changes:"
movepress push local production --files --dry-run

# Confirm
read -p "Deploy? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]
then
    movepress push local production --files
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
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      
      - name: Download Movepress
        run: |
          curl -O https://example.com/movepress.phar
          chmod +x movepress.phar
      
      - name: Deploy
        env:
          SSH_KEY: ${{ secrets.SSH_PRIVATE_KEY }}
        run: |
          echo "$SSH_KEY" > ~/.ssh/id_rsa
          chmod 600 ~/.ssh/id_rsa
          ./movepress.phar push local staging --files --no-interaction
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
    password: ""
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
