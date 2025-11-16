# Movepress Development Todo

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
- [ ] Add code coverage reporting


### Database Operations
- [ ] DatabaseService class
  - [ ] Export database via mysqldump
  - [ ] Import database via mysql
  - [ ] wp-cli integration for search-replace
  - [ ] Backup before import
  - [ ] Handle database connection strings
  - [ ] Support both local and remote (via SSH) operations
- [ ] Integrate database operations into Push/Pull commands
- [ ] Database operation tests

#### Database Sync
- [ ] Create DatabaseService
- [ ] Implement database export (local)
- [ ] Implement database export (remote via SSH)
- [ ] Implement database import (local)
- [ ] Implement database import (remote via SSH)
- [ ] wp-cli search-replace integration
- [ ] Automatic URL replacement during push/pull
- [ ] Database backup before destructive operations
- [ ] Handle large database dumps (compression)
- [ ] Progress indicators for database operations
- [ ] Database operation tests

#### wp-cli Integration
- [ ] Initialize wp-cli in commands
- [ ] Verify wp-cli is available
- [ ] Handle wp-cli errors gracefully
- [ ] Search-replace command integration
- [ ] Support for network/multisite
- [ ] wp-cli operation tests

#### Error Handling & Validation
- [ ] SSH connection testing before operations
- [ ] Rsync availability validation
- [ ] Database connection validation
- [ ] Disk space checking
- [ ] Better error messages
- [ ] Rollback on failure
- [ ] Validation tests

#### User Experience
- [ ] Confirmation prompts for destructive operations
- [ ] Progress bars for long-running operations
- [ ] Colored output for better readability
- [ ] Summary of what will be synced before execution
- [ ] Estimated time/size calculations
- [ ] Better verbose output

### Additional Commands
- [ ] `movepress status` - Show current configuration
- [ ] `movepress validate` - Validate movefile.yml
- [ ] `movepress ssh` - Test SSH connectivity
- [ ] `movepress backup` - Create backups before operations

### Advanced Features
- [ ] Pre/post sync hooks (run custom scripts)

### Build & Distribution
- [ ] Build PHAR with Box
- [ ] Test PHAR executable
- [ ] Add version command

### Documentation
- [ ] Detailed command documentation
- [ ] Configuration file reference
- [ ] Use case examples
- [ ] Troubleshooting guide
- [ ] Migration guide from wordmove
- [ ] Video tutorials
- [ ] API documentation

### Testing
- [ ] Integration tests (with real file operations)
- [ ] Database operation tests
- [ ] SSH connection mocking
- [ ] wp-cli operation mocking
- [ ] End-to-end tests
- [ ] Performance tests

## Maybes?
- [ ] Create release workflow
- [ ] Distribute via Homebrew
- [ ] Distribute via Packagist
- [ ] Auto-update mechanism