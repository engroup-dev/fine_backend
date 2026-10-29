# CLAUDE.md

Guidance for Claude Code in this repository. Read `../HANDOFF.md` first — it is the authoritative project state and carries hard-won dev-environment gotchas.

## What this is

Laravel 13 / PHP 8.4 API ("fine_backend", SQLite in dev) for **Fine** — a five-unit foam-to-furniture manufacturer ERP. All ten phases are built: foundation/RBAC, procurement+treasury, inventory, foam, cutter, furniture, sales/POS, full accounting (ledger, landed cost/FX, overhead, fixed assets), HR/payroll, owner dashboard. The desktop client is the sibling `fine-desktop` repo (`engroup-dev/fine-erp`; both repos are in the engroup-dev GitHub org).

**Architecture is online-only** (offline-sync removed 2026-08-04). `SyncService`/`SyncConflict` are dead remnants — do not build on them.

## Invariants (do not silently reverse)

- **Ledger**: modules post via `AccountingService::postJournal` only. ACC-01 (debits=credits) and missing accounts are **fatal before write** — an operation that moves value but can't post is refused, never skipped. Journal lines carry `operating_unit_id`; subledgers/reports derive from lines. Journal API is read-only except gated manual entries. Postings land on **leaf accounts only** — `postJournal` refuses a header (`HEADER_ACCOUNT_POSTING`); cash moves name a treasury (`cash_account_id` → `TreasuryAccounts`), revenue is the unit's `revenue_account_id`, receivables the client's own 122xxx sub-account (manual entries may still name the 122 root).
- **Inventory**: stock never appears/leaves without an `inventory_movements` row (INV-06); movements are append-only.
- **Costing is actuals, never estimates**: orders cost from lots actually drawn; labor/FX/pay rates snapshot at event time and history never moves.
- **Unit scoping**: `X-Operating-Unit-ID` header → `ScopeOperatingUnit` → `CurrentUnitContext` → global scopes. Three scope types (plain, warehouse-derived, shared-or-unit). `operating_unit_id` is never accepted in request bodies for unit-scoped writes. FK validation uses `ExistsInCurrentUnit`. Company-wide roles (null-unit `user_roles`) pass with no header; accounting/dashboard reads accept `company_wide=1` for them only.
- Guard failures render **422 with a `code`** (see `bootstrap/app.php`); business rules are cited in comments by ID (INV-xx, FOAM-xx, ACC-xx, HR-xx…) mapping to `docs/phase-0X-*.md`.

## Module pattern

migration → enum (`allowedNext()`) → models (`Auditable`+observer registration in `AppServiceProvider`, `HasUuids`, `HasOptimisticLocking`, SoftDeletes) → service (all writes in `DB::transaction` with `lockForUpdate`) → controller → `routes/api.php` → Pest feature test seeding `ChartOfAccountsSeeder`. Run `vendor/bin/pint --dirty --format agent` after PHP changes.

## Environment gotchas (the expensive ones)

- PHP exists only in **PowerShell** (Herd shim), not Bash.
- The user's own server runs on port **8000** against the real dev DB — never migrate/seed it. Use **8010** with `php -S` from `public/` and an explicit `$env:DB_DATABASE` scratch file (`php artisan serve` does NOT inherit `DB_DATABASE`).
- Old seeded DBs lack newer chart-of-accounts codes → `MISSING_ACCOUNT` on posting → reseed (`migrate:fresh --seed`).
- PowerShell pipes into `php artisan tinker` inject a BOM and break class resolution — write a script that requires `vendor/autoload.php` + `bootstrap/app.php` and run `php script.php` instead.
- `withoutGlobalScopes()` does not reach `whereHas()` subqueries; `date`-cast columns store midnight timestamps, so lookups/uniqueness go through `whereDate` (both have caused real bugs).
- Tests: `php artisan test --compact` (a `|` in `--filter` breaks under the PowerShell→cmd shim — pass file paths instead).

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
