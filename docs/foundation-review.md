# DebtOps foundation review — 16 October 2026

The four root documents are the requirements authority. This file records implementation disposition, not replacement requirements. C sections override historical O/U evidence. Product: DebtOps by InteXcode; DRMS remains a reference only.

## Dependency order

Follow Plan.md §11 rather than the suggested eighteen independent phases:

0. Resolve legacy workbook matching/finance fixtures, template owners, production domain and provider feasibility alongside development. Initial M10 policy values were approved on 18 October 2026; explicit authorized calendar assignments activate expectations. Remaining decisions gate affected activation, not all development.
1. Foundation: Laravel/Sail/PostgreSQL/Redis, identity, Admin/Rider permission bundles, explicit client scopes, reference data, audit/outbox, staff/customer foundations and private storage configuration.
2. M13 customers/categories, M03 batches and account/property/party foundations; M04 field registry/aliases/profiles, mapping review, staging/preview/confirmation/reconciliation. Staff eligibility precedes assigned-Rider import.
3. M05 manual/imported tasks, single/selected/range allocation, monitoring; M06 mobile visits/business/remarks/proof.
4. M07 immutable completion submissions and independent review; M10 schedules/punches/corrections; M02 truthful operational KPIs.
5. M11 customer Call Centre/follow-ups; M14 three templates, single/bulk email, quotas/receipts and WhatsApp messaging.
6. M14 provider-qualified calling; M09 all ten report families, financial snapshot reporting/M08 updates and integration acceptance. Legacy databank waits for G05.
7. Pilot, security/load/mobile/accessibility, backup restore, reconciliation and approved deployment.

Testing accompanies each step. Audit, authorization and durable processing begin in step 1 rather than waiting until the end.

## Confirmed module and interface traceability

| Module | Interfaces (Prototype.md) | Database.md entities / dependencies | Plan.md |
|---|---|---|---|
| M01 Access | UI01–06, UI46 | staff/users, Spatie pivots, scopes, reset tokens, audit | §4, §7 |
| M02 Dashboards | UI07–08, UI47 | scoped task/review/allocation/attendance/customer projections | operational KPIs |
| M03 Batches | UI09–13 | clients, reference_values, assignment_batches | §5 |
| M04 Import/mapping | UI14–15, UI39, UI57–58 | templates/batches/rows, fields/aliases/profiles/mappings, documents, outbox | automated mapping |
| M05 Task operations | UI16–19, UI55–56 | accounts/parties/tasks, allocations, bulk operations/items, histories | task contracts |
| M06 Rider execution | UI19–24, UI42–44, UI48–52 | activities, inspections, observations, follow-ups, documents, submissions | task contracts |
| M07 Verification | UI25–26, UI42, UI51–52 | submissions/proof manifest/reviews/history; independent reviewer | task contracts |
| M08 Financial updates | UI14, UI19, UI28 | financial_updates/snapshots; source row/account | §7; G04 |
| M09 Reports | UI27, UI29, UI41 | export_jobs/documents; ten scoped report projections | §9 |
| M10 Rider/attendance | UI30–33, UI53–54, UI59 | staff/users, policies/schedules/attendances/adjustments | attendance contracts |
| M11 Call Centre/databank | UI34–35, UI60, UI64 | activities(call), follow-ups, contacts; provisional databank entities | §5; G05 |
| M12 Administration | UI36–41, UI43–45, UI70 | roles/permissions/scopes, settings, audit, notifications, outbox/processed_events | §4, §10 |
| M13 Customers | UI24, UI61–63 | debtors/contacts/addresses/account_parties/categories/memberships | customer contracts |
| M14 Communications | UI64–70 | integration accounts/events, templates/batches/conversations/communications/attempts/events/preferences/quota reservations/call permissions/voice sessions | communication contracts |

All entity names and columns remain in Database.md; its entity catalogue is the complete future inventory. Foundation, M13 and M03 batch migrations/models are executable. M10 personnel reuses the existing staff schema. Avoid speculative empty models and migrations for later workflows.

M10 personnel implementation traceability: Module.md M10 personnel/availability and M01 controlled invitations; Prototype.md UI30 directory, UI31 creation, UI32 edit, UI54 Rider profile and UI03 activation; Database.md staff/users/reference_values/password reset tokens/audit_logs; Plan.md access/security and staff eligibility dependencies before imports/allocation. Directory filters cover Rider/office, status, position and appointment dates. Profile, employment/contact edit and redacted activity history are usable. Assigned/completed tasks, attendance and performance remain explicit pending tabs until their source modules arrive. No fabricated operational data or duplicate Rider table was added.

