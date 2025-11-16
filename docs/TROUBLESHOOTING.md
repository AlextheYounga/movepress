# Troubleshooting Guide

Common issues and solutions when using Movepress.

---

## Installation Issues

| Problem               | Solution                                                               |
| --------------------- | ---------------------------------------------------------------------- |
| **Permission denied** | `chmod +x movepress.phar`                                              |
| **PHP version error** | Update to PHP 8.1+: `brew install php@8.1` or `apt-get install php8.1` |

---

## Configuration Issues

| Problem                    | Solution                                                                      |
| -------------------------- | ----------------------------------------------------------------------------- |
| **File not found**         | Run `movepress init` or create `movefile.yml` in WordPress root               |
| **Invalid YAML**           | Use spaces (not tabs), quote special characters, validate at yaml-checker.com |
| **Variables not resolved** | Create `.env` file with `KEY=value` (no spaces around `=`)                    |

**Common YAML mistakes:**

```yaml
# Wrong: tabs
local:
	path: /path

# Correct: spaces
local:
  path: /path

# Wrong: unquoted wildcards
exclude:
  - *.log

# Correct: quoted
exclude:
  - "*.log"
```

---

## SSH Connection Issues

| Problem                   | Solution                                                                                        |
| ------------------------- | ----------------------------------------------------------------------------------------------- |
| **Connection failed**     | Test manually: `ssh user@host`. Verify config in `movefile.yml`. Run `movepress ssh production` |
| **Key permission denied** | `chmod 600 ~/.ssh/id_rsa` and `chmod 700 ~/.ssh`                                                |
| **Port refused**          | Verify port in `movefile.yml` and test: `ssh -p 2222 user@host`                                 |

---

## Database Issues

| Problem                  | Solution                                                                                        |
| ------------------------ | ----------------------------------------------------------------------------------------------- |
| **mysqldump not found**  | Install: `brew install mysql-client` or `apt-get install mysql-client`                          |
| **Connection failed**    | Verify credentials in `movefile.yml`, test: `mysql -u user -p -h localhost db_name`             |
| **Import timeout**       | Add to `~/.ssh/config`: `ServerAliveInterval 60`. Use `--verbose` flag                          |
| **Search-replace fails** | Verify `url` matches actual site URL. Check `wordpress_path` is correct. Run `movepress status` |

---

## File Sync Issues

| Problem               | Solution                                                                                   |
| --------------------- | ------------------------------------------------------------------------------------------ |
| **rsync not found**   | Install: `brew install rsync` or `apt-get install rsync`                                   |
| **Permission denied** | On remote: `sudo chown -R deploy:www-data /var/www/mysite && chmod -R 775 /var/www/mysite` |
| **Files not syncing** | Check `exclude` patterns. Use `--dry-run` and `--verbose` flags                            |
| **Slow sync**         | Sync specific folders: `--include="wp-content/uploads/2024"`. Use Git for code (faster)    |

---

## Docker Environment Issues

### Database connection fails in Docker

**Problem:**

```
mysqldump: Can't connect to MySQL server on 'localhost'
```

**Solution:**
Ensure database port is forwarded to host:

```yaml
# docker-compose.yml
mysql:
    ports:
        - '3306:3306'
```

Then use `localhost` in movefile.yml:

```yaml
local:
    database:
        host: 'localhost' # Or 127.0.0.1
```

See [Docker Setup Guide](DOCKER.md) for complete configuration.

---

### WordPress files not found in Docker

**Problem:**

```
wp-cli error: WordPress not found at path
```

**Solution:**
Mount WordPress directory to host machine:

```yaml
# docker-compose.yml
wordpress:
    volumes:
        - ./wordpress:/var/www/html
```

Use the **host path** in movefile.yml:

```yaml
local:
    wordpress_path: './wordpress' # Host path, not container path
```

See [Docker Setup Guide](DOCKER.md) for examples.

---

## Command and Validation Issues

| Problem                    | Solution                                                                                          |
| -------------------------- | ------------------------------------------------------------------------------------------------- |
| **Command not found**      | Move to PATH: `sudo mv movepress.phar /usr/local/bin/movepress`                                   |
| **No confirmation prompt** | Remove `--no-interaction` flag to get prompts                                                     |
| **Validation fails**       | Fix reported errors: ensure `path`, `url`, `database` are present. URLs need protocol (`http://`) |

---

## Performance Issues

| Problem                   | Solution                                                                              |
| ------------------------- | ------------------------------------------------------------------------------------- |
| **Memory limit exceeded** | Increase limit: `php -d memory_limit=512M movepress.phar push local production --db`  |
| **Timeout issues**        | Sync in smaller chunks (database first, then uploads). Use Git for code (no timeouts) |

---

## Debugging Steps

`bash\n# 1. Validate configuration\nmovepress validate\n\n# 2. Check system status\nmovepress status\n\n# 3. Test SSH connection\nmovepress ssh production\n\n# 4. Preview changes\nmovepress push local production --untracked-files --dry-run\n\n# 5. Run with verbose output\nmovepress push local production --db -v\n`\n\nFor cron/systemd issues, check logs: `journalctl -xe` or `grep CRON /var/log/syslog`\n\n---

## See Also

- [Command Reference](COMMANDS.md)
- [Configuration Reference](CONFIGURATION.md)
- [Docker Setup](DOCKER.md)
- [Examples](EXAMPLES.md)
