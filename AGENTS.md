# AGENTS.md

Instructions for every agent or contributor working in this repository. Read this file before
changing code. The architecture contract in [`docs/architectuur.md`](docs/architectuur.md) and the
inventory in [`docs/inventarisatie-2026-11.md`](docs/inventarisatie-2026-11.md) are normative too;
when they disagree with this file, the architecture document wins and this file must be updated.

## What this repository is

`jw-1980/ratings-and-reviews` is one private Laravel package shared by WelZoLekker (Blade) and
NutriCompare (Vue 3). It owns reviews, per-topic ratings, threaded discussions, votes, polls,
reports, moderation, uploads and erasure in `rr_`-tables. The host application stays the owner of
users, permissions (RBAC), points, notifications, product data and its own admin screens; the
package asks for those through contracts and reports back through events.

## Non-negotiable rules

- **No AI, no external runtime services.** Every feature is deterministic: rules, word lists,
  counts or statistics. Do not add an AI/LLM dependency or call.
- **One package, few dependencies.** PHP `^8.3`, Laravel `^12.0 || ^13.0`,
  `symfony/html-sanitizer` `^7.2 || ^8.0`. New runtime dependencies need a documented reason in
  `docs/architectuur.md`; prefer contracts and host callbacks.
- **MySQL 8.4 is the only tested and supported database.** The package test suite must run on
  MySQL 8.4 (no SQLite, no Docker in the documented workflow). Hosts whose own tests use SQLite
  must still be able to migrate: guard MySQL-only schema and runtime SQL per driver through
  `Support\SqlDialect` (FULLTEXT, generated columns, JSON keys, upserts).
- **Morph aliases only.** Host models are resolved through the host's morph map. Never accept a
  client-supplied class name; an unknown `{type}` is a 404.
- **Ability checks always have a subject.** Use `rr.moderate`, `rr.manage-settings` and
  `rr.manage-topics` with the item, subject or a `Support\ModerationScope`/`SectionScope`.
  Missing ability means denied. A list filters per section instead of checking per row.
- **The host owns presentation of its own data.** Do not add host-specific columns, enums or
  product logic to the package.
- **Privacy.** Content is only public when approved and published. Respect `erasure_withheld_at`,
  `held_as_evidence` and open reports in every read path. Never log raw IPs, e-mails, tokens or
  user content; store hashes only. Uploaded files must be re-encoded when possible and served
  only through the package's access rules.
- **Translations.** Every user-visible string lives in `lang/{nl,en}/…` under the
  `ratings-and-reviews::` namespace. `nl` and `en` must have identical keys. No hardcoded UI text
  in PHP, Blade, JavaScript or Vue (config/labels that are host data are fine).
- **Events after commit.** Dispatch package events after the database transaction commits
  (`afterCommit`/`DB::afterCommit`) so listeners never see uncommitted state.
- **Backwards compatibility.** The package is pre-1.0 and breaking changes are allowed, but a
  schema change needs a migration that upgrades an existing database, not only a fresh install.
  Document breaking changes in `docs/architectuur.md` under the relevant round.

## Coding standards

- PSR-12 via Laravel Pint (`vendor/bin/pint`). Run `vendor/bin/pint --test` before pushing; no
  file may fail it. LF line endings.
- PHP: typed properties and return types on public APIs, constructor injection, `readonly` where
  the value never changes, no `env()` outside `config/`, no queries in Blade templates.
- Keep services responsible for one concern; controllers validate, authorize and delegate.
  Form requests resolve the item before validation so 404/403 win over 422.
- Prefer the query builder for aggregates and batched reads; eager-load or batch everything a
  list needs. A list request must have a fixed number of queries, independent of the page size.
- Migrations: table prefix `rr_`, explicit index names, foreign keys where the package owns both
  tables, no destructive change without an upgrade migration and a test.
- JavaScript: plain ES modules in `resources/js/` (no build for the PHP package), 2-space indent,
  JSDoc for exported functions, sanitize all user HTML with `sanitize.js` before `innerHTML` or
  `v-html`. Vue components are `<script setup lang="ts">`; typecheck with `npm run typecheck`.
- Do not commit `vendor/`, `node_modules/`, `dist/`, `composer.lock` or generated fixture copies
  (`packages/vue/src/shared/`).

## Tests

```bash
composer install
vendor/bin/phpunit                 # MySQL 8.4 on 127.0.0.1:3306, schema rr_test
vendor/bin/pint
cd packages/vue
npm install
npm test                           # Vitest, copies resources/js first
npm run typecheck
npm run build
```

- Every behaviour change gets a test that fails without the change. Security fixes need a test
  that exercises the authorization or sanitization path, not only the service call.
- The concurrency tests are skipped when `pcntl` is unavailable; do not let a skipped test fail
  in `tearDown`.
- The Blade fixtures are golden files generated against the real backend
  (`packages/vue/scripts/blade-fixture/`); regenerate them when the rendered markup changes, and
  never hand-edit them to make a test pass.

## Pull requests and branches

- Branch from `main`, keep the branch focused, and open a pull request against `main`.
- Before merging: `vendor/bin/pint --test`, the MySQL PHPUnit suite, `npm test`,
  `npm run typecheck` and `npm run build` must pass; the PR description states what was tested.
- Resolve conflicts by rebasing or merging `main` into the branch, never by discarding one side
  blindly. Re-run the full checks after resolving.
- Do not commit stray files, editor output, generated logs or secrets. Keep `composer.lock`
  untracked for this library.
