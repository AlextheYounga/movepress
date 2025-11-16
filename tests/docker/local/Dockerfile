FROM wordpress:latest

# Install required packages for running movepress
RUN apt-get update && apt-get install -y \
 openssh-client \
 rsync \
 git \
 default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

# Apache is already exposed by parent image on port 80
