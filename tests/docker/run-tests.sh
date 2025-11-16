#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MOVEPRESS_BIN="${PROJECT_ROOT}/build/movepress.phar"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Movepress Docker Integration Test Runner${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""

# Check if movepress.phar exists
if [ ! -f "$MOVEPRESS_BIN" ]; then
    echo -e "${RED}✗ movepress.phar not found at: $MOVEPRESS_BIN${NC}"
    echo "Please build it first with: composer install && ./vendor/bin/box compile"
    exit 1
fi
echo -e "${GREEN}✓ Found movepress.phar${NC}"

# Setup SSH keys
echo ""
echo -e "${YELLOW}Setting up SSH keys...${NC}"
bash "${SCRIPT_DIR}/setup-ssh.sh"

# Stop and remove any existing containers
echo ""
echo -e "${YELLOW}Cleaning up existing containers...${NC}"
cd "$SCRIPT_DIR"
docker compose down -v 2>/dev/null || true

# Build and start containers
echo ""
echo -e "${YELLOW}Building and starting containers...${NC}"
docker compose build
docker compose up -d

# Wait for containers to be healthy
echo ""
echo -e "${YELLOW}Waiting for containers to be ready...${NC}"
sleep 10

# Wait for WordPress installations to complete
echo "Waiting for WordPress installations..."
for i in {1..60}; do
    if docker logs movepress-local 2>&1 | grep -q "Local environment ready"; then
        echo -e "${GREEN}✓ Local environment ready${NC}"
        break
    fi
    if [ $i -eq 60 ]; then
        echo -e "${RED}✗ Local environment timeout${NC}"
        exit 1
    fi
    sleep 2
done

for i in {1..60}; do
    if docker logs movepress-remote 2>&1 | grep -q "Remote environment ready"; then
        echo -e "${GREEN}✓ Remote environment ready${NC}"
        break
    fi
    if [ $i -eq 60 ]; then
        echo -e "${RED}✗ Remote environment timeout${NC}"
        exit 1
    fi
    sleep 2
done

# Test SSH connectivity
echo ""
echo -e "${YELLOW}Testing SSH connectivity...${NC}"
ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i "${SCRIPT_DIR}/ssh/id_rsa" -p 2222 root@localhost "echo 'SSH connection successful'" 2>/dev/null
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ SSH connection successful${NC}"
else
    echo -e "${RED}✗ SSH connection failed${NC}"
    exit 1
fi

# Run Movepress commands
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Running Movepress Tests${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""

cd "$PROJECT_ROOT"

# Test 1: Validate configuration
echo -e "${YELLOW}Test 1: Validating configuration...${NC}"
"$MOVEPRESS_BIN" validate --config="${SCRIPT_DIR}/movefile.yml"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Configuration valid${NC}"
else
    echo -e "${RED}✗ Configuration validation failed${NC}"
    exit 1
fi

# Test 2: Check status
echo ""
echo -e "${YELLOW}Test 2: Checking system status...${NC}"
"$MOVEPRESS_BIN" status --config="${SCRIPT_DIR}/movefile.yml"

# Test 3: Push database from local to remote
echo ""
echo -e "${YELLOW}Test 3: Pushing database from local to remote...${NC}"
"$MOVEPRESS_BIN" push local remote --db --config="${SCRIPT_DIR}/movefile.yml" --verbose
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Database push successful${NC}"
else
    echo -e "${RED}✗ Database push failed${NC}"
    exit 1
fi

# Verify database was transferred
echo "Verifying database transfer..."
REMOTE_POST_COUNT=$(docker exec movepress-mysql-remote mysql -uwordpress -pwordpress wordpress_remote -se "SELECT COUNT(*) FROM wp_posts WHERE post_type='post' AND post_status='publish'")
if [ "$REMOTE_POST_COUNT" -gt 0 ]; then
    echo -e "${GREEN}✓ Database contains posts: $REMOTE_POST_COUNT${NC}"
else
    echo -e "${RED}✗ Database appears empty${NC}"
    exit 1
fi

# Test 4: Push files from local to remote
echo ""
echo -e "${YELLOW}Test 4: Pushing files from local to remote...${NC}"
"$MOVEPRESS_BIN" push local remote --files --config="${SCRIPT_DIR}/movefile.yml" --verbose
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ File push successful${NC}"
else
    echo -e "${RED}✗ File push failed${NC}"
    exit 1
fi

# Verify files were transferred
echo "Verifying file transfer..."
if ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i "${SCRIPT_DIR}/ssh/id_rsa" -p 2222 root@localhost "[ -f /var/www/html/wp-content/uploads/2024/11/test-local.txt ]" 2>/dev/null; then
    echo -e "${GREEN}✓ Files transferred successfully${NC}"
else
    echo -e "${RED}✗ Expected files not found${NC}"
    exit 1
fi

# Test 5: Pull database from remote to local
echo ""
echo -e "${YELLOW}Test 5: Pulling database from remote to local...${NC}"
"$MOVEPRESS_BIN" pull local remote --db --config="${SCRIPT_DIR}/movefile.yml" --verbose
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Database pull successful${NC}"
else
    echo -e "${RED}✗ Database pull failed${NC}"
    exit 1
fi

# Test 6: Test backup command
echo ""
echo -e "${YELLOW}Test 6: Testing database backup...${NC}"
"$MOVEPRESS_BIN" backup local --config="${SCRIPT_DIR}/movefile.yml"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Backup successful${NC}"
else
    echo -e "${RED}✗ Backup failed${NC}"
    exit 1
fi

# Summary
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  All Tests Passed!${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""
echo "Test environments are still running. You can:"
echo "  • Access local WordPress: http://localhost:8080 (admin/admin)"
echo "  • Access remote WordPress: http://localhost:8081 (admin/admin)"
echo "  • SSH to remote: ssh -i tests/docker/ssh/id_rsa -p 2222 root@localhost"
echo ""
echo "To stop the test environment:"
echo "  cd tests/docker && docker compose down"
echo ""
