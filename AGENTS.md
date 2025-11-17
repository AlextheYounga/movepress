# Agent Onboarding

You are a **Copilot agent**.

## How to Work

You do not write code until we discuss the reasoning behind your change first. You should begin. You should draft a quick summary of your intentions and thoughts, and if I agree, then you should proceed. When debugging, always look for a fix which involves deleting lines of code only, then for fixes which involve modifying code, then for fixes which involve writing new lines

## Engineering Philosophy

The following defines our **engineering culture, preferences, and non-negotiables**.  
Agents should reference this before making any design or implementation decisions.

---

## Principles

- **Minimize assumptions.** Validate ideas with code or tests instead of speculation.
- **Work incrementally.** Ship in small, verifiable steps.
- **Get it running first.** Deliver working code before refactoring.
- **Follow conventions.** Always check existing files before inventing a new style.
- **Avoid premature abstraction.** Don’t generalize until there’s a proven need.
- **Ask: _What would John Carmack do?_** Optimize for clarity, correctness, and performance.

---

## Opinions

- **Simplicity first:** Complexity is a liability.
- **Comments are code:** Do not write excessive comments in the same way you would not write excessive code.
- **KISS > DRY:** Clarity (Keep It Simple, Stupid) is usually better than deduplicating everything. DRY should not create indirection.
- **CLEAN CODE:** Clean Code principles are non-negotiable. “Clear code is clear thinking.” Files shouldn't be more than 300 lines of code maximum, (if we can help it).
- **Consistency is king:** Check other files for conventions and for existing solutions to inspire future solutions.
- **FOSS > SaaS:** Never use closed-source third-party services if open-source exists. We are self-hosting maximalists.
- **Leverage before adding:** Always ask: _“Can existing tools (like SQLite) solve this before adding Redis/new dependencies?”_
- **Idempotency:** We like idempotent setups.
- **YAGNI:** (You Aren’t Gonna Need It). Don’t add layers for problems we don’t yet have.
- **Cohesion > Coupling:** Related things belong together; unrelated things must be separated.
- **Transparency > Magic:** Avoid “clever” metaprogramming or hidden behaviors; explicitness wins. Ruby often upsets us because of this.
- **Avoid hack solutions:** Consider alternative approaches instead of applying patchwork solutions to poorly-thought implementations. Example: never wrangle with strings (regexing, manipulating for comparisons, etc) if you don't have to.

---

## WP-CLI Integration (CRITICAL)

**NEVER use global `wp` command or call wp-cli as an external command.** Movepress bundles wp-cli and uses it as a PHP library.

- ✅ **Correct:** Call `Application::loadWpCliClasses()`, bootstrap WordPress, initialize WP_CLI config, then use wp-cli classes (e.g., `new Search_Replace_Command()`)
- ❌ **Wrong:** Execute `wp` command, call `php boot-fs.php`, or run wp-cli as external process
- **Why:** wp-cli is a PHP library. We have the entire codebase bundled.
- ✅ **Loading wp-cli:** Call `Movepress\Application::loadWpCliClasses()` which loads all necessary wp-cli files from vendor
- **Bootstrap order:**
    1. Call `Application::loadWpCliClasses()`
    2. Define `WP_USE_THEMES` and `WP_CLI` constants
    3. Bootstrap WordPress via `require_once $wordpressPath . '/wp-load.php'`
    4. Initialize WP_CLI config via reflection: `$runner = \WP_CLI::get_runner(); /* set $runner->config via reflection */`
    5. Use wp-cli command classes: `$cmd = new Search_Replace_Command(); $cmd->__invoke(...);`
- **Remote execution:** Transfer movepress.phar to remote and execute `movepress post-import` command via SSH
- **Never:** Try to execute PHP files from within PHAR using `php phar://...` - this doesn't work
- **Never:** Extract wp-cli files to temporary locations - defeats the purpose of bundling
- **Exception:** Test environment setup scripts (entrypoint.sh) may download wp-cli for initial WordPress installation ONLY

---

## Completed Goals

### ✅ Docker Integration Tests

- **Goal:** Get all Docker tests to pass so we can be sure everything works
- **Accomplished:** All 8 Docker integration tests now pass:
    - Configuration validation
    - System status checks
    - Database push (local → remote with search-replace)
    - File push (untracked files via rsync over SSH)
    - Database pull (remote → local with search-replace)
    - Backup functionality
    - Dry-run mode
- **Key Fixes:**
    - Fixed `mysql` → `mariadb` command usage for MariaDB containers
    - Implemented proper wp-cli library integration
    - Created `PostImportCommand` for remote search-replace execution
    - Updated database host configuration for realistic SSH-based operations

### ✅ WP-CLI Library Integration

- **Goal:** Use wp-cli as PHP library instead of external commands
- **Accomplished:** Complete wp-cli integration with proper bootstrap sequence:
    - `Application::loadWpCliClasses()` loads all necessary wp-cli files
    - Reflection-based WP_CLI config initialization
    - `PostImportCommand` executes search-replace on remote servers
    - PHAR-based remote execution via `movepress post-import`
- **Architecture:** Local operations use direct class instantiation, remote operations transfer PHAR and execute via SSH

### ✅ Realistic Test Environment

- **Goal:** Make Docker tests accurately simulate real-world scenarios
- **Accomplished:**
    - Proper SSH connections between containers
    - Database operations execute on remote servers (not direct container-to-container connections)
    - Real WordPress installations with actual content
    - File synchronization via rsync over SSH
    - Search-replace URL transformations (localhost:8080 → localhost:8081)

---

## Test-Driven Development

- Always write tests **before** implementing code.
- Cover both positive and negative cases.
- Write descriptive, context-rich test names.
- Minimize duplication across test cases.
- Never write test code in production files. Test code must be confined to tests.
- **Data:** Prefer Faker; otherwise use fixture files.
- **Scope:** Favor broad functional tests over micro-unit tests.
- **No external calls.** Tests must not depend on network connectivity.
- **Tests must be fast:** No test should feel “expensive” to run.
