# DCMS entity implementation register

Derived tracking index only; Database.md remains authoritative for every field, relationship and constraint. Foundation does not imply module CRUD is complete. Framework reset/job tables and package-owned Pulse tables are additional operational schema.

| Entity / source | Model | Module | Disposition |
|---|---|---|---|
| [users](../Database.md#table-users) | User | M01/M12 | Foundation |
| [staff](../Database.md#table-staff) | Staff | M10 | Foundation |
| [roles](../Database.md#table-roles) | Role (Spatie) | M12 | Foundation |
| [permissions](../Database.md#table-permissions) | Permission (Spatie) | M12 | Foundation |
| [role_has_permissions](../Database.md#table-role_has_permissions) | package pivot | M12 | Foundation |
| [model_has_roles](../Database.md#table-model_has_roles) | package polymorphic pivot | M12 | Foundation |
| [model_has_permissions](../Database.md#table-model_has_permissions) | package polymorphic pivot | M12 | Foundation |
| [reference_values](../Database.md#table-reference_values) | ReferenceValue | M03/M06/M10/M12 | Foundation |
| [clients](../Database.md#table-clients) | Client | M03/M06 | Foundation |
| [assignment_batches](../Database.md#table-assignment_batches) | AssignmentBatch | M03 | Planned |
| [properties](../Database.md#table-properties) | Property | M06 | Planned |
| [debtors](../Database.md#table-debtors) | Debtor | M13/M06 | M13 implemented |
| [contacts](../Database.md#table-contacts) | Contact | M13/M06 | M13 implemented |
| [addresses](../Database.md#table-addresses) | Address | M13/M06 | M13 implemented |
| [debt_accounts](../Database.md#table-debt_accounts) | DebtAccount | M06/M08 | Planned |
| [account_parties](../Database.md#table-account_parties) | AccountParty | M06 | Planned |
| [import_templates](../Database.md#table-import_templates) | ImportTemplate | M04/M11/M12 | Planned |
| [import_batches](../Database.md#table-import_batches) | ImportBatch | M04/M08/M11 | Planned |
| [import_rows](../Database.md#table-import_rows) | ImportRow | M04/M08/M11 | Planned |
| [tasks](../Database.md#table-tasks) | Task | M03/M05/M06/M07 | Planned |
| [task_allocations](../Database.md#table-task_allocations) | TaskAllocation | M05 | Planned |
| [collection_activities](../Database.md#table-collection_activities) | CollectionActivity | M06 | Planned |
| [property_inspections](../Database.md#table-property_inspections) | PropertyInspection | M06 | Planned |
| [task_observations](../Database.md#table-task_observations) | TaskObservation | M06 | Planned |
| [follow_ups](../Database.md#table-follow_ups) | FollowUp | M06 | Planned |
| [task_reviews](../Database.md#table-task_reviews) | TaskReview | M07 | Planned |
| [financial_updates](../Database.md#table-financial_updates) | FinancialUpdate | M08 | Planned |
| [financial_snapshots](../Database.md#table-financial_snapshots) | FinancialSnapshot | M08 | Planned |
| [documents](../Database.md#table-documents) | Document | M04/M06/M09/M01 | Planned |
| [export_jobs](../Database.md#table-export_jobs) | ExportJob | M09 | Planned |
| [bulk_operations](../Database.md#table-bulk_operations) | BulkOperation | M05 | Planned |
| [bulk_operation_items](../Database.md#table-bulk_operation_items) | BulkOperationItem | M05 | Planned |
| [databank_sources](../Database.md#table-databank_sources) | DatabankSource | M11 | Planned; G05 approval required |
| [databank_records](../Database.md#table-databank_records) | DatabankRecord | M11 | Planned; G05 approval required |
| [databank_search_tokens](../Database.md#table-databank_search_tokens) | DatabankSearchToken | M11 | Planned; G05 approval required |
| [notifications](../Database.md#table-notifications) | DatabaseNotification (Laravel) | M12 | Foundation |
| [audit_logs](../Database.md#table-audit_logs) | AuditLog | M01–M12 | Foundation |
| [system_settings](../Database.md#table-system_settings) | SystemSetting | M12 | Planned |
| [outbox_events](../Database.md#table-outbox_events) | OutboxEvent | M04/M05/M09/M12 | Foundation |
| [processed_events](../Database.md#table-processed_events) | ProcessedEvent | M12 | Foundation |
| [user_client_scopes](../Database.md#table-user_client_scopes) | UserClientScope | M01/M12 | Foundation |
| [customer_categories](../Database.md#table-customer_categories) | CustomerCategory | M13 | M13 implemented |
| [customer_category_assignments](../Database.md#table-customer_category_assignments) | CustomerCategoryAssignment | M13 | M13 implemented |
| [import_fields](../Database.md#table-import_fields) | ImportField | M04 | Planned |
| [column_aliases](../Database.md#table-column_aliases) | ColumnAlias | M04 | Planned |
| [import_mapping_profiles](../Database.md#table-import_mapping_profiles) | ImportMappingProfile | M04 | Planned |
| [import_column_mappings](../Database.md#table-import_column_mappings) | ImportColumnMapping | M04 | Planned |
| [task_status_histories](../Database.md#table-task_status_histories) | TaskStatusHistory | M05/M06/M07 | Planned |
| [task_submissions](../Database.md#table-task_submissions) | TaskSubmission | M06/M07 | Planned |
| [task_submission_documents](../Database.md#table-task_submission_documents) | TaskSubmissionDocument | M06/M07 | Planned |
| [attendance_policies](../Database.md#table-attendance_policies) | AttendancePolicy | M10 | Planned |
| [attendance_schedules](../Database.md#table-attendance_schedules) | AttendanceSchedule | M10 | Planned |
| [attendances](../Database.md#table-attendances) | Attendance | M10 | Planned |
| [attendance_adjustments](../Database.md#table-attendance_adjustments) | AttendanceAdjustment | M10 | Planned |
| [integration_accounts](../Database.md#table-integration_accounts) | IntegrationAccount | M14/M12 | Planned |
| [communication_templates](../Database.md#table-communication_templates) | CommunicationTemplate | M14 | Planned |
| [communication_batches](../Database.md#table-communication_batches) | CommunicationBatch | M14 | Planned |
| [conversations](../Database.md#table-conversations) | Conversation | M14 | Planned |
| [communications](../Database.md#table-communications) | Communication | M14/M11/M13 | Planned |
| [communication_attempts](../Database.md#table-communication_attempts) | CommunicationAttempt | M14 | Planned |
| [integration_events](../Database.md#table-integration_events) | IntegrationEvent | M14 | Planned |
| [communication_events](../Database.md#table-communication_events) | CommunicationEvent | M14 | Planned |
| [communication_preferences](../Database.md#table-communication_preferences) | CommunicationPreference | M14/M13 | Planned |
| [communication_quota_reservations](../Database.md#table-communication_quota_reservations) | CommunicationQuotaReservation | M14 | Planned |
| [call_permissions](../Database.md#table-call_permissions) | CallPermission | M14 | Planned |
| [voice_sessions](../Database.md#table-voice_sessions) | VoiceSession | M14 | Planned |