The owner approved `DCMS Staff Terms v1.0` and `https://dcms.intexcode.com/privacy-policy` on 16 October 2026. Configuration is set; endpoint availability and real mail delivery remain unverified. Native preview mail is captured locally. The separately reserved `users.invite` permission is deliberately excluded from ordinary Admin bundles; existing grants are preserved. The local first-admin bootstrap and isolated synthetic browser Admin explicitly receive it. Admin-role invitation additionally requires `roles.manage`.

M03 batch metadata traceability: Plan.md §5/§11 dependency order and security; Module.md M03 validation/archive contracts; Prototype.md UI09/UI10 active/archive registers and UI11/UI12 grouped forms, conflict feedback and archive confirmation; Database.md assignment_batches/client/reference/user/audit definitions. Registers support scoped client/type/state/start-date filters, literal private-session name/reference search, allowlisted sort and pagination, with mobile cards. UI13 tasks, Upload Task, allocation counts and exports connect through M04/M05/M09; they are not simulated. M04 intake/mapping and the M05 canonical account/task foundations are now implemented; see their dispositions below.

M03 implementation decisions within the proposed archive contract: creation starts active; metadata and archive/reopen use separate actions; archive and reopen each require a reason. `assignments.manage` controls writes and archive; separate reserved `assignments.reopen` controls reopening. Existing role bundles/grants remain unchanged. A batch's client is immutable, and type changes stop after child work exists to preserve historical meaning. Archive checks include completed-task approval and terminal import-row reconciliation; later intake writers must follow the documented parent-lock contract. No standalone batch delete is introduced.

## Access contract

Admin and Rider are the minimum roles; both use the web session guard. Optional future bundles: Super Admin, Call Centre Agent, Supervisor, Operations Manager, Finance User. No role-name bypass; action grants plus effective client scope and record policy are required. Riders additionally require active eligible staff and current task allocation. Own historical submissions are masked after reassignment. Positions 01/02/03/04/05/99/06 are reference values, never roles.

Granular grants are declared in config/dcms.php. Seeded bundles are a reviewable development baseline; production grants require business sign-off per Database.md §Seeders. Independent correction/template approval and role administration are separate grants, excluded from the ordinary Admin bundle. Bootstrap authority is provisioned through an explicit local command with a prompted secret, never a seeded password.

Admin shell: fixed header, responsive collapsible sidebar, breadcrumbs, desktop/tablet main content. Rider shell: single column, 44px targets, bottom Home/My Tasks/Attendance/Account navigation. Pending modules are labelled and have no actionable links; no fabricated dashboard metrics.

## Technical findings before affected implementation

1. Database.md ERD declares entities and morph edges but omits most relational edges. The per-table FK definitions and relationship section are authoritative for implementation. No new relationship is inferred from the drawing.
2. bulk_operations.from_iwk_id/to_iwk_id are mandatory while M05/UI16 confirm single and selected task IDs. Resolved in M05: explicit selection mode and conditional nullable range bounds accompany exact bulk_operation_items; manual tasks never receive fake source IDs.
3. Plan.md says template approval uses separate grants; Database.md explicitly prohibits self-approval for review and attendance. Template approval authority remains separate; content policy must be agreed before sending.
4. Private S3 and provider interfaces are foundation configuration; no external messaging or AWS activation is implied. The communications schema seeds exactly three owner-approved version 1.0 defaults supplied on 18 October 2026; later versions follow submission/review controls.
5. Databank schema is expressly provisional/G05 gated. Legacy matching keys, fees and source boolean vocabularies require fixtures. No guessed defaults or source semantics.
6. UTC persistence and Asia/Kuala_Lumpur display are distinct; APP timezone stays UTC, business timezone is separate.
7. Docker Desktop/WSL is not installed on this machine. Native PHP 8.3 exists outside PATH; Sail targets PHP 8.4. Local checks do not substitute for the documented Sail integration gate.

## Foundation exit gate

Install locked dependencies, build assets, migrate an empty PostgreSQL database, seed references/roles, provision an account, test login/logout/recovery/activation, status enforcement on existing sessions, Admin/Rider isolation, effective scopes and immutable audit. Run PostgreSQL Pest and Playwright on desktop and 320–430px Rider viewports. Validate Compose and Redis sessions/queues/cache with Sail. Record actual results in README; unrun checks remain explicit.

