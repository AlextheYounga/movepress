# Engineering Philosophy

This document defines our **engineering culture, preferences, and non-negotiables**.  
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

## Development Stack

> You may propose tools outside this list if they strongly align with our philosophy, but justify the choice.

### Languages

- Python, PHP, Ruby
- JavaScript, TypeScript
- Rust, C, Go
- Bash (for scripting)

### Frameworks

- Vue (preferred for small/medium sites)
- Laravel 12 CLI (preferred for large sites)
- Rails
- NestJS, Express.js
- Flask
- Tauri (preferred over Electron)

### Tools

- Ripgrep (for all cli searching and parsing)
- SQLAlchemy (**only** ORM for Python)
- Prisma (**only** ORM for JS/TS)
- SQLite (default database; we are SQLite maximalists)
- Redis (only if SQLite cannot solve the problem)
- Pandas
- Tailwind, Vite, Shadcdn
- Pytest, Jest
- Playwright (only if Jest cannot cover required tests)

---

## Opinions

- **Simplicity first:** Complexity is a liability.
- **Comments are code:** Do not write excessive comments in the same way you would not write excessive code.
- **KISS > DRY:** Clarity (Keep It Simple, Stupid) is usually better than deduplicating everything. DRY should not create indirection.
- **Clarity is king:** Clean Code principles are non-negotiable. “Clear code is clear thinking.”
- **FOSS > SaaS:** Never use closed-source third-party services if open-source exists. We are self-hosting maximalists.
- **SQLite preferred:** For very small projects (e.g., CLI tools), JSON storage is acceptable.
- **Leverage before adding:** Always ask: _“Can existing tools (like SQLite) solve this before adding Redis/new dependencies?”_
- **Avoid React/NextJS:** We dislike convoluted abstractions.
- **No Python websites:** Use Vue for small, Laravel for large.
- **Always Tauri over Electron.**
- **Relational > Document DBs:** Never use NoSQL/document databases.
- **IDs:** Never use UUIDs. Use autoincrement, or Cuids if necessary.
- **Idempotency:** We like idempotent setups.
- **YAGNI:** (You Aren’t Gonna Need It). Don’t add layers for problems we don’t yet have.
- **Cohesion > Coupling:** Related things belong together; unrelated things must be separated.
- **Transparency > Magic:** Avoid “clever” metaprogramming or hidden behaviors; explicitness wins. Ruby often upsets us because of this.

---

## Repository Layout

- **Model larger projects after Laravel layouts,** but keep them lean.
- **MVC where appropriate.**
- **No root-level scripts.** Place them in appropriate folders.
- **Documentation → `docs/` folder.**
- **File naming:** dot notation allowed (e.g., `pages.controller.ts`).

---

## Test-Driven Development

- Always write tests **before** implementing code.
- Cover both positive and negative cases.
- Write descriptive, context-rich test names.
- Minimize duplication across test cases.
- **Data:** Prefer Faker; otherwise use fixture files.
- **Scope:** Favor broad functional tests over micro-unit tests.
- **No external calls.** Tests must not depend on network connectivity.
- **Tests must be fast:** No test should feel “expensive” to run.
