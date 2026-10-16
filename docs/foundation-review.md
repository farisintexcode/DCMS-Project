# DCMS foundation review — 16 October 2026

The four root documents are the requirements authority. This file records implementation disposition, not replacement requirements. C sections override historical O/U evidence. Product: DCMS by InteXcode; DRMS remains a reference only.

## Dependency order

Follow Plan.md §11 rather than the suggested eighteen independent phases:

0. Resolve legacy workbook matching/finance fixtures, attendance policy values, template owners, production domain and provider feasibility alongside development. These gate affected activation, not all development.
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

All entity names and columns remain in Database.md; its entity catalogue is the complete future inventory. Only foundation migrations/models are executable in this increment. Avoid speculative empty models and migrations for later workflows.

## Access contract

Admin and Rider are the minimum roles; both use the web session guard. Optional future bundles: Super Admin, Call Centre Agent, Supervisor, Operations Manager, Finance User. No role-name bypass; action grants plus effective client scope and record policy are required. Riders additionally require active eligible staff and current task allocation. Own historical submissions are masked after reassignment. Positions 01/02/03/04/05/99/06 are reference values, never roles.

Granular grants are declared in config/dcms.php. Seeded bundles are a reviewable development baseline; production grants require business sign-off per Database.md §Seeders. Independent correction/template approval and role administration are separate grants, excluded from the ordinary Admin bundle. Bootstrap authority is provisioned through an explicit local command with a prompted secret, never a seeded password.

Admin shell: fixed header, responsive collapsible sidebar, breadcrumbs, desktop/tablet main content. Rider shell: single column, 44px targets, bottom Home/My Tasks/Attendance/Account navigation. Pending modules are labelled and have no actionable links; no fabricated dashboard metrics.

## Technical findings before affected implementation

1. Database.md ERD declares entities and morph edges but omits most relational edges. The per-table FK definitions and relationship section are authoritative for implementation. No new relationship is inferred from the drawing.
2. bulk_operations.from_iwk_id/to_iwk_id are mandatory while M05/UI16 confirm single and selected task IDs. Before M05, add an explicit selection mode and conditional nullable range bounds (with exact bulk_operation_items); do not invent fake source IDs. This increment does not migrate that table.
3. Plan.md says template approval uses separate grants; Database.md explicitly prohibits self-approval for review and attendance. Template approval authority remains separate; content policy must be agreed before sending.
4. Private S3 and provider interfaces are foundation configuration; no external messaging or AWS activation is implied. Exactly three draft template slots will be seeded with the communications schema, not speculative approved content.
5. Databank schema is expressly provisional/G05 gated. Legacy matching keys, fees and source boolean vocabularies require fixtures. No guessed defaults or source semantics.
6. UTC persistence and Asia/Kuala_Lumpur display are distinct; APP timezone stays UTC, business timezone is separate.
7. Docker Desktop/WSL is not installed on this machine. Native PHP 8.3 exists outside PATH; Sail targets PHP 8.4. Local checks do not substitute for the documented Sail integration gate.

## Foundation exit gate

Install locked dependencies, build assets, migrate an empty PostgreSQL database, seed references/roles, provision an account, test login/logout/recovery/activation, status enforcement on existing sessions, Admin/Rider isolation, effective scopes and immutable audit. Run PostgreSQL Pest and Playwright on desktop and 320–430px Rider viewports. Validate Compose and Redis sessions/queues/cache with Sail. Record actual results in README; unrun checks remain explicit.
