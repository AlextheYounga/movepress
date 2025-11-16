#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSH_DIR="${SCRIPT_DIR}/ssh"

echo "Generating SSH keys for test environment..."

# Create SSH directory if it doesn't exist
mkdir -p "$SSH_DIR"

# Generate SSH keypair if it doesn't exist
if [ ! -f "$SSH_DIR/id_rsa" ]; then
    ssh-keygen -t rsa -b 4096 -f "$SSH_DIR/id_rsa" -N "" -C "movepress-test"
    echo "✓ SSH keypair generated"
else
    echo "✓ SSH keypair already exists"
fi

# Set proper permissions
chmod 700 "$SSH_DIR"
chmod 600 "$SSH_DIR/id_rsa"
chmod 644 "$SSH_DIR/id_rsa.pub"

echo "✓ SSH keys ready at: $SSH_DIR"
echo ""
echo "Public key:"
cat "$SSH_DIR/id_rsa.pub"
