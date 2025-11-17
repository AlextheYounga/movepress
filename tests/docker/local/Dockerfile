FROM wordpress:latest

# Install required packages for running movepress
RUN apt-get update && apt-get install -y \
 openssh-client \
 rsync \
 git \
 default-mysql-client \
 curl \
 && rm -rf /var/lib/apt/lists/*

# Download wp-cli for setup
RUN curl -sS -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp

# Pre-install WordPress core files
RUN php -d memory_limit=512M /usr/local/bin/wp core download --path=/var/www/html --allow-root --force

# WordPress config will be created at runtime in entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Apache is already exposed by parent image on port 80
