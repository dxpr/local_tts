#!/bin/bash

# Install git hooks for the AI TTS module
# This script copies hook scripts to .git/hooks/ and makes them executable

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the module root directory
MODULE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$MODULE_ROOT"

echo ""
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  Installing Git Hooks for AI TTS Module                   ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if we're in a git repository
if [ ! -d ".git" ]; then
  echo -e "${RED}✗ Error: Not a git repository${NC}"
  echo -e "${YELLOW}  Run this from the module root directory${NC}"
  exit 1
fi

# Check if git-hooks directory exists
if [ ! -d "scripts/git-hooks" ]; then
  echo -e "${RED}✗ Error: scripts/git-hooks directory not found${NC}"
  exit 1
fi

# Create hooks directory if it doesn't exist
mkdir -p .git/hooks

# Install pre-commit hook
if [ -f "scripts/git-hooks/pre-commit" ]; then
  echo -e "${YELLOW}Installing pre-commit hook...${NC}"
  cp scripts/git-hooks/pre-commit .git/hooks/pre-commit
  chmod +x .git/hooks/pre-commit
  echo -e "${GREEN}✓ pre-commit hook installed${NC}"
else
  echo -e "${YELLOW}⚠ pre-commit hook not found, skipping${NC}"
fi

# Install commit-msg hook
if [ -f "scripts/git-hooks/commit-msg" ]; then
  echo -e "${YELLOW}Installing commit-msg hook...${NC}"
  cp scripts/git-hooks/commit-msg .git/hooks/commit-msg
  chmod +x .git/hooks/commit-msg
  echo -e "${GREEN}✓ commit-msg hook installed${NC}"
else
  echo -e "${YELLOW}⚠ commit-msg hook not found, skipping${NC}"
fi

# Install composer dependencies if not present
if [ ! -f "vendor/bin/phpcs" ]; then
  echo ""
  echo -e "${YELLOW}Installing Composer dependencies for code quality tools...${NC}"
  composer install --no-interaction
  echo -e "${GREEN}✓ Composer dependencies installed${NC}"
fi

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  ✓ Git hooks successfully installed!                      ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${BLUE}What happens now:${NC}"
echo -e "  • ${GREEN}pre-commit${NC}: Auto-fixes code style and blocks commits with errors"
echo -e "  • ${GREEN}commit-msg${NC}: Validates commit message format"
echo ""
echo -e "${BLUE}To uninstall hooks:${NC}"
echo -e "  rm .git/hooks/pre-commit .git/hooks/commit-msg"
echo ""
echo -e "${BLUE}To skip hooks (not recommended):${NC}"
echo -e "  git commit --no-verify"
echo ""

exit 0
