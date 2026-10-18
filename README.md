# DebtOps by InteXcode

Debt Collection Management System. Laravel 13 modular monolith with Livewire 4, Blade, Tailwind CSS 4 and Livewire's bundled Alpine; PostgreSQL, Redis and private S3 storage. DRMS by Senja Bina is a historical analysis reference, not this product.

## Requirements and traceability

Read these before implementing any feature:

- [Plan.md](Plan.md): architecture, stack, security, integrations, testing and roadmap.
- [Module.md](Module.md): M01–M14 functional contracts and business rules.
- [Prototype.md](Prototype.md): UI01–UI70 screen and interaction contracts.
- [Database.md](Database.md): canonical entities, fields, relationships and constraints.

These four documents remain the single source of truth. Confirmed C scope overrides historical reference O/U evidence. Implementation notes: [review/dependencies](docs/foundation-review.md), [architecture](docs/architecture.md), [all 66 entity dispositions](docs/entity-register.md). Notes are tracking aids, not replacement requirements.

Customer means `debtors` / `Debtor`; Rider means eligible `staff` linked one-to-one through `users.staff_id`. Client is the commissioning company. Do not introduce duplicate customers/rider_profiles/task_assignments/attachments/task_verifications tables.

## This increment

- Official Laravel 13 skeleton and included Composer/npm lockfiles.
- Sail PHP 8.4, PostgreSQL 17, Redis 7.4 and a local Mailpit sink configuration.
- Session login/logout, throttling, password broker/recovery/reset, invitation-only activation endpoint and own password changes. Approved terms/privacy configuration is required for activation. Public registration is absent.
- Admin/Rider role bundles, granular permissions, active account/staff checks, explicit effective client scopes and a client policy. No role-name bypass. Employment positions are separate reference values.
- Dependency-ordered identity/reference/client/scope migrations; UUID v7 public references, PostgreSQL checks/partial indexes/FKs; encrypted staff contact casts.
- Append-only audit, outbox/deduplication persistence and notification schema. M04 now publishes only import events through its durable outbox dispatcher.
- Responsive Admin and mobile Rider layouts, reusable Blade inputs/buttons/feedback, Livewire account settings and real customer/task KPIs. Navigation labels remaining modules.
- S3 private disk configuration, separate provider contracts for email, WhatsApp messaging and calling; no selected or enabled provider.
- Pulse package schema and permission-protected access; recording disabled by default. Sentry disabled without a DSN and configured for minimal sanitized incident signals. AWS/CloudWatch infrastructure is later deployment work.
- PostgreSQL Pest/PHPUnit tests, desktop/mobile Playwright tests, GitHub PR template and a GitLab CI template.
- M13 Customer Management: scoped list/search/create/edit, active/inactive status, encrypted identifiers/contacts/addresses, observation history and client-specific categories with explicit bulk membership changes. See [customer traceability and decisions](docs/customer-management.md).
- M10 Rider / Staff Management and Attendance: searchable personnel, controlled invitations, versioned work policies, explicit calendars, own clock-in/out/history, scoped Admin register/exports and independently approved corrections. Attendance links are available from staff profiles; performance reporting follows M09.
- M03 Assignment Batch Management: client-scoped active/archive register, metadata creation/editing, seven reference types, schedule/routing fields, version conflict checks, confirmed archival and audited, separately authorized reopening. Task intake, allocation and history link to M04–M07; general exports await M09.

Customer Management implements UI62/UI24/UI63/UI61 against the canonical debtor entities. Account/task/visit/manual-call history is connected; provider communication history and customer export follow their modules. Legacy task/account import adapters, communication and general report workflows remain planned. The remaining dependency order follows Plan.md §11 and the review notes.

Assignment batches are available at `/admin/assignments` to active `admin.access` + `assignments.view` users, restricted to current active client scopes. Creation/editing/archival require `assignments.manage`; reopening additionally requires `assignments.reopen`, a reserved grant excluded from ordinary Admin bundles. Archived assignments are read-only and retain their external-reference uniqueness and history. Client identity cannot change. Types cannot change once task/import history exists. Batch status changes check the locked record version and work disposition; terminal imports with unreconciled accepted rows block archival. No tasks, upload results or operational totals are fabricated. Traceability and future writer lock requirements are recorded in [architecture](docs/architecture.md) and [module disposition](docs/foundation-review.md).

