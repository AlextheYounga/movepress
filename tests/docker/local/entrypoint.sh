#!/bin/bash
set -e

echo "===== Movepress Local Environment Setup ====="

# Start Apache in background
apache2-foreground &
APACHE_PID=$!

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
while ! mysqladmin ping -h"${WORDPRESS_DB_HOST%:*}" -u"${WORDPRESS_DB_USER}" -p"${WORDPRESS_DB_PASSWORD}" --silent; do
    sleep 2
done
echo "MySQL is ready!"

# Wait for WordPress files to be available
echo "Waiting for WordPress files..."
while [ ! -f /var/www/html/wp-config.php ]; do
    sleep 2
done

# Install WordPress using bundled wp-cli
echo "Installing WordPress..."
php /usr/local/bin/movepress core download --path=/var/www/html --force || true

# Check if WordPress is already installed
if ! php /usr/local/bin/movepress core is-installed --path=/var/www/html 2>/dev/null; then
    echo "Running WordPress installation..."
    php /usr/local/bin/movepress core install \
        --path=/var/www/html \
        --url="http://localhost:8080" \
        --title="Movepress Local Test" \
        --admin_user="admin" \
        --admin_password="admin" \
        --admin_email="local@movepress.test" \
        --skip-email
    echo "WordPress installed successfully!"
else
    echo "WordPress already installed, skipping..."
fi

# Create test content
echo "Creating test content..."
php /usr/local/bin/movepress post create \
    --path=/var/www/html \
    --post_title="Test Post Local" \
    --post_content="This is test content from the local environment. URL: http://localhost:8080" \
    --post_status="publish" \
    --porcelain || true

# Create test uploads directory with sample file
echo "Creating test uploads..."
mkdir -p /var/www/html/wp-content/uploads/2024/11
echo "Test upload file from local environment" > /var/www/html/wp-content/uploads/2024/11/test-local.txt
chown -R www-data:www-data /var/www/html/wp-content/uploads

# Fix permissions
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html

echo "===== Local environment ready! ====="
echo "WordPress URL: http://localhost:8080"
echo "Admin: admin / admin"
echo ""

# Keep Apache running
wait $APACHE_PID
