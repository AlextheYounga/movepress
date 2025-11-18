#!/bin/bash
set -e

echo "===== Movepress Local Environment Setup ====="

# Start Apache in background
apache2-foreground &
APACHE_PID=$!

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until php -r "\$mysqli = @new mysqli('${WORDPRESS_DB_HOST%:*}', '${WORDPRESS_DB_USER}', '${WORDPRESS_DB_PASSWORD}', '${WORDPRESS_DB_NAME}'); exit(\$mysqli->connect_error ? 1 : 0);" 2> /dev/null; do
    sleep 2
done
echo "MySQL is ready!"

# Update wp-config.php with actual database connection details
echo "Updating wp-config.php..."
wp config create \
    --path=/var/www/html \
    --dbname="${WORDPRESS_DB_NAME}" \
    --dbuser="${WORDPRESS_DB_USER}" \
    --dbpass="${WORDPRESS_DB_PASSWORD}" \
    --dbhost="${WORDPRESS_DB_HOST}" \
    --allow-root \
    --force

# Check if WordPress is already installed
echo "Checking WordPress installation..."
if ! wp core is-installed --path=/var/www/html --allow-root 2> /dev/null; then
    echo "Installing WordPress..."
    wp core install \
        --path=/var/www/html \
        --url="http://localhost:8080" \
        --title="Movepress Local Test" \
        --admin_user="admin" \
        --admin_password="admin" \
        --admin_email="local@movepress.test" \
        --skip-email \
        --allow-root
    echo "WordPress installed successfully!"
else
    echo "WordPress already installed, skipping..."
fi

# Create test content
echo "Creating test content..."
wp post create \
    --path=/var/www/html \
    --post_title="Test Post Local" \
    --post_content="This is test content from the local environment. URL: http://localhost:8080" \
    --post_status="publish" \
    --porcelain \
    --allow-root || true

# Create test uploads directory with sample file
echo "Creating test uploads..."
mkdir -p /var/www/html/wp-content/uploads/2024/11
echo "Test upload file from local environment" > /var/www/html/wp-content/uploads/2024/11/test-local.jpg
echo "Local hardcoded URL: http://localhost:8080" > /var/www/html/wp-content/uploads/hardcoded.txt
chown -R www-data:www-data /var/www/html/wp-content/uploads

# Fix permissions (skip read-only mounted files)
echo "Setting permissions (skipping read-only mounts)..."
find /var/www/html -maxdepth 1 ! -name movefile.yml -type f -exec chown www-data:www-data {} \; 2> /dev/null || true
find /var/www/html -maxdepth 1 ! -name movefile.yml -type d -exec chown www-data:www-data {} \; 2> /dev/null || true
chown -R www-data:www-data /var/www/html/wp-* 2> /dev/null || true
find /var/www/html -maxdepth 1 ! -name movefile.yml -type f -exec chmod 644 {} \; 2> /dev/null || true
find /var/www/html -maxdepth 1 ! -name movefile.yml -type d -exec chmod 755 {} \; 2> /dev/null || true
chmod -R 755 /var/www/html/wp-* 2> /dev/null || true

echo "===== Local environment ready! ====="
echo "WordPress URL: http://localhost:8080"
echo "Admin: admin / admin"
echo ""

# Keep Apache running
wait $APACHE_PID
