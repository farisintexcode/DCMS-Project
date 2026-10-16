# DCMS by InteXcode

Debt Collector Management System. Laravel 13 modular monolith with Livewire 4, Blade, Tailwind CSS 4 and Livewire's bundled Alpine; PostgreSQL, Redis and private S3 storage. DRMS by Senja Bina is a historical analysis reference, not this product.

## Requirements and traceability

Read these before implementing any feature:

- [Plan.md](Plan.md): architecture, stack, security, integrations, testing and roadmap.
- [Module.md](Module.md): M01–M14 functional contracts and business rules.
- [Prototype.md](Prototype.md): UI01–UI70 screen and interaction contracts.
- [Database.md](Database.md): canonical entities, fields, relationships and constraints.

These four unchanged documents remain the single source of truth. Confirmed C scope overrides historical reference O/U evidence. Implementation notes: [review/dependencies](docs/foundation-review.md), [architecture](docs/architecture.md), [all 66 entity dispositions](docs/entity-register.md). Notes are tracking aids, not replacement requirements.

Customer means `debtors` / `Debtor`; Rider means eligible `staff` linked one-to-one through `users.staff_id`. Client is the commissioning company. Do not introduce duplicate customers/rider_profiles/task_assignments/attachments/task_verifications tables.

## This increment

- Official Laravel 13 skeleton and included Composer/npm lockfiles.
- Sail PHP 8.4, PostgreSQL 17, Redis 7.4 and a local Mailpit sink configuration.
- Session login/logout, throttling, password broker/recovery/reset, invitation-only activation endpoint and own password changes. Approved terms/privacy configuration is required for activation. Public registration is absent.
- Admin/Rider role bundles, granular permissions, active account/staff checks, explicit effective client scopes and a client policy. No role-name bypass. Employment positions are separate reference values.
- Dependency-ordered identity/reference/client/scope migrations; UUID v7 public references, PostgreSQL checks/partial indexes/FKs; encrypted staff contact casts.
- Append-only audit, outbox/deduplication persistence and notification schema. Outbox dispatch arrives with the first queued business operation.
- Responsive Admin and mobile Rider shells, reusable Blade inputs/buttons/feedback, Livewire account settings. Navigation labels forthcoming modules; operational KPIs are intentionally awaiting real module data.
- S3 private disk configuration, separate provider contracts for email, WhatsApp messaging and calling; no selected or enabled provider.
- Pulse package schema and permission-protected access; recording disabled by default. Sentry disabled without a DSN and configured for minimal sanitized incident signals. AWS/CloudWatch infrastructure is later deployment work.
- PostgreSQL Pest/PHPUnit tests, desktop/mobile Playwright tests, GitHub PR template and a GitLab CI template.
- M13 Customer Management: scoped list/search/create/edit, active/inactive status, encrypted identifiers/contacts/addresses, observation history and client-specific categories with explicit bulk membership changes. See [customer traceability and decisions](docs/customer-management.md).

Customer Management implements UI62/UI24/UI63/UI61 against the canonical debtor entities. Account/task/visit/communication history tabs await their source modules; customer export follows the reporting module. Task/import/attendance/communication/report workflows remain planned. The remaining dependency order follows Plan.md §11 and the review notes.

## Local setup with Sail

On Windows, install Docker Desktop with WSL2 integration and use a WSL shell. This workspace's initial machine has neither Docker nor WSL; installing them is a host prerequisite, not an application code change.

Use PHP 8.4 + Composer 2 to install dependencies, or bootstrap Composer through Sail's official image from the project root:

```bash
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" \
  -v "$PWD:/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
# Set DCMS_SEARCH_KEY in .env to a separate base64-encoded random 32-byte secret.
# Generate locally: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm ci --ignore-scripts
./vendor/bin/sail npm run build
./vendor/bin/sail artisan dcms:bootstrap-admin you@example.test --name="Your name"
```

The bootstrap command prompts for a strong password and only runs in `local` before the first user exists. It creates Admin plus explicit roles.manage/scopes.manage grants, with audit. No accounts or passwords are seeded by the normal DatabaseSeeder. It grants no client portfolio access. Invitation issuance and staff provisioning UI follow in the staff module; the activation endpoint already consumes a valid broker token for an invited account and retains the terms version in audit.

To explicitly provision your first local client and portfolio scope before the client/scope administration UI arrives, run `./vendor/bin/sail artisan dcms:provision-local-client CLIENT_CODE "Client name" you@example.test`. This local-only command requires an active account with `scopes.manage` and audits both records. It does not grant any other user's access.

Customer search requires `DCMS_SEARCH_KEY`, independent of `APP_KEY`. Keep it stable and private: changing it requires rebuilding all customer HMAC search tokens and identity/contact fingerprints before search resumes. Search text stays out of URLs. Full identifiers require the separate `customers.view_sensitive` grant, excluded from ordinary Admin bundles; masked identifiers are the default.

Open the configured APP_URL at `/login` or `/rider/login`. Mailpit dashboard uses local port 8025. Database/Redis/Mailpit ports bind only loopback. Change local default database credentials before shared use. Keep `.env`, test secrets, vendor and generated assets out of Git.

Run `key:generate` only for a new environment; preserve existing encryption keys. Application time is UTC; display/calendar policy uses `DCMS_BUSINESS_TIMEZONE=Asia/Kuala_Lumpur`. Set approved `DCMS_TERMS_VERSION` and HTTPS `DCMS_PRIVACY_URL` before inviting users. Production requires approved domain/TLS, secure session cookies, Secrets Manager/IAM and separate runtime/migrator DB roles.