- M05 Task Management & Allocation: customer account/property/party foundations, manual task intake/editing, scoped monitoring, immutable source/history, exact single/selected operation previews, queued allocation/reassignment/unassignment/cancellation, reconciliation and redacted result downloads. Legacy IWK imports/ranges await G02; M06 field execution and M07 independent review are implemented.

## M04 Excel imports and mapping

Open **Excel imports** in the Admin navigation (`/admin/imports`). The Customer and Assignment Batch contracts support private upload, clean-scan gating, sheet/header selection, exact canonical/alias suggestions, explicit Ignore, reviewed transforms, immutable profile versions, masked before/after preview, exact confirmation, queued atomic writes, cancellation, retries, reconciliation, audit and redacted CSV errors. History is restricted to effective client scope. Registry configuration is at `/admin/imports/configuration`.

Permissions: `admin.access` + `imports.view` for history/review; `imports.map` for upload, mapping, profiles and cancellation; `imports.confirm` for commit/retry. Target writes also recheck existing Customer/Assignment permissions. Client alias publication requires `templates.manage` + `imports.map`; template publication additionally requires reserved `templates.approve`, which is not granted automatically. Template versions and canonical bindings are immutable. Configuration creates compatible versions of the implemented writer contract; arbitrary executable transforms and workbook-defined database bindings are never accepted.

Enable the approved optional ClamAV service and import worker in a Docker/WSL shell:

```bash
COMPOSE_PROFILES=imports ./vendor/bin/sail up -d
./vendor/bin/sail artisan queue:work redis --queue=imports --timeout=120 --tries=3
# Run in another terminal for ongoing outbox publication:
./vendor/bin/sail artisan schedule:work
# Or publish pending events once:
./vendor/bin/sail artisan imports:dispatch
```

Configure the existing private S3 bucket/region/credentials, block public bucket access and enable bucket encryption. Set `IMPORT_SCANNER_HOST=clamav` and `IMPORT_SCANNER_PORT=3310`; no scanner port is exposed to the host. ClamAV needs sufficient memory and healthy definitions before intake. The optional development image uses `stable`; pin a reviewed image digest before production. Source objects are application-encrypted and SHA-256 checked. Livewire temporary uploads must also remain private, encrypted at rest and subject to temporary-file cleanup. Queue publication failures remain durable and return a nonzero command status. The native preview disables workbook selection when the S3 bucket is unconfigured; it never substitutes local storage or a clean scan.

Initial configurable limits: 10 MiB source, 2,000 data rows, 100 columns, 10 sheets, 64 MiB expanded XLSX; header selection is rows 1–100. These are implementation bounds, not historical workbook assumptions. XLSX/XLS parsing uses PhpSpreadsheet 5.10. Macro/embedded/external content, executable sheets, formulas, encrypted/unreadable workbooks and exceeded limits are rejected. Dates must be ISO `YYYY-MM-DD`; identifiers should be text. Existing zero-padding number formats are preserved, but precision already lost in Excel cannot be reconstructed.

Customer matching is exact client + customer reference; Batch matching is exact client + **required external reference**. Preview exercises the existing save actions inside a rolled-back savepoint; target revisions and accepted row payloads are then frozen. Processing rechecks current permissions/scopes and revisions and commits each target, row disposition and count atomically. Duplicate submissions reuse an existing intake; a failed frozen attempt can be uploaded again as a separate fresh review, preserving the previous attempt. Retrying a stale revision does not silently refresh it. Only mapped optional fields are updated; the first Customer contract covers reference, party type, name, status and optional identity, without replacing contacts, addresses or categories.

Account/task import adapters await approved source contracts, including G02; payment/databank adapters also await their modules and G05. Task/account target columns now have real foreign keys and remain null-constrained until the corresponding adapters are activated. Initial system template definitions have a nullable creator because normal seeding does not invent a user; user-created versions always record the creator. Source retention is not automatically shortened or deleted without the approved retention policy. Error CSVs are streamed on demand after a fresh access check and audit, without raw workbook values.

Traceability: Plan.md intake/mapping/security/queue contracts; Module.md M04; Prototype.md UI14, UI15, UI39, UI57 and UI58; Database.md import_fields, column_aliases, import_templates, import_mapping_profiles, import_batches, import_column_mappings, import_rows, documents and existing audit/outbox/notification entities.

