# Movepress Development Todo

> **⚠️ HISTORICAL DOCUMENT:** This todo list reflects the original development roadmap. The project has since evolved to use Git-based deployment for code files. See current docs: README.md, COMMANDS.md, EXAMPLES.md

### Project Setup
- [x] Initialize Composer with dependencies (Symfony Console, Process, YAML, Dotenv, wp-cli)
- [x] Set up project structure (src/, tests/, bin/, config/)
- [x] Create executable entry point (bin/movepress)
- [x] Configure Box for PHAR compilation (box.json)
- [x] Set up PHPUnit test framework
- [x] Create example configuration files (movefile.yml.example, .env.example)
- [x] Write README.md with basic documentation

### Core Configuration
- [x] ConfigLoader class with YAML parsing
- [x] Environment variable interpolation (${VAR_NAME})
- [x] Dotenv file loading (.env support)
- [x] Global and environment-specific exclude patterns
- [x] Exclude pattern merging logic
- [x] Configuration validation
- [x] Comprehensive ConfigLoader tests (10 tests)

### SSH Service
- [x] SshService class for SSH connection management
- [x] SSH connection string building (user@host)
- [x] SSH options handling (port, key path)
- [x] Tilde expansion for SSH key paths (~/.ssh/id_rsa)
- [x] Comprehensive SshService tests (9 tests)

### File Synchronization (Rsync)
- [x] RsyncService class for file operations
- [x] Basic rsync command building
- [x] Exclude pattern support (--exclude flags)
- [x] SSH integration for remote syncing
- [x] Dry-run mode support
- [x] Verbose/progress mode
- [x] Sync all files method
- [x] Sync content only (themes + plugins, exclude uploads)
- [x] Sync uploads only (wp-content/uploads)
- [x] Trailing slash handling for proper rsync behavior
- [x] Rsync availability checking
- [x] Comprehensive RsyncService tests (9 tests)

### Commands
- [x] Application class with command registration
- [x] InitCommand - Generate movefile.yml and .env templates
- [x] PushCommand - Push files/db from source to destination
  - [x] Source/destination arguments
  - [x] --db flag for database only
  - [x] --files flag for all files
  - [x] --content flag for themes + plugins
  - [x] --uploads flag for uploads only
  - [x] --dry-run flag
  - [x] --no-backup flag
  - [x] Flag conflict validation
  - [x] Environment validation
  - [x] Configuration display
  - [x] File sync integration
  - [x] Comprehensive PushCommand tests (13 tests)
- [x] PullCommand - Pull files/db from source to destination
  - [x] Same flag structure as PushCommand
  - [x] Reversed sync direction
  - [x] Comprehensive PullCommand tests (16 tests)

### Testing
- [x] PHPUnit configuration
- [x] Test bootstrap
- [x] No deprecation warnings
- [x] Add code coverage reporting


### Database Operations
- [x] DatabaseService class
  - [x] Export database via mysqldump
  - [x] Import database via mysql
  - [x] wp-cli integration for search-replace
  - [x] Backup before import
  - [x] Handle database connection strings
  - [x] Support both local and remote (via SSH) operations
- [x] Integrate database operations into Push/Pull commands
- [x] Database operation tests

#### Database Sync
- [x] Create DatabaseService
- [x] Implement database export (local)
- [x] Implement database export (remote via SSH)
- [x] Implement database import (local)
- [x] Implement database import (remote via SSH)
- [x] wp-cli search-replace integration
- [x] Automatic URL replacement during push/pull
- [x] Database backup before destructive operations
- [x] Handle large database dumps (compression)
- [x] Progress indicators for database operations
- [x] Database operation tests

#### wp-cli Integration
- [x] Bundle wp-cli as required dependency
- [x] Use PHP entry point (boot-fs.php) instead of shell wrapper
- [x] Verify wp-cli works in both dev and PHAR environments
- [x] Remove all system wp-cli fallbacks
- [x] Simplify wp-cli availability checking (always bundled)
- [x] Handle wp-cli errors gracefully
- [x] Search-replace command integration
- [x] wp-cli operation tests

#### Error Handling & Validation
- [x] SSH connection testing before operations
- [x] Rsync availability validation
- [x] Database connection validation
- [x] Better error messages

#### User Experience
- [x] Confirmation prompts for destructive operations
- [x] Progress bars for long-running operations
- [x] Colored output for better readability
- [x] Summary of what will be synced before execution
- [x] Better verbose output

### Additional Commands
- [x] `movepress status` - Show current configuration
- [x] `movepress validate` - Validate movefile.yml
- [x] `movepress ssh` - Test SSH connectivity
- [x] `movepress backup` - Create backups before operations

### Testing
- [x] Integration tests (command validation)
  - [x] rsync flag validation
  - [x] mysqldump flag validation
  - [x] mysql flag validation
  - [x] wp-cli search-replace command validation
  - [x] ssh flag validation
  - [x] scp flag validation
- [x] Database operation tests
- [x] SSH connection testing
- [x] wp-cli command structure tests
- [x] Code coverage reporting (78 tests, 184 assertions)
- [x] Remove system wp-cli fallbacks (bundled only)
- [x] Simplify wp-cli availability checking

### Build & Distribution
- [x] Build PHAR with Box
  - [x] Configure box.json with proper file inclusion
  - [x] Exclude humbug/php-scoper to avoid compilation errors
  - [x] Include Symfony Console Resources directory
  - [x] Bundle wp-cli PHP entry point (boot-fs.php)
  - [x] Use PHP entry point instead of shell wrapper for wp-cli
- [x] Test PHAR executable
  - [x] Verify all commands work from PHAR
  - [x] Confirm wp-cli is bundled and accessible
  - [x] Test status, validate, and other commands
  - [x] Verify wp-cli always shows as available (bundled dependency)
- [x] Add version command (--version flag works via Symfony Console)

### Documentation
- [x] Detailed command documentation (docs/COMMANDS.md)
  - All 7 commands documented with syntax, options, and examples
  - Global options reference
  - Exit codes and environment variables
- [x] Configuration file reference (docs/CONFIGURATION.md)
  - Complete movefile.yml structure
  - All required and optional fields
  - Environment variables usage
  - Best practices and validation
- [x] Use case examples (docs/EXAMPLES.md)
  - Basic workflows (deploy, pull, staging)
  - File syncing patterns
  - Database patterns
  - Team workflows
  - Multi-environment workflows
  - Automation examples (cron, scripts, CI/CD)
- [x] Troubleshooting guide (docs/TROUBLESHOOTING.md)
  - Installation issues
  - Configuration issues
  - SSH connection problems
  - Database issues
  - File sync issues
  - Command issues
  - Performance issues
- [x] Enhanced README.md
  - Installation instructions
  - Quick start guide
  - Commands overview
  - Configuration basics
  - Examples
  - Troubleshooting tips
- [x] Migration guide from wordmove (docs/MIGRATION.md)
  - Why migrate and benefits
  - Command comparison table
  - Configuration compatibility
  - Common migration issues
  - Step-by-step migration workflow
  - Team migration strategies
  - FAQ section
- [ ] Video tutorials
- [ ] API documentation


## Maybes?
- [ ] Pre/post sync hooks (run custom scripts)
- [ ] Create release workflow
- [ ] Distribute via Homebrew
- [ ] Distribute via Packagist
- [ ] Auto-update mechanism