The ordinary Admin seed excludes role/scope administration and independent template/correction approval. These separate grants require deliberate authorization. Seeders create initial bundles only and preserve subsequent permission edits. Production grants require business sign-off; never infer privileges from position labels.

For local queue work as modules arrive:

```bash
./vendor/bin/sail artisan queue:work redis --queue=default,imports,exports,email,whatsapp,webhooks,media --timeout=120 --tries=3
./vendor/bin/sail artisan schedule:work
```

Queue retry_after is 180 seconds and dispatch uses after_commit. Long-running imports/exports must define bounded job timeouts/backoff/idempotency. Production needs independent fair worker capacity and separate queue/cache Redis services. S3 has no local fake public bucket: configure private AWS storage or use Storage fakes in tests until Document quarantine/scanning and signed access are implemented.

## Verification

```bash
cp .env.testing.example .env.testing
# Supply a fresh test APP_KEY. Sail initializes the separate 'testing' database.
# Also supply a distinct DCMS_SEARCH_KEY in .env.testing.
./vendor/bin/sail artisan key:generate --env=testing
./vendor/bin/sail test
./vendor/bin/sail npm run build
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit
./vendor/bin/sail php vendor/bin/pint --dirty --format agent
```

Tests require PostgreSQL. Do not replace it with SQLite: partial indexes, category FKs, inet/JSONB and immutable audit guards are part of the application contract. Never point tests at a production database. Pest uses PHPUnit underneath; no duplicated suite is required.

Browser smoke checks can run against an already running app:

```bash
npm ci --ignore-scripts
npx playwright install chromium
PLAYWRIGHT_BASE_URL=http://localhost npm run test:e2e
```

Credential-based cases skip unless their `DCMS_E2E_ADMIN_EMAIL`, `DCMS_E2E_RIDER_EMAIL` and `DCMS_E2E_PASSWORD` are supplied. For full browser acceptance use a dedicated local/test database ending `_e2e`, generate a temporary runtime password, migrate it, and explicitly run BrowserTestSeeder with that password in the environment. It provisions `admin@example.test`, an explicitly scoped synthetic client, a foreign-client control record and `rider@example.test`; both accounts use the runtime secret. Start the app against that database, supply those email variables to Playwright and discard fixtures afterwards. The GitLab template demonstrates the full setup. No test sends to real customers.

Verified on 16 October 2026:

- Native PHP 8.3.30 (supported minimum) and portable isolated PostgreSQL 17.11: foundation and customer tests cover authentication, scoped access, encrypted data/search, history, category integrity, stale writes and transactional rollback. Current verification counts are recorded in the customer module notes.
- Chromium Playwright covers public entry, workspace access, synthetic login/account/logout and the customer create/edit/category/history/search workflow on desktop and mobile.
- Frontend production build, Blade view cache and config cache/clear passed.
- Composer install resolved Laravel 13.36, Livewire 4.4, Spatie Permission 8.3, Sail 1.69 and Pest 4.7. Exact versions are in lockfiles. Composer/npm audits reported no advisories at installation.

The ignored `.env` on the initial machine uses native loopback PostgreSQL port 55432 and file sessions/cache solely for local verification. `.env.example` uses the agreed Sail/Redis services. PHP's pdo_pgsql extension was enabled per process during native tests; enable it in your own PHP runtime or use Sail. Browser runtime checks used an explicitly enabled extension as `artisan serve` does not forward `php -d` options to its child server.

Pending: Docker Compose validation and PHP 8.4/Sail boot, Redis-backed session/queue/cache integration, configured GitLab runner/mirror execution, actual S3 access/scanning, AWS/Pulse/Sentry operational activation and provider capability tests. Passing native tests does not claim these integrations are operational. Foundation code is ready for the next module; these remain environment/integration acceptance gates.

## GitHub, GitLab and deployment

Local Git is initialized on `main`; no remote, commit, push or deployment has been created. GitHub remains canonical and GitLab receives a read-only pull mirror. Configure a protected trusted `GITHUB_CANONICAL_REPOSITORY`, protected branches and required GitHub reviews before activating `.gitlab-ci.yml`. The template verifies the mirrored SHA against GitHub main, installs locked dependencies, tests PostgreSQL, builds assets and runs browser checks. It has no deployment stage. Verify it on the chosen runner before relying on it.

Local image tags are development baselines. Resolve and commit approved image digests for production during compatibility/deployment work. AWS ECS/ECR/RDS/private S3/KMS/Secrets Manager, CloudWatch, OIDC, scanning/signing, migrations/cutover approvals and restore drills follow Plan.md. The DCMS domain and messaging providers are not yet supplied; DRMS's domain must remain untouched.

## Development rules

Keep Livewire/controllers thin; use validated inputs, explicit action permissions plus record policies/client scope, transactions and locks for critical writes, reusable UI and scoped queries. Every major change must identify Plan section, Module ID, UI ID and Database table/constraint in its PR. Highlight technical conflicts before changing intended behaviour. Read [known findings](docs/foundation-review.md) before implementing bulk selection, legacy imports or databank. Tests accompany each module. Avoid placeholder financial/attendance rules, fabricated KPIs and external messages triggered without a configured business rule.
