FROM wordpress:latest

# Install required packages
RUN apt-get update && apt-get install -y \
    openssh-server \
    rsync \
    git \
    default-mysql-client \
    sudo \
    && rm -rf /var/lib/apt/lists/*

# Configure SSH
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

# Expose SSH port
EXPOSE 22

# Apache is already exposed by parent image on port 80
