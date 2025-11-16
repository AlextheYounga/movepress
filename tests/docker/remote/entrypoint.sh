#!/bin/bash
set -e

echo "===== Movepress Remote Environment Setup ====="

# Start SSH service
echo "Starting SSH server..."
service ssh start

# Start Apache in background
apache2-foreground &
APACHE_PID=$!

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until php -r "\$mysqli = @new mysqli('${WORDPRESS_DB_HOST%:*}', '${WORDPRESS_DB_USER}', '${WORDPRESS_DB_PASSWORD}', '${WORDPRESS_DB_NAME}'); exit(\$mysqli->connect_error ? 1 : 0);" 2> /dev/null; do
    sleep 2
done
echo "MySQL is ready!"

# Set up SSH authorized keys
if [ -f /root/.ssh/id_rsa.pub ]; then
    echo "Setting up SSH authorized_keys..."
    cp /root/.ssh/id_rsa.pub /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
fi

# Download wp-cli for initial setup only
echo "Downloading wp-cli.phar for setup..."
if [ ! -f /tmp/wp-cli.phar ]; then
    curl -sS -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod +x /tmp/wp-cli.phar
fi

# Give WordPress container a moment to set up initial files
sleep 5

# Download WordPress core files (with increased memory for extraction)
echo "Downloading WordPress core..."
php -d memory_limit=512M /tmp/wp-cli.phar core download --path=/var/www/html --allow-root --force || true

# Create wp-config.php
echo "Creating wp-config.php..."
php /tmp/wp-cli.phar config create \
    --path=/var/www/html \
    --dbname="${WORDPRESS_DB_NAME}" \
    --dbuser="${WORDPRESS_DB_USER}" \
    --dbpass="${WORDPRESS_DB_PASSWORD}" \
    --dbhost="${WORDPRESS_DB_HOST}" \
    --allow-root \
    --force || true

# Check if WordPress is already installed
echo "Checking WordPress installation..."
if ! php /tmp/wp-cli.phar core is-installed --path=/var/www/html --allow-root 2> /dev/null; then
    echo "Installing WordPress with wp-cli..."
    php /tmp/wp-cli.phar core install \
        --path=/var/www/html \
        --url="http://localhost:8081" \
        --title="Movepress Remote Test" \
        --admin_user="admin" \
        --admin_password="admin" \
        --admin_email="remote@movepress.test" \
        --skip-email \
        --allow-root
    echo "WordPress installed successfully!"
else
    echo "WordPress already installed, skipping..."
fi

# Create test content with wp-cli (different from local)
echo "Creating test content..."
php /tmp/wp-cli.phar post create \
    --path=/var/www/html \
    --post_title="Test Post Remote" \
    --post_content="This is test content from the remote environment. URL: http://localhost:8081" \
    --post_status="publish" \
    --porcelain \
    --allow-root || true

# Create test uploads directory with sample file
echo "Creating test uploads..."
mkdir -p /var/www/html/wp-content/uploads/2024/11
echo "Test upload file from remote environment" > /var/www/html/wp-content/uploads/2024/11/test-remote.txt
chown -R www-data:www-data /var/www/html/wp-content/uploads

# Set up Git bare repository for deployment testing
echo "Setting up Git repository..."
if [ ! -d /var/repos/movepress-test.git ]; then
    mkdir -p /var/repos/movepress-test.git
    cd /var/repos/movepress-test.git
    git init --bare

    # Create post-receive hook for automatic deployment
    cat > hooks/post-receive << 'EOF'
#!/bin/bash
TARGET="/var/www/html"
GIT_DIR="/var/repos/movepress-test.git"

while read oldrev newrev ref
do
    BRANCH=$(git rev-parse --symbolic --abbrev-ref $ref)
    if [ "master" = "$BRANCH" ] || [ "main" = "$BRANCH" ]; then
        echo "Deploying branch $BRANCH to $TARGET..."
        git --work-tree=$TARGET --git-dir=$GIT_DIR checkout -f $BRANCH
        echo "Deployment complete!"
    fi
done
EOF
    chmod +x hooks/post-receive
    echo "Git repository initialized at /var/repos/movepress-test.git"
fi

# Fix permissions
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html

echo "===== Remote environment ready! ====="
echo "WordPress URL: http://localhost:8081"
echo "SSH: root@localhost:2222 (password: movepress)"
echo "Git repo: /var/repos/movepress-test.git"
echo "Admin: admin / admin"
echo ""

# Keep Apache running in foreground
wait $APACHE_PID
