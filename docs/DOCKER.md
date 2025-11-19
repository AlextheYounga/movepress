# Docker Environment Setup

This guide explains how to use Movepress with Docker-based WordPress environments.

---

## Overview

Movepress works with Docker by accessing WordPress files and databases through the **host machine**. It doesn't run commands inside containers - instead, it expects standard filesystem paths and database connections on the host.

---

## Requirements

For Movepress to work with your Docker setup, ensure:

### 1. WordPress Files Accessible on Host

Mount your WordPress directory (especially `wp-content`) to the host filesystem:

```yaml
# docker-compose.yml
services:
    wordpress:
        volumes:
            - ./wordpress:/var/www/html
            # OR just wp-content
            - ./wp-content:/var/www/html/wp-content
```

### 2. Database Port Forwarded

Expose the MySQL/MariaDB port to your host machine:

```yaml
# docker-compose.yml
services:
    mysql:
        ports:
            - '3306:3306' # Host:Container
```

### 3. Correct Configuration Paths

Use **host machine paths** in your `movefile.yml`, not container paths:

```yaml
local:
    wordpress_path: './wordpress' # Host path, not /var/www/html
    database:
        name: 'wordpress'
        user: 'root'
        password: 'root'
        host: 'localhost' # Or 127.0.0.1
    url: 'http://localhost:8080'
```

---

## Example Configurations

### Standard Docker Compose Setup

```yaml
# docker-compose.yml
version: '3.8'

services:
    wordpress:
        image: wordpress:latest
        ports:
            - '8080:80'
        volumes:
            - ./wordpress:/var/www/html
        environment:
            WORDPRESS_DB_HOST: mysql:3306
            WORDPRESS_DB_NAME: wordpress
            WORDPRESS_DB_USER: root
            WORDPRESS_DB_PASSWORD: root

    mysql:
        image: mysql:8.0
        ports:
            - '3306:3306'
        environment:
            MYSQL_ROOT_PASSWORD: root
            MYSQL_DATABASE: wordpress
        volumes:
            - mysql_data:/var/lib/mysql

volumes:
    mysql_data:
```

```yaml
# movefile.yml
local:
    wordpress_path: './wordpress'
    database:
        name: 'wordpress'
        user: 'root'
        password: 'root'
        host: 'localhost'
    url: 'http://localhost:8080'
```

---

### Custom Database Port

If your database is on a non-standard port:

```yaml
# docker-compose.yml
services:
    mysql:
        ports:
            - '3307:3306' # Custom host port
```

```yaml
# movefile.yml
local:
    database:
        host: 'localhost:3307' # Specify custom port
```

---

### Remote Docker Server

For production servers running Docker:

```yaml
production:
    wordpress_path: '/var/www/mysite/wordpress' # Path on remote host
    database:
        name: 'wordpress'
        user: 'wordpress'
        password: '${PROD_DB_PASSWORD}'
        host: 'localhost' # Database exposed on remote host
    url: 'https://example.com'
    ssh:
        host: 'production-server.com'
        user: 'deploy'
        key: '~/.ssh/id_rsa'
```

**Requirements on remote server:**

- Docker volumes mounted to filesystem
- Database port forwarded to host (or use Docker host networking)
- SSH user has read/write access to mounted volumes

---

## Common Issues

### Issue: "mysqldump: Can't connect to MySQL server"

**Cause:** Database port not forwarded to host

**Solution:** Add port forwarding in `docker-compose.yml`:

```yaml
mysql:
    ports:
        - '3306:3306'
```

---

### Issue: Rsync can't find wp-content

**Cause:** Using container path instead of host path

**Solution:** Update `movefile.yml` to use host path:

```yaml
# Wrong
wordpress_path: "/var/www/html"  # Container path

# Correct
wordpress_path: "./wordpress"    # Host path
```

---

## What Movepress Does NOT Support

### Container-Only Filesystems

If your WordPress files are ONLY in containers with no volume mounts, Movepress cannot access them. You must use Docker volumes or bind mounts.

### Docker Network-Only Databases

If your database is only accessible via Docker's internal network (not port forwarded), you have two options:

1. **Forward the port** (recommended):

    ```yaml
    mysql:
        ports:
            - '3306:3306'
    ```

2. **Use host networking** (advanced):
    ```yaml
    mysql:
        network_mode: 'host'
    ```

### Running Commands Inside Containers

Movepress does not use `docker exec` to run commands inside containers. All operations happen on the host machine with access to:

- Mounted filesystem paths
- Forwarded database ports

---

## Best Practices

### 1. Use Volume Mounts

Always mount WordPress files to your host:

```yaml
volumes:
    - ./wordpress:/var/www/html
```

This enables:

- File syncing via rsync
- Git tracking of themes/plugins
- Local editing with your IDE

### 2. Forward Database Ports

Always expose MySQL/MariaDB to host:

```yaml
ports:
    - '3306:3306'
```

This enables:

- Database dumps via mysqldump
- Database imports via mysql
- Local database GUI tools

### 3. Use Environment Variables

Store sensitive data in `.env`:

```yaml
# movefile.yml
local:
  database:
    password: "${DB_PASSWORD}"

# .env
DB_PASSWORD=your_secure_password
```

### 4. Separate Environments

Use different Docker Compose files for different environments:

```bash
# Local development
docker-compose -f docker-compose.local.yml up

# Staging
docker-compose -f docker-compose.staging.yml up
```

---

## Testing Your Setup

Verify your Docker configuration works with Movepress:

```bash
# Test database connection
mysqldump --host=localhost --user=root --password=root wordpress > /dev/null
echo "Database: $?"

# Test filesystem access
ls -la ./wordpress/wp-content
echo "Filesystem: $?"

# Test movepress dry-run
movepress push local staging --db --dry-run
```

All should return success (exit code 0).

---

## See Also

- [Configuration Reference](CONFIGURATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [Examples](EXAMPLES.md)
