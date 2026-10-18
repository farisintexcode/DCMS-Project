# Customer Management implementation

Tracking notes only. Root Plan.md, Module.md, Prototype.md and Database.md remain authoritative and unchanged.

| Requirement | Interface | Persistence / implementation |
|---|---|---|
| Module.md M13 customer list, search and maintenance; Plan.md customer contract and security strategy | Prototype.md UI62 / UI63 | debtors; DebtorPolicy, ClientAccess, CustomerSearch, SaveCustomer |
| M13 customer details, current contacts/addresses and provenance | UI24 / UI63 | contacts / addresses; encrypted values, append-and-expire observations |
| M13 client-specific categories, multiple memberships, assign/revoke | UI61 / UI24 / UI63 | customer_categories / customer_category_assignments; CustomerCategoryPolicy, SaveCategory, SyncCustomerCategories |
| Permission plus effective client portfolio scope | UI61–63 / UI24 | users, user_client_scopes, Spatie grants; scoped reads and transactional authorization checks |
| No silent merge, preserve history, audited maintenance | UI63 duplicate warning / UI24 history | client/reference uniqueness; identifier HMAC duplicate warning with explicit confirmation; audit_logs |

## Implemented boundaries

Admin routes provide the list, customer create/edit/details, category maintenance and explicit category membership changes for up to 50 selected customers per operation. Changing a customer's status does not remove history. There is no customer deletion, inferred identity merge, automatic debt creation or task creation. Rider customer access arrives in the assigned-task workflow; the default Rider cannot use the Admin customer routes.

Search supports exact customer references, normalized exact identifiers, exact current phone/email contacts, and normalized name tokens/prefixes with a three-character minimum. Names, identifiers, contact values and address lines are encrypted. Search uses HMAC fingerprints/tokens rather than plaintext scans; sensitive search text is retained only in the user's private session, outside the URL. Preserve the separate DCMS_SEARCH_KEY; rotation requires rebuilding the stored search data. No fuzzy matching or guessed country calling codes are applied.

Permissions are customers.view, customers.create, customers.update, customers.update_contacts, customers.categorize and customers.manage_categories, together with an effective explicit client scope. The separate customers.view_sensitive grant permits full identifiers and is excluded from the ordinary Admin bundle. Existing identifiers are not loaded into edit fields; an empty field retains the value, and removal is explicit. Users without contact-maintenance permission receive masked contact values and cannot replace them through a crafted save.

Contacts and addresses preserve their source and validity period when replaced; PostgreSQL guards prevent overwriting or deleting observations. Category membership history preserves actor, assignment and revocation times. A partial unique index allows only one current membership per customer/category. Database guards reject cross-client membership and moving customers/categories between clients. Inactive categories can retain existing memberships but cannot receive new memberships.

Writes lock the client before the customer/category, recheck authorization, validate input and append redacted audit within the transaction. Revision digests detect stale edits without adding an undocumented version column. Bulk membership changes capture revisions for the explicitly selected records; one stale or unauthorized item rolls back the whole operation. Customer, contact, address, membership and audit history remain available through pagination.

The UI24 account/task/visit/communication tabs identify their pending module dependency. Their actual history, customer exports and task-assignment eligibility checks must be implemented with those modules. No fabricated records or export endpoint are supplied.

## Technical findings applied

PostgreSQL local-session timezone and whole-second bindings initially made newly granted scopes appear ineffective, or placed an observation's close time before its creation time. PostgreSQL connections now explicitly use UTC, client scope columns retain microseconds, and security/validity comparisons preserve timestamp precision and offset. These fixes preserve the documented effective-time rules.

Membership/client and observation-history integrity is enforced by PostgreSQL triggers as well as application validation. Trigger functions use CREATE OR REPLACE so an isolated test database can be rebuilt safely; migrations remove them on rollback.

Until client/scope administration is implemented, dcms:provision-local-client explicitly provisions a new commissioning client and scope for an active scopes.manage account in local environments only. Normal seeders do not create customers, clients, accounts or passwords. Browser fixtures remain synthetic and restricted to isolated local/test databases ending _e2e.

## Verification

On 16 October 2026, native PHP 8.3.30 and isolated PostgreSQL 17.11 passed 68 tests / 243 assertions, including 36 customer tests / 129 assertions. Coverage includes scope revocation, foreign-client concealment, Rider denial, sensitive masking, encrypted search/storage, duplicate review, stale writes, validation, reference uniqueness, immutable history, inactive categories, cross-client database guards and transactional rollback. Eight Chromium browser checks passed across desktop/mobile, covering category/customer create, edit, status change, contact history and name search, including 320px and 430px history viewports.

Docker/Sail, Redis-backed runtime and remote CI remain the environment acceptance gates described in README.md. Native acceptance does not establish that those integrations have been exercised.