## M05 Task Management & Allocation

Open **Task monitoring** at `/admin/tasks`. Create a customer account first from **Customers → customer → Accounts → Add account**, explicitly selecting the relationship. Then create a manual task with a same-client customer/account/batch, priority, planned visit, due time and encrypted instructions. Account/property data remain separate from contact/billing addresses and financial snapshots. Account writes require `customers.update` and `tasks.create` plus current client scope; task pages require active Admin access and `tasks.view`/`tasks.monitor`. Creation/editing additionally require their existing task grants.

Task detail shows current Rider, due/visit times, immutable source provenance, metadata and task/allocation history. Original customer/account/batch/type identity is fixed; cancel and create corrected work to change it. Priority, schedule and instructions remain editable on nonterminal, non-submitted work with an exact version check. Blank due time inherits the end of the batch due day in the configured business timezone. No manual IWK key, payment receipt or completion approval is invented.

Select one task from its detail, or explicit checkboxes in monitoring, then review **New / Replace / Reassign / Unassign / Cancel**. Selection is limited to 200 tasks in one active batch. New excludes already assigned work; Replace/Reassign require an existing current Rider and incomplete work; Unassign closes the current allocation; Cancel retains the task/source/history. Receiving Riders require an active eligible staff record, active Rider login and a current client scope. Selection never grants scope automatically. `tasks.assign` authorizes New, `tasks.reassign` authorizes replacement/reassignment/unassignment, and `tasks.cancel` authorizes cancellation. Submitted/completed/cancelled work is protected. Every preview shows exclusions and exact counts, and confirmation rechecks task/allocation versions.

Operations are processed from a durable outbox on the existing **default Redis queue**:

```bash
./vendor/bin/sail artisan tasks:dispatch
./vendor/bin/sail artisan queue:work redis --queue=default --timeout=120 --tries=3
# Keep the existing scheduler running for ongoing imports/tasks outbox publication.
./vendor/bin/sail artisan schedule:work
```

Queued means accepted for processing, not applied. Client/batch/operation/task locks, eligibility checks, immutable confirmed selections and atomic item outcomes protect replay and concurrency. Interrupted jobs preserve applied changes and fail remaining items; failed items require a new preview. Redacted result CSVs contain only public task IDs and outcome codes. Pending operation items block batch archival.

IWK ranges and imported tasks remain gated by G02 approved identifier/occurrence fixtures and numeric source semantics. Manual single/selected allocation works independently. M06 supplies Rider task execution, visits/business data/proof and checklist-derived filters; M07 supplies immutable submissions and independent verification. M06/M07 actions are now available subject to their grants and infrastructure gates. General task exports belong to M09; operation-result CSV is available now. Real Redis/Docker execution remains an integration gate on this native host.

Traceability: Plan.md task contracts and Phase 3; Module.md M05 and M06 account prerequisites; Prototype.md UI13/UI16/UI17/UI18/UI19/UI24/UI55/UI56; Database.md properties/debt_accounts/account_parties/tasks/task_allocations/bulk_operations/items/task_status_histories plus existing audit/outbox/deduplication storage. See the updated architecture and entity register for disposition.
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

The bootstrap command prompts for a strong password and only runs in `local` before the first user exists. It creates Admin plus explicit roles.manage/scopes.manage/users.invite grants, with audit. No accounts or passwords are seeded by the normal DatabaseSeeder. It grants no client portfolio access. Existing accounts and role bundles do not acquire these reserved grants automatically.

The Riders & staff workspace requires `admin.access` and `riders.manage`. Employment records do not create login accounts or grant roles. A separate invitation action requires `users.invite`; selecting Admin also requires `roles.manage`. Invitations never merge existing identities or add client scopes. Staff contact changes leave active account login emails unchanged. Disabling staff or removing Rider eligibility requires a reason and checks outstanding current allocations; self-removal is refused. Existing sessions enforce staff status on their next request.

