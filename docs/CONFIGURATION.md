# Configuration Reference

Complete reference for `movefile.yml` configuration file.

---

## File Structure

```yaml
# Environment definitions
local:
  # ... environment config

production:
  # ... environment config

# Global settings
exclude:
  - pattern1
  - pattern2
```

---

## Environment Configuration

Each environment requires specific configuration options.

### Required Fields

#### path
The absolute path to your WordPress installation.

```yaml
local:
  path: /Users/username/Sites/mysite
```

#### url
The full URL of your WordPress site (including protocol).

```yaml
local:
  url: http://mysite.test
```

#### database
Database connection credentials.

```yaml
local:
  database:
    name: wp_local
    user: root
    password: ""
    host: localhost
```

**Database fields:**
- `name` - Database name
- `user` - Database username
- `password` - Database password (use environment variables for security)
- `host` - Database host (usually "localhost")

---

## Optional Fields

### wordpress_path
Path to WordPress core files if different from `path`. Used by wp-cli.

```yaml
local:
  path: /var/www/mysite
  wordpress_path: /var/www/mysite/public/wp
```

Default: Same as `path`

### ssh
SSH configuration for remote environments. **Required for remote servers.**

```yaml
production:
  ssh:
    host: server.example.com
    user: deploy
    port: 22
    key: ~/.ssh/id_rsa
```

**SSH fields:**
- `host` - SSH hostname or IP address
- `user` - SSH username
- `port` - SSH port (default: 22)
- `key` - Path to SSH private key (supports `~` for home directory)

### git
Git deployment configuration (optional). Used by `git:setup` command.

```yaml
production:
  git:
    repo_path: /var/repos/mysite.git
```

**Git fields:**
- `repo_path` - Path to bare Git repository on remote server (optional, defaults to `/var/repos/{site}.git`)

When configured, use Git to deploy code changes:
```bash
# One-time setup
movepress git:setup production

# Deploy code
git push production master

# Sync database and uploads
movepress push local production --db --untracked-files
```

### exclude
Environment-specific exclude patterns. These are merged with global excludes.

```yaml
local:
  exclude:
    - "local-config.php"
    - "debug.log"
```

---

## Global Settings

### exclude
Global exclude patterns applied to all environments during file sync.

```yaml
exclude:
  - ".git/"
  - ".gitignore"
  - "node_modules/"
  - ".DS_Store"
  - ".env"
  - ".env.*"
  - "composer.lock"
  - "package-lock.json"
```

**Exclude patterns:**
- Use trailing slash for directories: `"folder/"`
- Use wildcards: `"*.log"`, `"cache/*"`
- Patterns are passed to rsync's `--exclude` flag

---

## Environment Variables

Use `${VAR_NAME}` syntax to reference environment variables from your `.env` file.

### Example movefile.yml

```yaml
production:
  database:
    name: ${PROD_DB_NAME}
    user: ${PROD_DB_USER}
    password: ${PROD_DB_PASSWORD}
    host: localhost
  ssh:
    host: ${PROD_SSH_HOST}
    user: ${PROD_SSH_USER}
```

### Example .env

```bash
PROD_DB_NAME=wp_production
PROD_DB_USER=prod_user
PROD_DB_PASSWORD=super_secret_password
PROD_SSH_HOST=server.example.com
PROD_SSH_USER=deploy
```

**Security tip:** Never commit `.env` to version control!

---

## Complete Example

```yaml
# Local development environment
local:
  path: /Users/alex/Sites/mysite
  url: http://mysite.test
  database:
    name: wp_local
    user: root
    password: ""
    host: localhost
  exclude:
    - "wp-config-local.php"

# Staging server
staging:
  path: /var/www/staging
  url: https://staging.example.com
  ssh:
    host: staging.example.com
    user: deploy
    port: 22
    key: ~/.ssh/staging_key
  database:
    name: ${STAGING_DB_NAME}
    user: ${STAGING_DB_USER}
    password: ${STAGING_DB_PASSWORD}
    host: localhost
  exclude:
    - "wp-config-staging.php"

# Production server
production:
  path: /var/www/production
  url: https://example.com
  ssh:
    host: production.example.com
    user: deploy
    port: 22
    key: ~/.ssh/production_key
  database:
    name: ${PROD_DB_NAME}
    user: ${PROD_DB_USER}
    password: ${PROD_DB_PASSWORD}
    host: localhost
  exclude:
    - "wp-config-production.php"

# Global exclude patterns
exclude:
  - ".git/"
  - ".gitignore"
  - "node_modules/"
  - ".DS_Store"
  - ".env"
  - ".env.*"
  - "composer.lock"
  - "package-lock.json"
  - "*.log"
  - ".htaccess"
```

---

## Validation

Validate your configuration with:

```bash
movepress validate
```

This checks:
- YAML syntax
- Required fields presence
- Database configuration completeness
- SSH configuration for remote environments
- URL format
- Path accessibility

---

## File Location

Movepress looks for `movefile.yml` in:

1. Current working directory
2. Parent directories (up to root)

You can also specify a custom location (not yet implemented).

---

## Best Practices

### Security

1. **Use environment variables** for sensitive data:
   ```yaml
   database:
     password: ${DB_PASSWORD}
   ```

2. **Never commit `.env`** to version control:
   ```bash
   echo ".env" >> .gitignore
   ```

3. **Use SSH keys** instead of passwords for remote access

### Excludes

1. **Always exclude version control:**
   ```yaml
   exclude:
     - ".git/"
     - ".svn/"
   ```

2. **Exclude development dependencies:**
   ```yaml
   exclude:
     - "node_modules/"
     - "vendor/"
   ```

3. **Exclude sensitive files:**
   ```yaml
   exclude:
     - ".env"
     - "wp-config-local.php"
   ```

### Organization

1. **Use descriptive environment names:**
   - ✅ `local`, `staging`, `production`
   - ❌ `env1`, `env2`, `server`

2. **Document custom configurations** with comments:
   ```yaml
   production:
     # Custom WordPress installation structure
     path: /var/www/production
     wordpress_path: /var/www/production/wp
   ```

3. **Group related environments:**
   ```yaml
   # Development environments
   local:
     # ...
   dev:
     # ...
   
   # Production environments
   staging:
     # ...
   production:
     # ...
   ```

---

## Troubleshooting

### Configuration not found

**Problem:** `Configuration file not found: movefile.yml`

**Solution:** Run `movepress init` or create `movefile.yml` manually.

### Invalid YAML syntax

**Problem:** `Error parsing YAML`

**Solution:** Validate YAML syntax at https://yaml-checker.com

### Environment variable not resolved

**Problem:** `${VAR_NAME}` appearing as literal text

**Solution:** 
1. Create `.env` file in same directory as `movefile.yml`
2. Define variable: `VAR_NAME=value`
3. Ensure no spaces around `=`

### SSH connection fails

**Problem:** Cannot connect to remote environment

**Solution:**
1. Test connection manually: `ssh user@host`
2. Verify SSH key permissions: `chmod 600 ~/.ssh/id_rsa`
3. Check SSH config in `movefile.yml`
4. Use `movepress ssh <environment>` to diagnose

---

## See Also

- [Command Reference](COMMANDS.md)
- [Examples](EXAMPLES.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