M04 traceability increment: UI14 upload/history, UI57 reviewed columns, UI15 masked preview/confirmation/progress/errors, UI58 job history/details and UI39 versioned registry/client aliases now use the canonical import registry, staging and documents. Initial executable target contracts are M13 Customer basic fields and M03 Assignment Batch metadata, preserving client/customer-reference and client/external-reference matching. The latter requires external reference for deterministic imports even though manual batch creation allows blank references. Contact/address/category import bindings, account/property/task/payment/databank adapters remain explicit future contract versions rather than guessed mappings. Account/task target ID placeholders are null-constrained until those real tables and FKs exist.

Technical implementation choices: 10 MiB/2,000-row configurable bounds, bounded validated XLS/XLSX parser, fail-closed optional ClamAV, private application-encrypted source, rollback-only real-writer preview and atomic row commits. System-seeded template creators are nullable to avoid inventing user accounts; administrator versions always record their actor. The source/mapping/row identity and immutable confirmed snapshot jointly control replay; uploading a failed frozen attempt creates a separate reviewed attempt. The next dependency is the canonical account/property/party/task foundation before assignment-task intake and allocation. Real S3/Redis/scanner and Docker integration must pass before operational intake is declared ready.

M05 implementation — 17 October 2026: UI55 task monitoring, UI56 manual task creation/metadata editing, UI19 task/source/history detail, UI16/UI17 operation forms and UI18 exact-selection review/results now use properties, debt_accounts, account_parties, tasks, task_allocations, bulk_operations/items and task_status_histories. Customer UI24 Accounts creates/edits a client account and records an explicit customer role; no address-based property merge or legal-liability inference occurs. Account context writes use active client scope plus customers.update and tasks.create; read access follows customers.view. Task monitoring/actions use the existing task grants and current client scope. Existing user roles/grants are not rewritten.

The prior mandatory-range schema finding is resolved with selection_mode and conditionally nullable bounds. Manual tasks use public UUIDs and no invented IWK ID. Single/selected operations have a 200-task bound and immutable criteria, exact task versions/current allocations, exclusion codes and row outcomes. New only targets unassigned work; Replace/Reassign require an existing Rider and incomplete work; Unassign closes history; Cancel preserves all records. Submitted/completed/cancelled tasks are protected; no transfer/reopen review policy is invented. Receiving Riders require active eligible staff/login, rider.access and effective client scope. Production scope administration remains governed by scopes.manage; assignment never grants portfolio access implicitly.

Source context is frozen after task creation; supported edits are priority, visit/due timing and instructions/remarks. Blank task due time inherits the end of the batch due day in Asia/Kuala_Lumpur. Account/property/party history uses precise UTC timestamps; validity checks remain strict. Operation confirmation recomputes the exact task/allocation digest. Processing rechecks permissions and eligibility, then commits allocation/task/history/item/count outcomes under client→batch→operation/task locks. Queued operations block batch archival. Results are redacted task UUID/state/code CSVs. Queue publication is durable through tasks:dispatch and the default Redis queue; native preview does not run a fake worker.

G02 legacy source identifiers/occurrence matching and approved numeric range fixtures remain required before task imports/range activation. The M04 shared adapters remain unchanged; their deferred task/account target columns now have real FKs and stay null-constrained while those adapters are gated. M06 visits/proof/Rider execution and M07 submissions/independent verification are next. Visited/pending-completion filters show that dependency rather than inventing progress. Financial/attendance/communications/report behavior remains outside this increment.


M06 traceability: Plan.md owner-approved initial Rider policy (17 October 2026); Module.md M06; Prototype.md UI22/23/47–52; Database.md activities/inspections/follow-ups/documents/submissions/manifest/history and approved physical extensions. Choices are master data that preserve later edits, without mapping unknown DRMS flags. Purpose policies qualify existing assignment types with per-type overrides. G06 is resolved for the initial confirmed Rider workflow; historical source flags/boolean mappings remain unapproved.

Implemented scoped mobile work, atomic start, versioned visits/remarks/business/Other/follow-ups, bounded calendar backdating with encrypted reason/redacted audit, exact visit-linked private proof with fail-closed queued ClamAV scans, checklist/digest and immutable pending-verification submissions. Submitted proof cannot be replaced/deleted or appended to a frozen manifest. Corrections preserve prior versions; former assignees see only masked own receipts. Independent M07 decisions are next.

