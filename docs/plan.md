# Movepress Project Plan

> **⚠️ HISTORICAL DOCUMENT:** This plan reflects the original project vision. The tool has evolved to use Git-based deployment for code files. See current docs: README.md, COMMANDS.md, EXAMPLES.md

## Vision

Create a modern, self-contained WordPress deployment tool that replaces the unmaintained Wordmove gem. Movepress will be a single `.phar` executable with bundled wp-cli, making WordPress site migrations and deployments simple, fast, and reliable.

## Goals

### Primary Goals
1. **Drop-in Wordmove replacement** - Familiar YAML configuration, similar commands
2. **Zero external dependencies** - Bundle wp-cli, use native rsync/SSH
3. **Self-contained distribution** - Single `.phar` file, no gem/composer install needed
4. **Modern PHP** - PHP 8.1+, clean architecture, fully tested
5. **Developer-friendly** - Clear errors, dry-run mode, verbose output

### Success Criteria
- Can push/pull WordPress sites between local/staging/production
- Handles both files (rsync) and databases (wp-cli search-replace)
- Works on macOS/Linux without additional setup
- Comprehensive test coverage (unit + integration)
- Documentation that enables quick adoption

## Architecture

### Core Components

```
movepress.phar
├── Commands          # CLI interface (push, pull, init)
├── Services          # Business logic
│   ├── ConfigLoader  # YAML + .env parsing
│   ├── SshService    # SSH connection management
│   ├── RsyncService  # File synchronization
│   └── DatabaseService # DB export/import + wp-cli
└── vendor            # Bundled dependencies (wp-cli, Symfony)
```

### Technology Stack
- **Language:** PHP 8.1+
- **CLI Framework:** Symfony Console
- **Process Execution:** Symfony Process
- **Config:** Symfony YAML + vlucas/phpdotenv
- **WordPress CLI:** wp-cli/wp-cli-bundle
- **Build:** humbug/box (PHAR compilation)
- **Testing:** PHPUnit 10

### Key Design Decisions

1. **PHP over Ruby/Go/Node**
   - Native WordPress language
   - No compilation needed (Go)
   - Better WordPress ecosystem integration than Ruby/Node
   - Can bundle wp-cli directly

2. **Rsync for files**
   - Battle-tested, efficient delta transfers
   - Available on all Unix systems
   - Better than pure PHP implementations
   - SSH-based transfers

3. **wp-cli for databases**
   - Industry-standard WordPress CLI
   - Built-in search-replace handles serialized data
   - Bundle as Composer dependency
   - Invoke via `WP_CLI::runcommand()`

4. **YAML configuration**
   - Familiar to Wordmove users
   - Human-readable
   - Supports environment variables via ${VAR}

## Development Phases

### Phase 1: Foundation ✅ (Completed)
- [x] Project scaffolding
- [x] Configuration system (YAML + .env)
- [x] SSH service
- [x] Rsync service with exclude patterns
- [x] Push/Pull commands (file operations)
- [x] Test framework (57 tests, 123 assertions)

**Status:** Complete. All tests passing. Ready for database operations.

### Phase 2: Database Operations 🚧 (Current)
- [ ] DatabaseService class
- [ ] MySQL export/import
- [ ] wp-cli search-replace integration
- [ ] Remote database operations via SSH
- [ ] Backup before destructive operations
- [ ] Database operation tests

**Target:** Fully functional push/pull including databases

### Phase 3: Polish & UX
- [ ] Progress indicators
- [ ] Confirmation prompts
- [ ] Better error messages
- [ ] SSH connectivity testing
- [ ] `status` and `validate` commands
- [ ] Comprehensive error handling

**Target:** Production-ready tool

### Phase 4: Distribution
- [ ] Build PHAR with Box
- [ ] Test executable in isolation
- [ ] Version management
- [ ] Homebrew formula
- [ ] Documentation site
- [ ] Migration guide from Wordmove

**Target:** Public release

### Phase 5: Advanced Features
- [ ] Pre/post hooks
- [ ] Multisite support
- [ ] Performance optimizations
- [ ] Additional storage backends (S3)
- [ ] Monitoring/notifications

