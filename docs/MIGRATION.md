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
- One-time Git setup required: `movepress git-setup production`

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

- **SSH key not found:** Add explicit `key: ~/.ssh/id_rsa` to SSH config
- **wp-cli not found:** Movepress bundles wp-cli (verify with `movepress status`)
- **mysql commands not found:** Install MySQL client (`brew install mysql-client` or `apt-get install mysql-client`)
- **Source environment required:** Always specify both environments (`movepress push local production --db`)

---

## Migration Workflow

### 1. Install and Validate

```bash
# Build Movepress
git clone https://github.com/AlextheYounga/movepress.git
cd movepress
composer install --no-dev
./vendor/bin/box compile
sudo cp ./build/movepress.phar /usr/local/bin/movepress

# Validate setup
movepress validate
movepress status
movepress ssh production
```

### 2. Test with Safe Operations

```bash
# Start with read-only pulls
movepress pull production local --db
movepress pull production local --untracked-files

# Preview pushes
movepress push local staging --db --untracked-files --dry-run
```

### 3. Update Deployment Scripts

Replace Wordmove commands with Git + Movepress:

```bash
# Old: wordmove push production --db --wordpress
# New:
git push production master
movepress push local production --db --untracked-files
```

---

## Team Migration

Teams can migrate gradually since `movefile.yml` works with both tools:

```bash
# Team member A (still using Wordmove)
wordmove pull production --db

# Team member B (using Movepress)
movepress pull production local --db
```

Update team documentation to include both options during transition period.

---

## Benefits After Migration

| Feature              | Wordmove                    | Movepress                                    |
| -------------------- | --------------------------- | -------------------------------------------- |
| **Dependencies**     | Ruby + gems + system wp-cli | Single PHAR file                             |
| **Error Messages**   | Generic errors              | Detailed troubleshooting tips                |
| **Validation**       | Errors during execution     | Pre-flight validation (`movepress validate`) |
| **Diagnostics**      | Manual testing              | Built-in system checks (`movepress status`)  |
| **Setup Complexity** | Ruby version management     | Build once, run anywhere                     |

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

## FAQ

**Q: Will my movefile.yml work?**  
A: Most configs work with minimal changes. Run `movepress validate` to check.

**Q: Can I use both tools during migration?**  
A: Yes, the config format is compatible.

**Q: What about FTP deployments?**  
A: Movepress requires SSH/rsync. If you're on FTP-only hosting, consider upgrading or continue using Wordmove.

---

## See Also

- [Command Reference](COMMANDS.md)
- [Configuration Reference](CONFIGURATION.md)
- [Examples](EXAMPLES.md)
- [Troubleshooting](TROUBLESHOOTING.md)
