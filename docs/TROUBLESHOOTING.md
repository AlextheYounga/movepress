# Troubleshooting Guide

Common issues and solutions when using Movepress.

---

## Installation Issues

### PHAR won't execute

**Problem:**
```bash
bash: ./movepress.phar: Permission denied
```

**Solution:**
```bash
chmod +x movepress.phar
```

---

### PHP version error

**Problem:**
```
This package requires php >=8.1
```

**Solution:**
Update to PHP 8.1 or higher:
```bash
# Check current version
php --version

# macOS (Homebrew)
brew install php@8.1

# Ubuntu/Debian
sudo apt-get install php8.1
```

---

## Configuration Issues

### Configuration file not found

**Problem:**
```
Configuration file not found: /path/to/movefile.yml
```

**Solution:**
1. Run `movepress init` to create template
2. Or create `movefile.yml` manually in your WordPress root directory

---

### Invalid YAML syntax

**Problem:**
```
Error parsing YAML configuration
```

**Solution:**
1. Validate YAML syntax at https://yaml-checker.com
2. Check for:
   - Proper indentation (use spaces, not tabs)
   - Quoted strings containing special characters
   - Matching brackets/quotes

**Common YAML mistakes:**
```yaml
# Wrong: tabs instead of spaces
local:
	path: /path

# Correct: spaces for indentation
local:
  path: /path

# Wrong: unquoted special characters
exclude:
  - *.log

# Correct: quoted patterns
exclude:
  - "*.log"
```

---

### Environment variable not resolved

**Problem:**
Variables appear as literal text: `${DB_PASSWORD}`

**Solution:**
1. Create `.env` file in same directory as `movefile.yml`:
   ```bash
   touch .env
   ```

2. Add variables without spaces around `=`:
   ```bash
   # Correct
   DB_PASSWORD=secret123
   
   # Wrong
   DB_PASSWORD = secret123
   ```

3. Reference in `movefile.yml`:
   ```yaml
   database:
     password: ${DB_PASSWORD}
   ```

---

## SSH Connection Issues

### Cannot connect to remote server

**Problem:**
```
SSH connection failed
```

**Solution:**
1. Test SSH manually:
   ```bash
   ssh user@host
   ```

2. Verify SSH configuration in `movefile.yml`:
   ```yaml
   ssh:
     host: server.example.com
     user: deploy
     port: 22
     key: ~/.ssh/id_rsa
   ```

3. Use diagnostic command:
   ```bash
   movepress ssh production
   ```

---

### SSH key permission denied

**Problem:**
```
Permissions 0644 for '/home/user/.ssh/id_rsa' are too open
```

**Solution:**
```bash
# Fix key permissions
chmod 600 ~/.ssh/id_rsa

# Fix .ssh directory permissions
chmod 700 ~/.ssh
```

---

### SSH port connection refused

**Problem:**
```
Connection refused on port 22
```

**Solution:**
1. Verify correct port in `movefile.yml`:
   ```yaml
   ssh:
     port: 2222  # Check with your host
   ```

2. Test with manual SSH:
   ```bash
   ssh -p 2222 user@host
   ```

---

## Database Issues

### mysql/mysqldump not found

**Problem:**
```
mysqldump is not available
```

**Solution:**
Install MySQL client tools:

```bash
# macOS (Homebrew)
brew install mysql-client

# Ubuntu/Debian
sudo apt-get install mysql-client

# CentOS/RHEL
sudo yum install mysql
```

---

### Database connection failed

**Problem:**
```
Access denied for user
```

**Solution:**
1. Verify credentials in `movefile.yml`:
   ```yaml
   database:
     name: correct_db_name
     user: correct_user
     password: ${DB_PASSWORD}
     host: localhost
   ```

2. Test connection manually:
   ```bash
   mysql -u user -p -h localhost database_name
   ```

3. Check user permissions:
   ```sql
   GRANT ALL PRIVILEGES ON database_name.* TO 'user'@'localhost';
   FLUSH PRIVILEGES;
   ```

---

### Database import timeout

**Problem:**
Large database import times out or fails.

**Solution:**
1. Increase timeouts in SSH config:
   ```bash
   # Edit ~/.ssh/config
   Host *
     ServerAliveInterval 60
     ServerAliveCountMax 120
   ```

2. Use `--verbose` to monitor progress:
   ```bash
   movepress pull production local --db -v
   ```

---

### Search-replace not working

**Problem:**
URLs not replaced after database sync.

**Solution:**
1. Verify wp-cli is available:
   ```bash
   movepress status
   ```

2. Check URL configuration:
   ```yaml
   local:
     url: http://local.test  # Must match actual URL
   
   production:
     url: https://example.com  # Must match actual URL
   ```

3. Verify WordPress installation path:
   ```yaml
   local:
     path: /path/to/wordpress
     wordpress_path: /path/to/wordpress  # If different
   ```