**Target:** Feature parity with Wordmove + enhancements

## User Workflows

### Typical Use Case: Production Pull
```bash
# One-time setup
movepress init
# Edit movefile.yml and .env with credentials

# Pull production database and uploads to local
movepress pull production local --db --untracked-files --dry-run  # Preview
movepress pull production local --db --untracked-files            # Execute
```

### Typical Use Case: Staging Push
```bash
# Push local changes to staging
git add .
git commit -m "Update theme"
git push staging develop  # Deploy code

movepress push local staging --db --untracked-files  # Sync database and uploads
```

### Configuration Example
```yaml
# movefile.yml
global:
  exclude:
    - ".git/"
    - "node_modules/"

local:
  wordpress_path: "/Users/dev/sites/mysite"
  url: "http://mysite.local"
  database:
    name: "${DB_NAME}"
    user: "${DB_USER}"
    password: "${DB_PASSWORD}"

production:
  wordpress_path: "/var/www/mysite.com"
  url: "https://mysite.com"
  database:
    name: "${PROD_DB_NAME}"
    user: "${PROD_DB_USER}"
    password: "${PROD_DB_PASSWORD}"
  ssh:
    host: "${PROD_HOST}"
    user: "${PROD_USER}"
```

## Technical Requirements

### System Requirements
- PHP 8.1 or higher
- rsync (available on macOS/Linux by default)
- SSH client
- MySQL/MariaDB (for local database operations)

### Remote Server Requirements
- SSH access
- rsync installed
- MySQL/MariaDB with network access
- WordPress installation

## Testing Strategy

### Unit Tests
- Services in isolation (ConfigLoader, SshService, RsyncService, DatabaseService)
- Command argument parsing and validation
- Configuration merging and interpolation

### Integration Tests
- Actual rsync operations (with test fixtures)
- Database export/import
- wp-cli search-replace
- SSH connectivity

### End-to-End Tests
- Complete push/pull workflows
- Multi-environment scenarios
- Error recovery

## Non-Goals

### Out of Scope (v1.0)
- FTP/SFTP support (SSH/rsync only)
- Windows support (Unix-like systems only)
- GUI interface
- WordPress plugin
- Cloud storage (S3, etc.)
- Monitoring/analytics
- Multi-CMS support

These may be considered for future versions based on user feedback.

## Success Metrics

### Technical
- ✅ 100% test coverage of core services
- ⏳ Database operations working reliably
- ⏳ Zero-downtime deployments possible
- ⏳ Performance: push/pull 100MB in <30s (files only)

### Adoption
- Documentation enables setup in <5 minutes
- Migration from Wordmove is straightforward
- Community feedback is positive
- Active usage and contributions

## Timeline Estimate

- **Phase 1:** Complete (4-6 hours) ✅
- **Phase 2:** 3-4 hours ⏳ Current phase
- **Phase 3:** 2-3 hours
- **Phase 4:** 2-3 hours
- **Phase 5:** Ongoing

**Total to MVP (Phase 1-4):** ~12-16 hours
**Current Progress:** ~40% complete

## Resources

### References
- [Wordmove GitHub](https://github.com/welaika/wordmove) - Original tool
- [wp-cli Handbook](https://make.wordpress.org/cli/handbook/) - CLI documentation
- [Box Documentation](https://github.com/box-project/box) - PHAR builder

### Related Tools
- Wordmove (Ruby) - Original, unmaintained
- WP Pusher (SaaS) - Commercial solution
- WP-CLI Deploy - Limited scope
- Trellis (Ansible) - Full stack, more complex

### Differentiators
- **vs Wordmove:** Maintained, modern PHP, bundled wp-cli
- **vs SaaS tools:** Self-hosted, free, no vendor lock-in
- **vs Ansible:** Focused on WordPress, simpler setup
- **vs WP-CLI Deploy:** More comprehensive, better UX

## Contributing

When project is public:
- Follow existing code style
- Write tests for new features
- Update documentation
- Submit PRs with clear descriptions

## License

MIT License - Simple, permissive, community-friendly
