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

# Prepare containers (recreate if they already exist)
echo ""
echo -e "${YELLOW}Preparing containers (recreate if present)...${NC}"
cd "$SCRIPT_DIR"
if [ -n "$(docker compose ps -q 2> /dev/null)" ]; then
    echo -e "${YELLOW}Recreating existing containers...${NC}"
    docker compose up -d --force-recreate
else
    echo -e "${YELLOW}Building and starting containers...${NC}"
    docker compose build
    docker compose up -d
fi

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

# Test SSH connectivity from local container to remote container
echo ""
echo -e "${YELLOW}Testing SSH connectivity...${NC}"
docker exec movepress-local ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa root@wordpress-remote "echo 'SSH connection successful'"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ SSH connection successful${NC}"
else
    echo -e "${RED}✗ SSH connection failed${NC}"
    exit 1
fi

# Run Movepress commands (inside local container)
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Running Movepress Tests${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════${NC}"
echo ""

# Test 1: Validate configuration
echo -e "${YELLOW}Test 1: Validating configuration...${NC}"
docker exec movepress-local movepress validate
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Configuration valid${NC}"
else
    echo -e "${RED}✗ Configuration validation failed${NC}"
    exit 1
fi

# Test 2: Check status
echo ""
echo -e "${YELLOW}Test 2: Checking system status...${NC}"
docker exec movepress-local movepress status

# Test 3: Push database from local to remote
echo ""
echo -e "${YELLOW}Test 3: Pushing database from local to remote...${NC}"
docker exec movepress-local movepress push local remote --db --verbose
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
docker exec movepress-local movepress push local remote --files --verbose
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ File push successful${NC}"
else
    echo -e "${RED}✗ File push failed${NC}"
    exit 1
fi

# Verify files were transferred
echo "Verifying file transfer..."
if docker exec movepress-local ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa root@wordpress-remote "[ -f /var/www/html/wp-content/uploads/2024/11/test-local.txt ]"; then
    echo -e "${GREEN}✓ Files transferred successfully${NC}"
else
    echo -e "${RED}✗ Expected files not found${NC}"
    exit 1
fi

# Test 5: Pull database from remote to local
echo ""
echo -e "${YELLOW}Test 5: Pulling database from remote to local...${NC}"
docker exec movepress-local movepress pull local remote --db --verbose
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Database pull successful${NC}"
else
    echo -e "${RED}✗ Database pull failed${NC}"
    exit 1
fi

# Test 6: Test backup command
echo ""
echo -e "${YELLOW}Test 6: Testing database backup...${NC}"
docker exec movepress-local movepress backup local
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
echo "  • SSH to remote: ssh -i tests/Docker/ssh/id_rsa -p 2222 root@localhost"
echo ""
echo "To stop the test environment:"
echo "  cd tests/docker && docker compose down"
echo ""