---

## File Sync Issues

### rsync not found

**Problem:**
```
rsync is not available
```

**Solution:**
Install rsync:

```bash
# macOS (usually pre-installed)
brew install rsync

# Ubuntu/Debian
sudo apt-get install rsync

# CentOS/RHEL
sudo yum install rsync
```

---

### Permission denied during file sync

**Problem:**
```
rsync: recv_generator: mkdir failed: Permission denied
```

**Solution:**
1. Check file permissions on remote server:
   ```bash
   # On remote server
   ls -la /var/www/
   ```

2. Ensure SSH user has write access:
   ```bash
   # On remote server
   sudo chown -R deploy:www-data /var/www/mysite
   sudo chmod -R 775 /var/www/mysite
   ```

---

### Files not syncing as expected

**Problem:**
Some files aren't syncing.

**Solution:**
1. Check exclude patterns in `movefile.yml`:
   ```yaml
   global:
     exclude:
       - ".git/"
       - "*.log"
   ```

2. Use `--dry-run` to preview:
   ```bash
   movepress push local production --untracked-files --dry-run
   ```

3. Use `--verbose` to see what's happening:
   ```bash
   movepress push local production --untracked-files -v
   ```

---

### Slow file sync

**Problem:**
Upload sync is very slow.

**Solution:**
1. Sync specific upload folders only:
   ```bash
   # Specific upload directory
   movepress push local production --untracked-files \
     --include="wp-content/uploads/2024"
   ```

2. Check network connection

3. Verify rsync compression is enabled (it is by default)

4. For code changes, use Git instead (much faster):
   ```bash
   git push production master
   ```

---

## Command Issues

### Command not found

**Problem:**
```bash
movepress: command not found
```

**Solution:**
1. If using PHAR:
   ```bash
   # Add to PATH
   sudo mv movepress.phar /usr/local/bin/movepress
   
   # Or use full path
   /path/to/movepress.phar push local production --db
   ```

2. If building from source:
   ```bash
   ./build/movepress.phar push local production --db
   ```

---

### No confirmation prompt

**Problem:**
Destructive operations happen without confirmation.

**Solution:**
Remove `--no-interaction` flag if you want prompts:
```bash
# This will prompt
movepress push local production --db

# This won't prompt
movepress push local production --db --no-interaction
```

---

## Validation Issues

### Validation fails

**Problem:**
```bash
movepress validate
# Shows errors
```

**Solution:**
Fix each error reported:

1. **Missing required field:**
   ```yaml
   # Add missing fields
   local:
     path: /path/to/wordpress  # Required
     url: http://local.test     # Required
     database:                  # Required
       name: wp_local
       user: root
       password: ""
       host: localhost
   ```

2. **Invalid URL format:**
   ```yaml
   # Wrong
   url: mysite.test
   
   # Correct
   url: http://mysite.test
   ```

3. **Missing SSH config for remote:**
   ```yaml
   production:
     # ... other config
     ssh:  # Required for remote
       host: server.example.com
       user: deploy
   ```

---

## Performance Issues

### Memory limit exceeded

**Problem:**
```
Fatal error: Allowed memory size exhausted
```

**Solution:**
Increase PHP memory limit:
```bash
php -d memory_limit=512M movepress.phar push local production --db
```

---

### Timeout issues

**Problem:**
Operations timeout on large databases/uploads.

**Solution:**
1. Use `--verbose` to monitor progress
2. Sync in smaller chunks:
   ```bash
   # Database only first
   movepress push local production --db
   
   # Then uploads
   movepress push local production --untracked-files
   ```

3. For code, use Git (no timeout issues):
   ```bash
   git push production master
   ```

---

## Getting Help

### Enable verbose output

See detailed command output:
```bash
movepress push local production --db --untracked-files -v
```

### Use diagnostic commands

```bash
# Check system status
movepress status

# Validate configuration
movepress validate

# Test SSH connection
movepress ssh production

# Preview without executing
movepress push local production --untracked-files --dry-run
```

### Check logs

If using systemd or cron, check system logs:
```bash
# System logs
journalctl -xe

# Cron logs
grep CRON /var/log/syslog
```

---

## Still Having Issues?

1. Run validation:
   ```bash
   movepress validate
   ```

2. Check system status:
   ```bash
   movepress status
   ```

3. Try with verbose output:
   ```bash
   movepress push local production --db -v
   ```

4. Test individual components:
   ```bash
   # Test SSH
   movepress ssh production
   
   # Test database backup
   movepress backup local
   ```

5. Check version:
   ```bash
   movepress --version
   ```

---

## See Also

- [Command Reference](COMMANDS.md)
- [Configuration Reference](CONFIGURATION.md)
- [Examples](EXAMPLES.md)