The approved policy configuration supplied by the project owner is `DCMS Staff Terms v1.0` and `https://dcms.intexcode.com/privacy-policy`. The URL's live availability was not verified. Invitations use expiring, single-use password-broker tokens and send after the account transaction commits. The native preview uses the array mail transport: preparation is explicitly reported as local capture, not delivery. Configure Mailpit or an approved SMTP transport to receive messages; `MAIL_TIMEOUT` defaults to 15 seconds. Log mail transports, including failover to logs, are blocked for invitations to avoid recording activation secrets. Transport acceptance is not proof of inbox delivery.

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

Browser smoke checks can run against an already running app. Browser fixtures pace test starts with one worker to respect the real five-logins/minute policy; requests and authentication protections are not intercepted or disabled:

```bash
npm ci --ignore-scripts
npx playwright install chromium
PLAYWRIGHT_BASE_URL=http://localhost npm run test:e2e
```

Credential-based cases skip unless their `DCMS_E2E_ADMIN_EMAIL`, `DCMS_E2E_RIDER_EMAIL` and `DCMS_E2E_PASSWORD` are supplied. For full browser acceptance use a dedicated local/test database ending `_e2e`, generate a temporary runtime password, migrate it, and explicitly run BrowserTestSeeder with that password in the environment. It provisions `admin@example.test`, an explicitly scoped synthetic client, a foreign-client control record and `rider@example.test`; both accounts use the runtime secret. Start the app against that database, supply those email variables to Playwright and discard fixtures afterwards. The GitLab template demonstrates the full setup. No test sends to real customers.

Verified through 17 October 2026:

- Native PHP 8.3.30 (supported minimum) and portable isolated PostgreSQL 17.11: 169 tests / 710 assertions cover foundation, customers, staff, invitations, assignment batches, M04 imports and M05 tasks/accounts/allocation, including authorization, encryption, stale writes, archive/reopen boundaries, work-disposition guards, constraints and audit rollback. A dashboard fixture now aligns its grant with its frozen test clock rather than PostgreSQL wall-clock time; application scope rules are unchanged.
- Twenty-two Chromium Playwright workflows passed across desktop and mobile (including repeated task acceptance after the multi-account policy and responsive date-control fixes): public entry, workspace access, synthetic login/account/logout, customer create/edit/category/history/search, staff create/edit/availability/activity/search, assignment create/edit/confirmed archive/read-only history/search, import history/mapping/masked preview/CSV download/client alias publication, customer account creation, manual task create/edit, exact allocation preview/queued confirmation/cancellation/result download, and theme/navigation. Navigation checks cover OS/manual preference persistence, menu search/empty feedback, parent/child active states, compact persistence/tooltips, mobile focus/Escape/inertness, retained Account/Sign out locations, and viewport widths from 320px to 1280px. Staff checks also confirm that the account access bundle requires explicit selection.
- Frontend production build, Blade view cache and config cache/clear passed.
- Composer install resolved Laravel 13.36, Livewire 4.4, Spatie Permission 8.3, Sail 1.69 and Pest 4.7. Exact versions are in lockfiles. Composer/npm audits reported no advisories at installation.

The ignored `.env` on the initial machine uses native loopback PostgreSQL port 55432 and file sessions/cache solely for local verification. `.env.example` uses the agreed Sail/Redis services. PHP's pdo_pgsql and zip extensions were enabled per process during native tests; enable it in your own PHP runtime or use Sail. Browser runtime checks used an explicitly enabled extension as `artisan serve` does not forward `php -d` options to its child server.

Pending: Docker Compose validation and PHP 8.4/Sail boot, Redis-backed session/queue/cache integration, configured GitLab runner/mirror execution, actual S3 access/scanning, AWS/Pulse/Sentry operational activation and provider capability tests. Passing native tests does not claim these integrations are operational. Foundation code is ready for the next module; these remain environment/integration acceptance gates.

## GitHub, GitLab and deployment

Local Git uses `main`; this increment does not create a commit, push or deployment. GitHub remains canonical and GitLab receives a read-only pull mirror. Configure a protected trusted `GITHUB_CANONICAL_REPOSITORY`, protected branches and required GitHub reviews before activating `.gitlab-ci.yml`. The template verifies the mirrored SHA against GitHub main, installs locked dependencies, tests PostgreSQL, builds assets and runs browser checks. It has no deployment stage. Verify it on the chosen runner before relying on it.

