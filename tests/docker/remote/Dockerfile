FROM wordpress:latest

# Install required packages
RUN apt-get update && apt-get install -y \
 openssh-server \
 rsync \
 git \
 default-mysql-client \
 sudo \
 curl \
 && rm -rf /var/lib/apt/lists/*

# Download wp-cli for setup
RUN curl -sS -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp

# Pre-install WordPress core files
RUN php -d memory_limit=512M /usr/local/bin/wp core download --path=/var/www/html --allow-root --force

RUN mkdir -p /var/run/sshd \
 && mkdir -p /root/.ssh \
 && chmod 700 /root/.ssh

# Configure SSH to allow root login and password authentication for testing
RUN sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config \
 && sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config \
 && sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config

# Set root password for testing
RUN echo 'root:movepress' | chpasswd

# Create directory for Git repositories
RUN mkdir -p /var/repos && chmod 755 /var/repos

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Expose SSH port
EXPOSE 22
h/sshd_config
