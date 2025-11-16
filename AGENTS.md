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

**NEVER use global `wp` command.** Movepress bundles wp-cli and MUST use the bundled version exclusively.

- ✅ **Correct:** Use bundled wp-cli from movepress PHAR (via `boot-fs.php` or transferring PHAR)
- ❌ **Wrong:** Call external `wp` command installed on system
- **Why:** We control the wp-cli version, ensure consistency, and eliminate external dependencies
- **Remote execution:** Transfer movepress PHAR to remote server, then execute bundled wp-cli there
- **Never assume:** Do not assume remote servers have wp-cli installed globally

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