Local image tags are development baselines. Resolve and commit approved image digests for production during compatibility/deployment work. AWS ECS/ECR/RDS/private S3/KMS/Secrets Manager, CloudWatch, OIDC, scanning/signing, migrations/cutover approvals and restore drills follow Plan.md. The owner supplied the privacy-policy URL on dcms.intexcode.com; domain/TLS provisioning and messaging-provider activation remain deployment work. DRMS's domain must remain untouched.

## Development rules

DebtOps — Debt Collection Management System, developed by InteXcode — uses the supplied full logo at `public/images/brand/debtops-logo.png` (1.png) and transparent icon at `public/images/brand/debtops-icon-transparent.png` (the latest supplied background-free asset). These are byte-for-byte copies of the originals. `x-brand` uses CSS viewports to frame the supplied artwork without changing the files; the full wordmark retains a black panel for readability; the replacement icon has no black backing in either theme. Expanded navigation and login use the full wordmark; compact navigation/mobile headers use the icon. Shared identity/asset paths live in `config/dcms.php` under `branding`. The centralized Tailwind 4 `@theme` in `resources/css/app.css` defines `brand-primary` (#FCDE08, the most frequent yellow sampled from the supplied icon), black/white and neutral ink colours. White is the dominant light-mode surface; yellow primary actions use black text. Semantic red/green/amber remain reserved for errors, success and warnings. Future interfaces and exports must use the shared branding/configuration and UI components.

The owner-approved rebrand updates names in Plan.md, Module.md, Prototype.md and Database.md without changing their functional requirements. Existing internal `dcms` configuration/command names, `DCMS_*` environment variables, database identifiers, browser preference keys and Composer package identifier remain stable. `APP_NAME`, `VITE_APP_NAME` and the mail sender display name present DebtOps. Explicit legacy session-cookie/cache/Redis prefixes preserve application continuity when changing the display name. Keep existing prefixes when updating another environment. The previously approved `DCMS Staff Terms v1.0` and privacy-policy URL remain exact approved references; rebranding does not rewrite legal versions or provision a new domain. Reports and bulk communication are still planned modules; their future output must inherit DebtOps branding rather than implying these workflows already exist.

DebtOps rebranding verification on 16 October 2026: the 106-test backend regression suite and 16 Chromium desktop/mobile workflows passed. Browser checks include DebtOps titles, favicon/full-logo references, compact icon visibility and both themes. Production assets, Blade compilation/PHP lint, Pint and Composer validation passed. Copied logo SHA-256 hashes match the supplied originals. Local preview continues on port 8000 with its existing database and account controls.

The UI/UX redesign extends this system with `x-page-header`, `x-icon`, `x-badge`, `x-empty-state`, `x-loading` and a native-dialog `x-confirm-action`. Shared surfaces, metrics, tables, form sections, tabs, mobile navigation, password visibility controls and reduced-motion rules live in the same stylesheet/components. The desktop sidebar groups upcoming modules separately from usable navigation. Error pages cover 403/404/419/500 without exposing exception details. Bulk category changes require a confirmation of the explicit selection before calling the existing action; other validation and transaction rules are unchanged.

The navigation redesign uses `x-sidebar`, `x-sidebar-item`, `x-sidebar-group`, `x-sidebar-submenu`, `x-sidebar-tooltip` and `x-theme-toggle`. The rounded desktop sidebar expands by default, remembers compact mode and shows named tooltips on hover/focus. Menu search filters only permission-rendered navigation; Customer management groups Customers and Customer categories with active parent/child states. Unimplemented modules remain non-actionable and explicitly Planned. The Admin header retains Account/user information/Sign out; Rider retains header Sign out and bottom Account. Mobile drawers support focus containment, background inertness, Escape, backdrop dismissal and left swipe to close.

Appearance initialization in `partials/ui-preferences.blade.php` runs before styles render, follows the operating system until a manual light/dark choice, and stores only UI preferences in browser storage (`dcms.theme`, `dcms.sidebar`). Storage failures do not prevent in-page theme switching. Tailwind's class-driven `dark` variant and centralized surface/ink/semantic overrides in `resources/css/app.css` adapt existing forms, cards, tables, authentication and feedback. Yellow stays #FCDE08 with black text on primary actions. Reduced-motion preferences limit transitions. This owner-requested presentation update follows Prototype.md's shared-shell/navigation contract, Module.md permission boundaries and Plan.md's existing frontend stack; routes, role bundles, business actions and database schema are unchanged.

Dashboard traceability: Prototype.md UI04/UI07, Module.md M02/M13, Plan.md scoped dashboard/security contracts and Database.md debtors/clients/user_client_scopes/audit_logs. `CustomerOverview` reuses current customer permission and client scopes for total/active/inactive counts, permitted client count, seven local calendar days of record creation, five latest customers and five recent customer audit events. This is the available customer slice of the dashboard, not completion of M02's task/verification/attendance metrics. The Rider home explains pending field tools rather than supplying simulated tasks or attendance actions. No business write action, permission bundle, schema or provider integration is changed by this redesign.

Redesign verification on 16 October 2026: 71 PostgreSQL-backed tests / 265 assertions, eight Chromium desktop/mobile workflows, production asset build, Blade compilation and Pint passed. Additional browser checks exercised password visibility, 320px/430px forms, mobile menu focus/Escape restoration, native confirmation cancel/submit, immediate bulk-action success feedback and branded 404 rendering. Livewire success/error feedback is rendered inside page components so it updates after server actions. The original local preview database was preserved; acceptance used a separate synthetic database.

Keep Livewire/controllers thin; use validated inputs, explicit action permissions plus record policies/client scope, transactions and locks for critical writes, reusable UI and scoped queries. Every major change must identify Plan section, Module ID, UI ID and Database table/constraint in its PR. Highlight technical conflicts before changing intended behaviour. Read [known findings](docs/foundation-review.md) before implementing bulk selection, legacy imports or databank. Tests accompany each module. Avoid placeholder financial/attendance rules, fabricated KPIs and external messages triggered without a configured business rule.


## M06 Rider Execution & Site Visits

Open **Rider → My Tasks** at /rider/tasks. Current allocation, an active eligible Rider login, tasks.view/tasks.submit and an effective client scope are required. Start records an atomic history event. Save visits with availability, site status, contact result, outcome, separate visit/collection/customer remarks, applicable coded business details and due follow-ups. Customer identification numbers and unrelated records are hidden. Admin task detail and Customer → Visits / Collection show scoped recorded activity.

Owner-approved choices and policies are in reference_values, seeded by RiderReferenceSeeder without overwriting later master-data edits. The seven documented assignment types receive an execution_policy permitting Normal Site Visit, Address Verification, Collection Visit, Payment Verification and Customer Not Found / Invalid Address. These purposes qualify the assignment type. Purpose metadata holds version, backdate_calendar_days, min_images, required_categories, required_remarks, business_required and signboard_when_available. Assignment metadata holds version, purpose_codes and optional per-purpose overrides. New types or malformed/inactive policies fail closed. Master-data administration UI belongs to M12; authorized maintenance can change these records without editing application code. Submissions pin policy versions and lookup labels.

One previous MYT calendar day is allowed, with an encrypted reason for a selected time before the current minute and a redacted backdating audit. Original server entry time is preserved. No future visits or Rider override for older corrections. Payment Made / Payment Claimed always requires Payment Evidence and does not post cash. Address Verification requires signboard proof when declared available. Callbacks, Promise to Pay and Follow-Up Required require a due follow-up. Applicable business visits require all three coded selections; Other requires explanation.

Proof uses private, application-encrypted S3 objects with validated raster content, bounded dimensions/memory, EXIF removal, uploader/server time and exact task/activity linkage. Defaults: 10 MiB/image, 10 clean images/submission, 8192px and 24 megapixels. JPEG/PNG/WebP are supported. HEIC needs a safe decoder unavailable here; convert to JPEG/PNG. Signed temporary browser selections use Livewire's private temporary storage and cleanup; production requires encrypted private temporary volumes and transport. Nothing is published to the public disk.

~~~bash
./vendor/bin/sail artisan tasks:dispatch-proof
./vendor/bin/sail artisan queue:work redis --queue=media --timeout=120 --tries=3
./vendor/bin/sail artisan schedule:work
~~~

The existing optional Sail profile imports supplies ClamAV for workbooks and images. Keep it and the media worker running. Pending/error/rejected proof blocks completion. Retry scan errors after restoring infrastructure; replace rejected images. Draft removal soft-deletes metadata and preserves the object pending approved retention. A new visit save creates an immutable activity version and needs proof linked to that version; submitted images remain frozen.

Completion review shows saved information, anchored missing requirements and a disabled submission control until valid. Confirmation freezes an encrypted TaskSubmission and exact Document manifest, then sets submitted/pending verification. Duplicate retries return the same receipt. Returned work starts a correction draft/new version; only M07 independent review can mark Completed. After reassignment the previous Rider can see only own redacted submission receipts within current client access.

Traceability: Plan.md task/mobile/private storage/security and approved M06 policy; Module.md M06; Prototype.md UI22/23/47–52; Database.md collection_activities, property_inspections, follow_ups, documents, task_submissions, task_submission_documents and task_status_histories. Legacy-only TaskObservation/occupancy/LOD/CTOS mapping remains gated. The native runtime has no active S3/Redis/ClamAV; real infrastructure and physical-device camera checks remain integration gates. M07 independent Admin verification is implemented below.


## M07 Independent Task Verification

Open **Verification** at /admin/verification. Active Admin access, tasks.view/tasks.monitor/tasks.verify and current client scope are required. Review a frozen submission from the queue or the task detail link; filter by submission state, batch, submitting Rider, exact account/customer reference and MYT dates. The detail shows submitted customer/account context, visit/business/typed remarks/follow-ups, original times, exact private proof manifest, pinned policy and prior cycles.

Prepare **Approve / Verify** or **Reject / Return** with required return remarks. The submitter cannot be the reviewer, including dual-role users. Confirmation locks client → batch → task → submission, verifies the current pending version and records an immutable TaskReview. Approval requires the complete frozen policy/clean manifest and sets completed/approved with completed_at. Return can request correction for deficient evidence, sets in_progress/returned, and leaves every previous visit/photo/submission unchanged. Remarks are encrypted; audit events are redacted. In-app database notifications commit atomically, with no external email/WhatsApp delivery.

Bulk review takes only explicit selected submission UUIDs, up to 50, with a signed 20-minute preview. It lists each row's eligibility. Confirmation rechecks permissions/scopes/versions. Any invalid row aborts all new decisions with per-row errors; valid work is never silently partially applied. Retrying the same accepted preview returns the existing review receipt. New correction submissions append reviews linked to the preceding cycle; accepted decisions are never overwritten. Completed work remains read-only; broader reopen/correction authorization is separate policy work.

Riders see verification notices on Home and full correction instructions on their currently assigned task. Starting correction clears the current returned projection while retaining decisions and frozen proof. Dashboard task counts now distinguish active, pending verification, verified completed and returned work; links use the same scope/status. Exports follow M09, and historical DRMS numeric ranges remain gated by G02/G03 source semantics.

Traceability: Plan.md task lifecycle/security/KPIs; Module.md M07 confirmed review contract; Prototype.md UI25/26/42; Database.md task_reviews, task_submissions/manifest, task_status_histories, audit_logs and notifications. Real private storage/scanner/queue and physical-device camera acceptance remain infrastructure gates from M06.

## M10 Attendance

Open **Attendance** in Admin navigation and the Rider bottom navigation. UI33/UI53/UI59 use the existing staff/users and the four documented attendance entities. The owner-approved initial rules are in Module.md#m10-approved-policy and reflected in all four source documents. `AttendancePolicySeeder` creates only an inactive initial template; it never invents a database approver or staff calendar. An authorized administrator publishes an immutable approved version in **Schedules & policies**, then explicitly assigns dates to staff. Existing policy versions stay pinned. Assignments cover today/future dates, up to 93 at a time; existing dates require an independently approved correction. Rest days, authorized leave, holidays and replacement shifts are explicit calendar entries, not guessed public holidays.

Initial template: Mon–Fri 09:00–18:00 MYT, fixed 60-minute unpaid break per completed interval, inclusive 15-minute grace, Present >=420 net minutes, Half Day >=240, otherwise Incomplete. Late is a separate flag measuring arrival after scheduled start; no penalties/payroll exist. Explicit overnight schedules retain the scheduled start service date. No-punch Absent appears only after an expected shift ends; an open, incomplete or unscheduled record never silently becomes Absent.

Riders use server clock times, one interval per shift, UUID request replay and read-only raw punches. Personal history remains available after client-grant expiry. Staff who already have Admin login access may use `/attendance` with separately granted `attendance.view_own`/`attendance.clock`; Rider task eligibility remains unchanged. Permission bundles are not expanded automatically. Admin views require `attendance.view_all`, active Admin access and current shared client grants with the staff account. Schedules require `attendance.configure`; requests require `attendance.correct`; independent decisions require the reserved `attendance.approve_correction`. A requester or the attendance owner cannot approve their own correction.

Correction requests retain original/proposed values, versions, reason and independent decision history. Stale targets require rejection/re-request. A missed clock-out can be closed in the effective projection without inventing a raw event. Authorized planned-shift corrections preserve the old calendar in the snapshot; raw events remain unchanged. Unscheduled punches require an explicit calendar assignment and approved link correction. Database-only notices and redacted audit commit with the decision; no external message is sent. Supporting attachments are not mandatory under this approved policy.

The register provides date/status/staff/client filters, pagination, truthful totals and matching drill-downs. CSV and printer-friendly **Save as PDF** reports materialize one filtered PostgreSQL snapshot, exclude reasons/private remarks, escape spreadsheet formulas, recheck scope and cap immediate exports at 5,000 rows. General queued reporting remains M09. User-facing times are MYT; persistence remains UTC. Docker/WSL/Sail integration remains a local infrastructure acceptance gate.

M10 validation (18 October 2026): full PostgreSQL regression run passed 233 tests / 1,106 assertions; after final input/identity/constraint hardening, the focused attendance suite passed all 33 tests / 171 assertions. This covers 235 distinct backend cases across the full run and affected reruns, not a single 235-test invocation. Twelve existing desktop/mobile authentication/navigation checks and both end-to-end attendance journeys passed. Rider correction inputs fit 320px and 430px viewports; screenshots were inspected. Asset build and required Pint formatting passed. Both additive migrations and the inactive template were applied to the preserved local preview. No staff calendars, new client scopes or approver grants were automatically seeded there.

## M11 Call Centre and follow-ups

The Admin sidebar now opens Call Centre at `/admin/calls`. Access requires active `admin.access`, `calls.record`, `customers.view` and a current active client scope. Search by exact customer reference, name prefix, exact contact/account or permitted task UUID, with client/category/current Rider filters. Sensitive search values stay in the session rather than URLs. Customer and task screens link to the same workspace; customer Communication history reuses the call/authorized visit timeline.

Record manual outgoing calls at server time, or today/yesterday in Asia/Kuala_Lumpur with a required backdating reason. Eight configured outcomes include required Other details, Callback follow-up and Promise to Pay notes/follow-up. Optional stated promise amounts are context only and do not create payments or receipts. Phone snapshots, outcome labels, remarks and original entry time remain immutable and encrypted. Saving a call sends no external communication.

Contact edits require `customers.update_contacts`, independently of full customer editing, and retain superseded observations with actor provenance and stale-edit checks. `/admin/calls/follow-ups` provides scoped due/status/assignee filters. Only the assigned agent resolves their own pending call reminder, with required remarks and a retained done/cancelled decision. Visit reminders are visible only with task access and retain their source workflow. Legacy databank and real email/WhatsApp/voice history await their approved source/provider modules.

Traceability: Plan.md communication strategy; Module.md M11 implementation contract; Prototype.md UI60/UI64; Database.md debtors, contacts, collection_activities and follow_ups. The additive migration adds encrypted call snapshots, a chronological customer index and immutable versioned reminder decision metadata; it does not replace the canonical entities.

M11 validation (18 October 2026): the full PostgreSQL suite passed 252 tests / 1,204 assertions. After final activity-type/policy/pagination hardening, both affected suites passed 42 tests / 253 assertions, covering 257 distinct backend cases across the full run and affected reruns. Fourteen distinct desktop/mobile browser checks passed across the initial run and reruns (three initially skipped because the Rider fixture email was omitted were rerun successfully). This includes both Call Centre journeys, conditional historical/PTP date fields at 320px/430px, preserved contact history, shared customer timeline and reminder decisions, with no captured page errors. Screenshots were inspected; asset build and required Pint passed. The preserved native preview received the additive M11 migration and call reference seed only. Docker/WSL/Sail and actual external provider integrations remain separate acceptance gates.
