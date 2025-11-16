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
while ! mysqladmin ping -h"${WORDPRESS_DB_HOST%:*}" -u"${WORDPRESS_DB_USER}" -p"${WORDPRESS_DB_PASSWORD}" --silent; do
    sleep 2
done
echo "MySQL is ready!"

# Set up SSH authorized keys
if [ -f /root/.ssh/id_rsa.pub ]; then
    echo "Setting up SSH authorized_keys..."
    cp /root/.ssh/id_rsa.pub /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
fi

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
        --url="http://localhost:8081" \
        --title="Movepress Remote Test" \
        --admin_user="admin" \
        --admin_password="admin" \
        --admin_email="remote@movepress.test" \
        --skip-email
    echo "WordPress installed successfully!"
else
    echo "WordPress already installed, skipping..."
fi

# Create test content (different from local)
echo "Creating test content..."
php /usr/local/bin/movepress post create \
    --path=/var/www/html \
    --post_title="Test Post Remote" \
    --post_content="This is test content from the remote environment. URL: http://localhost:8081" \
    --post_status="publish" \
    --porcelain || true

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
    cat > hooks/post-receive <<'EOF'
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