Images are raster-reencoded to strip metadata, encrypted and scanned. HEIC remains unavailable without a safe supported decoder. Existing optional ClamAV is reused with no dependency addition. Native Docker/S3/Redis/ClamAV and physical-device camera behavior remain integration gates. Backend tests explicitly fake storage/scanner boundaries; real browser tests exercise visits and blocked completion while storage is absent.


M07 (18 October 2026) implements the confirmed independent Admin review contract, UI25/26/42 and canonical TaskReview. Queue/detail/history, approve/completed, mandatory-reason return/in_progress, encrypted verification remarks, exact frozen proof/policy checks, optimistic conflicts, atomic selected-row review and in-app Rider notifications are present. Self-review is rejected by policy, action and PostgreSQL. Accepted reviews cannot be edited/deleted; a new correction cycle preserves and links its predecessor. An invalid selected row aborts all decisions with explicit row feedback.

Only approval requires a complete clean manifest: Return must remain available for deficient submitted evidence so correction is possible. Review does not re-evaluate the submission against later master-data edits. Source numeric ranges/export families and broader completed-work reopening remain separate gated scope. Combined validation covers 202 distinct PostgreSQL backend tests and 26 desktop/mobile workflows; affected task/review suites were rechecked after fixes. Native S3/Redis/ClamAV/Docker and physical camera behavior remain integration gates.

M10 Attendance (18 October 2026) follows Plan.md#plan-attendance, the owner-approved Module.md#m10-approved-policy, Prototype.md UI33/UI53/UI59 and Database.md attendance_policies/schedules/attendances/adjustments. The four source documents were updated with approved weekday/shift/break/grace/threshold/overnight/calendar and independent correction rules before completion. Policies are immutable versions; initial seed is inactive until an authorized account publishes a version, with no invented approver or automatic staff calendar. Separate grants control configuration, own punches, scoped review, correction requests/independent decisions and exports. Existing roles/scopes remain unchanged.

Staff locks, request UUIDs, versions, partial unique indexes and PostgreSQL history guards protect actual punches and corrections. Approved missing-clock-out fixes close the effective interval only. Present/Half Day/Incomplete use net worked minutes; lateness is a separate flag. Explicit Rest Day/Public Holiday/Leave and no-punch completed expected shifts drive calendar results. Pending Correction is an overlay. The forward shift-end constraint handles PostgreSQL CHECK's null/UNKNOWN semantics explicitly. Own history survives client-scope expiry; Admin attendance requires shared current active client grants, while personnel access remains organization-wide. CSV/print-to-PDF use one bounded materialized query and redact reasons/private remarks; broad queued reporting remains M09.

Validation: 233-test full PostgreSQL regression / 1,106 assertions passed; final 33-test attendance suite / 171 assertions passed after affected changes. Fourteen desktop/mobile browser cases passed, including explicit 320px/430px form checks; screenshots inspected; assets/Pint passed. Local preview received only additive migrations and inactive policy template. Docker/WSL/Sail integration remains unverified. The next dependency is M11 Call Centre/follow-ups; legacy databank G05 and financial source interpretation remain separate gates.

M11 Call Centre/follow-ups (18 October 2026) now implements UI60 and the call/authorized-visit slice of UI64 against the canonical customer/contact/activity/reminder entities. The four authority documents incorporate the owner's approved one-calendar-day MYT backdating limit and required reason. Manual call snapshots are encrypted and append-only; optional task/contact context, current scopes and permissions are rechecked at write/replay. Contact-only changes preserve superseded observations and require their separate grant/revision. Assigned agents resolve only their own pending call reminders; PostgreSQL retains original context and terminal decisions. Call-only outcomes cannot enter Rider visit selectors or validation; linked calls cannot masquerade as site visits in either task interface.

Validation: full PostgreSQL run 252 tests / 1,204 assertions; final affected Call Centre/Rider suites 42 tests / 253 assertions passed, covering 257 distinct backend cases across runs. Fourteen distinct browser cases passed across desktop/mobile runs and reruns, including conditional forms at 320px/430px, authentication/navigation, contacts, call history and reminder resolution. Screenshots inspected; assets/Pint passed. Only additive schema and call reference values were applied to the preserved preview. Native external integrations and Docker/WSL/Sail remain unverified. Next dependency: M14 communication/templates and channel history; legacy databank G05 remains separately gated.
