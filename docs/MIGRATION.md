# Migrating from Wordmove

Guide for migrating from Wordmove to Movepress.

---

## Why Migrate?

[Wordmove](https://github.com/welaika/wordmove) has been unmaintained for several years and relies on Ruby dependencies that can be challenging to manage. Movepress is a modern successor built in PHP that:

- ✅ Requires no Ruby installation
- ✅ Ships as a single executable file
- ✅ Bundles wp-cli (no separate installation)
- ✅ Has active development and support
- ✅ Provides better error messages
- ✅ Includes built-in diagnostics

**Best part:** Your existing `movefile.yml` should work with minimal changes!

---

## Quick Migration

### 1. Install Movepress

Replace Wordmove with Movepress:

```bash
# Remove Wordmove (optional)
gem uninstall wordmove

# Build Movepress from source
git clone https://github.com/AlextheYounga/movepress.git
cd movepress
composer install --no-dev
./vendor/bin/box compile
sudo mv ./build/movepress.phar /usr/local/bin/movepress
```

### 2. Test Your Configuration

Your existing `movefile.yml` should work:

```bash
# Validate configuration
movepress validate

# Check system status
movepress status
```

### 3. Try a Safe Operation

Test with a pull operation first:

```bash
# Pull database from production (same as Wordmove)
movepress pull production local --db
```

---

## Command Comparison

Commands are nearly identical:

### Wordmove

```bash
wordmove push production --db --wordpress
wordmove pull staging --db
wordmove init
```

### Movepress

```bash
# Code deployment via Git
git push production master

# Database and uploads via movepress
movepress push local production --db --untracked-files
movepress pull staging local --db
movepress init
```

**Main differences:**

- Movepress uses **Git for code** (themes, plugins, core files)
- Movepress uses `--untracked-files` for uploads/caches only
- Movepress requires explicit source and destination environments
- One-time Git setup required: `movepress git:setup production`

---

## Configuration Compatibility

### What Works Unchanged

Most `movefile.yml` configurations work as-is:

```yaml
# This config works in both Wordmove and Movepress
local:
    path: /path/to/wordpress
    url: http://local.test
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
        port: 22
    database:
        name: wp_production
        user: prod_user
        password: secret123
        host: localhost

exclude:
    - '.git/'
    - 'node_modules/'
```

---

## Configuration Differences

### SSH Configuration

**Wordmove:**

```yaml
production:
    ssh:
        host: server.example.com
        user: deploy
        port: 22
        # Wordmove uses 'rsync_options' and 'ssh_options'
```

**Movepress:**

```yaml
production:
    ssh:
        host: server.example.com
        user: deploy
        port: 22
        key: ~/.ssh/id_rsa # Explicit key path
```

**Migration:** Add explicit `key` path if using non-default SSH key.

---

### Database Configuration

**Wordmove:**

```yaml
database:
    name: wp_db
    user: user
    password: pass
    host: localhost
    # Optional charset, collate
```

**Movepress:**

```yaml
database:
    name: wp_db
    user: user
    password: pass
    host: localhost
    # Charset and collate handled automatically by wp-cli
```

**Migration:** Remove `charset` and `collate` if present (handled automatically).

---

### FTP/SFTP Support

**Wordmove:** Supports FTP/SFTP

**Movepress:** SSH/rsync only (more reliable for WordPress)

**Migration:** If you're using FTP in Wordmove, you'll need SSH access for Movepress.

---

## Command Reference

### Push Operations

| Wordmove                               | Movepress                                                                              | Notes                  |
| -------------------------------------- | -------------------------------------------------------------------------------------- | ---------------------- |
| `wordmove push production --db`        | `movepress push local production --db`                                                 | Explicit source needed |
| `wordmove push production --wordpress` | `git push production master`                                                           | **Code via Git now**   |
| `wordmove push production --themes`    | `git add wp-content/themes && git push production master`                              | Git commits            |
| `wordmove push production --plugins`   | `git add wp-content/plugins && git push production master`                             | Git commits            |
| `wordmove push production --uploads`   | `movepress push local production --untracked-files`                                    | Renamed flag           |
| `wordmove push production --all`       | `git push production master && movepress push local production --db --untracked-files` | Git + movepress        |

---

### Pull Operations

| Wordmove                               | Movepress                                           | Notes                |
| -------------------------------------- | --------------------------------------------------- | -------------------- |
| `wordmove pull production --db`        | `movepress pull production local --db`              | Explicit destination |
| `wordmove pull production --wordpress` | `git pull production master`                        | **Code via Git now** |
| `wordmove pull production --uploads`   | `movepress pull production local --untracked-files` | Renamed flag         |

---

### Other Commands

| Wordmove        | Movepress                | Notes                   |
| --------------- | ------------------------ | ----------------------- |
| `wordmove init` | `movepress init`         | Identical               |
| N/A             | `movepress status`       | New: system diagnostics |
| N/A             | `movepress validate`     | New: config validation  |
| N/A             | `movepress ssh <env>`    | New: test SSH           |
| N/A             | `movepress backup <env>` | New: standalone backups |

---

## Feature Comparison

### Present in Both

- ✅ Push/pull databases with search-replace
- ✅ Push/pull files with rsync
- ✅ Exclude patterns (global and per-env)
- ✅ SSH support
- ✅ Environment variables in config
- ✅ Backup before database import
- ✅ Dry-run mode

### Only in Movepress

- ✅ Built-in wp-cli (no separate install)
- ✅ Configuration validation (`movepress validate`)
- ✅ System diagnostics (`movepress status`)
- ✅ SSH testing (`movepress ssh`)
- ✅ Standalone backup command
- ✅ Better error messages with troubleshooting tips
- ✅ Progress indicators
- ✅ Single executable file

### Only in Wordmove

- FTP/SFTP support (Movepress is SSH/rsync only)
- SQL adapter options (Movepress uses wp-cli for all DB operations)

---

## Common Migration Issues

### Issue: "SSH key not found"

**Problem:** Wordmove used default SSH key, Movepress needs explicit path.

**Solution:** Add `key` to SSH config:

```yaml
production:
    ssh:
        host: server.example.com
        user: deploy
        key: ~/.ssh/id_rsa # Add this
```

---

### Issue: "wp-cli not found"

**Problem:** You were using system wp-cli with Wordmove.

**Solution:** Movepress bundles wp-cli! Just verify:

```bash
movepress status
# Should show: wp-cli ✓ Available
```

---

### Issue: "mysql commands not found"

**Problem:** Wordmove used SQL adapters, Movepress needs mysql tools.

**Solution:** Install MySQL client:

```bash
# macOS
brew install mysql-client

# Ubuntu/Debian
sudo apt-get install mysql-client
```

---

### Issue: "Source environment required"

**Problem:** Wordmove inferred source as `local`.

**Solution:** Always specify source and destination:

```bash
# Wordmove
wordmove push production --db

# Movepress
movepress push local production --db
```

---

## Migration Workflow

### Step 1: Install Alongside

Keep Wordmove installed while testing:

```bash
# Build Movepress
git clone https://github.com/AlextheYounga/movepress.git
cd movepress
composer install --no-dev
./vendor/bin/box compile
sudo cp ./build/movepress.phar /usr/local/bin/movepress

# Both are now available
wordmove --version
movepress --version
```

---

### Step 2: Validate Configuration

Test your `movefile.yml`:

```bash
# Check configuration
movepress validate

# Check system tools
movepress status

# Test SSH connections
movepress ssh production
```

---

### Step 3: Test with Safe Operations

Start with read-only operations:

```bash
# Pull database (safe, overwrites local only)
movepress pull production local --db

# Pull uploads (safe)
movepress pull production local --untracked-files

# Preview push (doesn't execute)
movepress push local staging --db --untracked-files --dry-run
```

---

### Step 4: Update Scripts/CI

Update deployment scripts:

**Old (Wordmove):**

```bash
#!/bin/bash
wordmove push production --db --wordpress
```

**New (Movepress):**

```bash
#!/bin/bash
# Deploy code via Git
git push production master

# Sync database and uploads
movepress push local production --db --untracked-files
```

---

### Step 5: Remove Wordmove

Once confident:

```bash
gem uninstall wordmove
```

---

## Team Migration

### Gradual Migration

Teams can migrate gradually:

```yaml
# movefile.yml works with both tools
local:
    path: /path/to/wordpress
    url: http://local.test
    # ... standard config

production:
    # ... standard config
```

**Instructions for team:**

```bash
# Option A: Still using Wordmove
wordmove pull production --db

# Option B: Using Movepress
movepress pull production local --db
```

---

### Documentation Updates

Update internal docs with command changes:

````markdown
## Deployment (Updated)

### Using Movepress (Recommended)

```bash
# Deploy code
git push production master

# Sync database and uploads
movepress push local production --db --untracked-files
```
````

### Using Wordmove (Legacy)

```bash
wordmove push production --db --wordpress
```

````

---

## Benefits After Migration

### Simplified Setup

**Before (Wordmove):**
```bash
# Install Ruby
# Install Ruby gems
# Install wp-cli separately
gem install wordmove
gem install specific versions for compatibility
````

**After (Movepress):**

```bash
# Build once, run anywhere
git clone https://github.com/AlextheYounga/movepress.git
cd movepress
composer install --no-dev
./vendor/bin/box compile
# Single PHAR file ready to use
```

---

### Better Diagnostics

**Wordmove:**

```
Error: Something went wrong
```

**Movepress:**

```
SSH connection failed

Troubleshooting tips:
- Verify SSH key permissions: chmod 600 ~/.ssh/id_rsa
- Test manually: ssh -i ~/.ssh/id_rsa user@host
- Run diagnostics: movepress ssh production
```

---

### Built-in Validation

**Wordmove:** Errors appear during execution

**Movepress:** Catch issues early

```bash
movepress validate
# Lists all config errors before attempting deployment
```

---

## Getting Help

### For Migration Issues

```bash
# Validate config
movepress validate

# Check system
movepress status

# Test connections
movepress ssh production

# Try with verbose output
movepress push local production --untracked-files -v
```

### Documentation

- [Command Reference](COMMANDS.md)
- [Configuration Reference](CONFIGURATION.md)
- [Examples](EXAMPLES.md)
- [Troubleshooting](TROUBLESHOOTING.md)

---

## Success Stories

_Share your migration story!_ Open an issue or PR to add your experience.

---

## FAQ

### Q: Will my movefile.yml work?

**A:** Most configs work with minimal changes. Run `movepress validate` to check.

---

### Q: Can I use both tools?

**A:** Yes! The config format is compatible, so you can use both during migration.

---

### Q: What about FTP deployments?

**A:** Movepress requires SSH/rsync. If you're on FTP-only hosting, consider upgrading your hosting or continue using Wordmove.

---

### Q: Is there a performance difference?

**A:** Both use rsync for files, so performance is similar. Database operations may be faster with Movepress due to modern PHP and bundled wp-cli.

---

### Q: What about Ruby version issues?

**A:** Gone! Movepress is pure PHP, no Ruby dependencies.

---

## Conclusion

Movepress brings the power of Wordmove to modern PHP with:

- Zero Ruby dependencies
- Better error handling
- Built-in diagnostics
- Active maintenance

Your existing workflow and configs should work with minimal changes. Welcome to the modern era of WordPress deployments!

---

## See Also

- [Command Reference](COMMANDS.md)
- [Configuration Reference](CONFIGURATION.md)
- [Examples](EXAMPLES.md)
- [Troubleshooting](TROUBLESHOOTING.md)
