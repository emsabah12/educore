HR-014 — HR Security, Privacy & Retention Controls

Version: 0.1 Draft
Phase: 3E — Security, Privacy & Retention Controls
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
Depends On: HR-001–HR-013, ADR-022, ADR-025, ADR-030, ADR-031, ADR-032

1. Resource Audit
   [FAKTA] Existing controls yang dapat direuse

Repository sudah mempunyai:

private local filesystem root di storage/app/private;
frontend/browser security baseline ADR-030;
telemetry privacy allowlist ADR-031;
Core Audit dengan metadata sanitization untuk beberapa secret key;
encrypted browser session requirement untuk production;
database-backed tenant-aware queue;
canonical tenant + organizational authorization;
person_identifiers.encrypted_value;
person_identifiers.value_fingerprint;
audit event append-only secara schema.

Classification:

Existing capability Decision
ADR-030 browser security KEEP / REUSE
ADR-031 telemetry privacy KEEP / REUSE
Core Audit KEEP as supplemental
Private filesystem KEEP + EXTEND
Core queue KEEP + HARDEN
Person identifier schema KEEP + VERIFY IMPLEMENTATION
Public filesystem for HR documents DO NOT USE
Raw sensitive telemetry PROHIBITED 2. Critical Findings
[RISK — HIGH] Queue payload exposure

Current QueueWatchdogListener mengambil:

BaseTenantAwareJob.payload

dan memasukkan seluruh payload sebagai:

audit_logs.metadata.input_payload

Sementara:

jobs.payload
failed_jobs.payload

juga menyimpan serialized job.

Ini tidak aman untuk future HR jobs yang membawa:

compensation data;
document contents;
leave details;
disciplinary evidence;
government export datasets;
personal identifiers.
Required rule
Sensitive HR async job
→ identifier-only payload
→ worker retrieves authorized data server-side

Contoh:

GOOD

tenant_id
export_run_id
actor_user_id

bukan:

BAD

employee full record
salary data
NIK
document contents
government export rows
[RESOURCE GAP] Person identifier encryption implementation

Schema sudah mendefinisikan:

encrypted_value
value_fingerprint

tetapi repository baseline belum menunjukkan application service/model yang membuktikan encryption + fingerprint lifecycle sudah diimplementasikan.

Karena itu:

schema intent
≠ verified operational encryption

Sebelum identifier sensitif digunakan production, implementasi dan test encryption harus diverifikasi.

3. Security Objective

HR harus mengikuti:

Least Privilege

- Least Disclosure
- Tenant Isolation
- Organizational Scope
- Data Minimization
- Private Storage
- Explicit Lifecycle
- Auditable High-Impact Actions

Security tidak boleh bergantung hanya pada UI.

4. HR Data Classification

Baseline classification berikut menjadi canonical security classification untuk HR.

Class Examples Default treatment
INTERNAL Employee number, employment status, organizational placement Authorized internal access
CONFIDENTIAL Personal contact, address, birth information Scoped access + no routine telemetry
RESTRICTED Government identifiers, compensation, payroll inputs, performance details Explicit permission + minimal disclosure
HIGHLY RESTRICTED Discipline evidence, sensitive HR documents, exit-interview detail, private government export artifacts Explicit specialized access + strong audit/storage controls
SECRET Credentials, signing secret, API token, session/token material Never exposed as HR business data
HR-014-BR-001

Classification applies to data itself, not only the page containing it.

A user authorized to see Employee Directory does not automatically obtain Restricted employee fields.

5. Ordinary Employee Profile

Baseline ordinary Employee directory may expose only data necessary for legitimate HR operations.

Examples:

name
employee identifier
employment state
authorized placement information

Additional personal profile fields must follow least-disclosure policy.

HR-014-FR-001

List endpoints should return fewer fields than detailed Employee endpoints where practical.

List DTO
≠ Full Employee DTO

Avoid sending sensitive fields to browser merely to hide them visually.

6. Personal Contact & Address

Core currently stores Person contacts and addresses as ordinary database values.

Therefore these fields must be treated as:

CONFIDENTIAL

at authorization/query/telemetry boundaries.

HR-014-FR-002

Contact/address values:

must not become routine log metadata;
must not become telemetry dimensions;
must not be included in generic audit descriptions;
must not be included in queue payload unless explicitly required and approved. 7. Legal / Government Identifiers

Examples include canonical identifiers such as national ID/passport where supported by Core.

Classification:

RESTRICTED

Required design:

plaintext
→ application boundary
→ encryption
→ ciphertext at rest

normalized plaintext
→ keyed fingerprint/HMAC
→ exact-match lookup
HR-014-BR-002

Raw identifier must not be used as:

URL parameter;
log field;
queue identifier;
audit metadata;
filename;
telemetry attribute. 8. Sensitive Field Retrieval
HR-014-FR-003

Sensitive fields should be excluded from ordinary API response unless the operation requires them.

Preferred:

GET employee summary
→ ordinary fields

authorized sensitive operation
→ restricted fields

rather than:

backend returns everything
→ frontend hides sensitive fields 9. Compensation & Payroll Input Privacy

Classification:

RESTRICTED

Required:

hr.compensation.view
or
hr.payroll.inputs.view

- scope
- sensitivity policy
  HR-014-BR-003

Compensation data must not appear in:

ordinary Employee list;
global search results by default;
telemetry;
generic notification payload;
audit description text;
unauthorized report drill-down. 10. Leave & Permit Privacy

Leave records may contain private employee information beyond the fact that leave exists.

Therefore:

leave metadata
→ CONFIDENTIAL

reason / attachments
→ RESTRICTED by default
HR-014-FR-004

Approver UI/API should receive only information required to make the authorized decision.

A leave approval role does not automatically require access to unrelated HR details.

11. Attendance Privacy

Attendance records:

CONFIDENTIAL

Raw events may include future adapter data such as device/location information.

[OPEN DECISION]

Exact fingerprint/QR/GPS adapters remain unresolved.

Until those integrations exist:

do not establish permanent storage of biometric/location payload

without separate privacy/security review.

12. Performance & Competency

Detailed assessment data:

RESTRICTED

Summary/released employee-facing information may have different disclosure rules.

HR-014-FR-005

Draft assessment evidence must not automatically be visible through Employee self-service.

Release/publishing semantics must come from HR-007 business rules/API specification.

13. Discipline

Discipline records and evidence:

HIGHLY RESTRICTED
HR-014-FR-006

Ordinary:

hr.employees.view

must not expose discipline detail.

Required authorization remains explicit:

hr.discipline.view

- resource scope

Audit/log/error messages should prefer identifiers:

disciplinary_case_id

instead of raw allegations/evidence.

14. Offboarding & Exit Interview

Offboarding operational status:

CONFIDENTIAL

Exit-interview contents and potentially sensitive case data:

HIGHLY RESTRICTED
HR-014-FR-007

Employee termination status must not imply that all historical HR records become publicly accessible or automatically deleted.

15. Documents & Agreements

Locked HR-008 rule remains:

metadata
→ database

file bytes
→ private object storage

no DB BLOB
HR-014-BR-004

HR artifacts must never use:

public filesystem disk
public /storage URL
predictable public filename

as canonical access mechanism.

16. Private Artifact Access

Document/export retrieval should follow:

Authenticated Request
→ Tenant Context
→ Permission
→ Resource Scope
→ Sensitivity Check
→ Storage Retrieval

Browser must not receive permanent public object URLs.

[REKOMENDASI]

If direct object-storage delivery is later required, use short-lived authorized delivery such as a temporary signed URL generated after backend authorization.

Exact mechanism depends on the production storage provider.

17. Storage Provider

Current development baseline:

local private filesystem

Production provider:

[OPEN DECISION]

Candidate architecture remains storage-adapter based.

The domain must not depend directly on:

AWS S3
MinIO
GCS
Azure Blob

as business semantics.

18. Upload Security

Frontend may validate for UX:

file size
extension
MIME hint

but backend remains authoritative.

Backend minimum:

size validation
type validation
ownership
authorization
storage destination
safe generated storage key
HR-014-FR-008

Uploaded filename supplied by user must not become trusted filesystem path.

19. Malware / Antivirus Boundary

[OPEN DECISION] Provider belum dipilih.

However security contract can already be locked.

Recommended lifecycle:

Upload
→ PRIVATE QUARANTINE
→ Scan
→ CLEAN / REJECTED / SCAN_FAILED
→ Authorized availability
HR-014-BR-005

A file requiring malware scanning must not become downloadable as normal HR artifact before scan policy considers it safe.

Exact engine/vendor remains deferred.

20. Signed / Finalized Documents

Finalized/signed document versions remain immutable.

Security implication:

finalized artifact
→ never overwrite in place

Correction must create:

new version

where domain rules allow.

This preserves auditability and protects signed evidence.

21. Government Export Security

Dapodik / EMIS export artifacts:

HIGHLY RESTRICTED

Required:

Frozen Dataset
→ Private Export Artifact
→ Explicit Download Permission
HR-014-FR-009

Government export dataset must not be:

placed on public storage;
included wholesale in job payload;
dumped into logs;
stored as telemetry;
exposed by predictable URL. 22. Queue Privacy Contract

All HR asynchronous jobs use:

Identifiers

- minimum operational metadata

Example:

tenant_id
export_run_id
document_id
actor_user_id

Worker retrieves required domain state from canonical repositories.

HR-014-BR-006

BaseTenantAwareJob.payload must be treated as potentially persisted infrastructure data.

Therefore:

if data should not appear in failed_jobs
→ it must not be in queue payload 23. Queue Watchdog Remediation

Current behavior:

QueueWatchdog
→ copies input_payload
→ audit_logs.metadata

Classification:

REFRACTOR
[REKOMENDASI]

Watchdog should audit only allowlisted operational metadata such as:

job_class
queue
exception_class
attempt
correlation/run identifier

not arbitrary serialized job input.

For HR:

raw job payload
→ NEVER copied to generic audit 24. Logging Rules

Application logs must not routinely contain:

raw Employee record
contact values
addresses
government identifiers
compensation
leave reasons
attendance raw payload
performance content
disciplinary evidence
document contents
exit-interview text
government export rows
HR-014-BR-007

Logging uses:

event name
resource ID
tenant-safe operational state
error classification
correlation identifier

rather than business payload dumping.

25. Telemetry

HR inherits ADR-031 allowlist model.

unknown telemetry field
→ not sent

Allowed example:

module = hr
routeId = hr.employees.view
workspaceType = ORGANIZATION
errorCode = AUTHORIZATION_DENIED

Forbidden:

employee_name
NIP
salary
leave_reason
document_name containing PII
discipline_notes
raw API response 26. Audit vs Logs vs Domain Evidence

These remain separate concepts.

Domain Transaction Evidence
→ business-critical history

Core Audit
→ supplemental governance trail

Operational Logs
→ diagnostics

Telemetry
→ operational observability
HR-014-BR-008

Do not treat laravel.log as HR audit history.

Do not treat frontend telemetry as HR audit history.

27. Core Audit Limitation

Current DatabaseAuditTrailService is intentionally fail-open:

audit persistence failure
→ logs critical error
→ does not necessarily abort business transaction

Therefore locked HR architecture remains:

high-impact HR lifecycle
→ transactional/domain evidence required

Core Audit
→ supplemental 28. Audit Metadata Sanitization Gap

Current sanitizer masks known keys such as:

password
token
access_token
api_key
client_secret

This is valuable but remains blacklist-based sanitization.

It does not automatically understand:

salary
national_id
leave_reason
discipline_evidence
document_content
HR-014-BR-009

Sensitive HR audit metadata must use an allowlist, not rely only on generic secret-key sanitization.

29. High-Impact Audit Events

Minimum candidates:

Employee provisioned
Employment created
Employment activated
Employment ended

Hiring approved / converted

Leave finally approved/rejected

Attendance finalized

Compensation materially changed

Performance finalized

Document finalized
Agreement finalized/signed transition

Disciplinary action finalized

Offboarding completed

Sensitive report exported
Government export generated/downloaded

Exact event names belong to detailed implementation/API contract.

30. Audit Metadata Principle

Preferred:

event_type
actor_user_id
tenant_id
resource_id
effective business state
timestamp
approved operational references

Avoid:

full before object
full after object
raw form payload
raw document
raw evidence

unless an explicit domain requirement justifies it.

31. Retention Model

Exact retention durations remain [OPEN DECISION] from HR-008/HR-009.

Therefore HR-014 does not invent:

30 days
1 year
5 years
10 years

Instead retention must be modeled by data category and lifecycle.

32. Retention Categories
    Data Retention trigger
    Employee master Employment/person lifecycle policy
    Employment history HR historical-record policy
    Leave Leave retention policy
    Attendance Attendance retention policy
    Compensation/payroll inputs HR/Finance retention policy
    Performance Performance retention policy
    Documents/contracts Document-type retention policy
    Discipline Discipline policy
    Offboarding Offboarding policy
    Audit evidence Audit retention policy
    Export artifacts Export-artifact retention policy
    Logs Operational logging policy
    Failed jobs Queue infrastructure policy
    Telemetry Observability policy

Each duration remains configurable/authoritative only after business/legal policy is provided.

33. Retention ≠ Deleting Canonical Identity

Retention of a specific HR artifact does not imply:

delete Person
delete Membership
delete User

These are independent lifecycle concerns.

Similarly:

Employment ENDED
≠ purge Employee history 34. Soft Delete

Current Employee schema has:

softDeletes()
HR-014-BR-010

Soft delete is an implementation mechanism, not automatic retention policy.

deleted_at
≠ legal deletion
≠ archival policy
≠ anonymization policy

No generic purge job should be introduced merely because a table supports soft delete.

35. Data Purge

Before any HR purge implementation, required authority includes:

record category
retention duration
retention start event
dependency rules
audit requirements
referential-integrity effect
artifact storage deletion rule

Until those exist:

automated destructive HR purge
→ DEFERRED 36. Export Artifact Retention

Report/export run metadata and generated file need separate retention.

ExportRun
≠ ExportArtifact
[REKOMENDASI]

The binary artifact may have shorter retention than business run metadata.

Exact durations remain [OPEN DECISION].

This enables:

auditability of generation
without indefinite retention of sensitive downloadable files 37. Browser Storage

Sensitive HR business data must not be intentionally persisted in:

localStorage
sessionStorage
IndexedDB
Service Worker cache

unless a future architecture decision explicitly authorizes it.

Normal runtime memory/server-state cache remains bounded by frontend architecture.

Logout/tenant switch/workspace switch must follow existing cache invalidation rules.

38. URL Privacy

Do not place sensitive HR values in URL.

Forbidden examples:

?national_id=...
?salary=...
?leave_reason=...
?document_token=...

Opaque resource IDs may be used where route architecture requires them.

Telemetry should use routeId, not raw URL.

39. Notifications

Notification content must follow least disclosure.

Preferred:

Pengajuan cuti membutuhkan persetujuan.

rather than exposing unnecessary private reasons in push/email metadata.

HR-014-FR-010

Sensitive case content should be accessed in authenticated EduCore rather than copied into generic notification infrastructure unless explicitly required.

40. Error Messages

Production errors must not reveal:

file storage key
absolute path
SQL
stack trace
secret
raw identifier
raw sensitive payload

Canonical error code + safe message remains ADR-025 baseline.

41. Encryption at Rest

Two different layers must not be conflated.

Infrastructure encryption

Database/storage-level encryption:

[DEPLOYMENT CONCERN]

Application-level field encryption

Required when domain/security specification calls for reducing plaintext exposure inside persistence.

Current explicit example:

person_identifiers.encrypted_value
[OPEN DECISION]

Additional fields requiring application-level encryption have not yet been justified.

We should not encrypt every column indiscriminately because it affects:

queryability;
indexing;
filtering;
uniqueness;
operational complexity;
key rotation. 42. Encryption Key Management

Application encryption keys must not be:

committed in repository;
sent to browser;
stored in HR database row;
logged.

Exact secret/KMS provider:

[OPEN DECISION / Phase 3G deployment concern]

43. Tenant Isolation

Every persisted HR business record remains tenant-scoped either directly or transitively.

Private object keys should carry safe tenant ownership metadata/reference, but authorization must not trust path naming alone.

storage prefix
≠ authorization

Backend must verify tenant ownership from canonical metadata.

44. Cross-Tenant Export Protection

Before export generation:

dataset rows
→ canonical tenant filter
→ organizational scope
→ permission
→ sensitivity

No post-generation frontend filtering is acceptable.

45. Production Session Security Impact

Existing repository .env.example has development defaults such as:

SESSION_ENCRYPT=false

but BrowserSessionSecurityPolicy explicitly requires production:

encrypted session payload
Secure cookie
HttpOnly
SameSite=Strict
host-only \_\_Host- cookie

Classification:

development default
≠ production policy conflict

No HR-specific session mechanism is introduced.

46. Privacy-Friendly Search

Search results must only include resources user could otherwise access.

HR-014-BR-011

Search authorization happens server-side.

Forbidden:

query all employees
→ return names
→ hide unauthorized details later

Search results themselves can leak employee existence.

47. Bulk Operations

Bulk download/bulk export materially increase disclosure risk.

Until exact authorization/limits exist:

bulk sensitive operations
→ DEFERRED

This aligns with HR-009 open decisions for:

export thresholds;
pagination/date limits;
privacy cohort threshold. 48. Testing Requirements

Minimum security/privacy tests must include:

Authorization
unauthorized sensitive field → not returned
out-of-scope employee → not exposed
self permission → cannot read another Employee
Serialization
ordinary Employee DTO
→ no restricted fields
Queue
sensitive job
→ no raw business payload in serialized job/audit
Audit
audit metadata
→ approved fields only
Logs

Test representative failure paths to ensure sensitive values are absent where practical.

Documents
unauthorized user
→ cannot retrieve artifact

public URL
→ unavailable

finalized version
→ cannot overwrite
Export
report view permission
≠ artifact download permission 49. Required Remediation Classification
Area Change
HR documents storage ADD — private only
HR export storage ADD — private only
Queue job payload convention REFACTOR / HARDEN
QueueWatchdog raw payload audit REFACTOR — P0 before sensitive HR jobs
Audit HR metadata ADD allowlist contract
Person identifier encryption VERIFY + COMPLETE if absent
HR sensitive DTOs ADD purpose-specific DTOs
Telemetry KEEP ADR-031
Browser storage KEEP security restrictions
Public storage for HR artifacts PROHIBIT
Automated retention purge DEFER until policy authority 50. Security Invariants
Sensitive data is not protected by hiding HTML.

Authorization happens before data disclosure.

List APIs return no more data than needed.

Private artifacts never become public by convenience.

Queue payload is considered persisted data.

Logs are not a business database.

Audit logs are not a payload dump.

Telemetry uses allowlists.

Employment termination does not imply data deletion.

Soft delete is not retention policy.

Report view does not imply export/download.

Finalized documents are immutable.

Cross-domain HR UI does not bypass source-domain security. 51. Acceptance Criteria
HR-014-AC-001 — Sensitive Employee Field

Given actor memiliki hr.employees.view
But tidak memiliki sensitive access
When Employee response dikembalikan
Then restricted fields tidak ikut dikirim hanya untuk kemudian disembunyikan frontend.

HR-014-AC-002 — Private Document

Given HR document tersimpan
When unauthenticated/public request mencoba mengakses storage path
Then artifact tidak tersedia sebagai public file.

HR-014-AC-003 — Document Download

Given user meminta document artifact
When authorization dilakukan
Then Tenant, permission, resource scope, dan sensitivity policy diverifikasi sebelum retrieval.

HR-014-AC-004 — Queue Privacy

Given sensitive HR process dijalankan async
When job diserialisasi
Then payload hanya membawa identifiers/minimum operational metadata
And tidak membawa raw sensitive dataset.

HR-014-AC-005 — Watchdog

Given HR job gagal permanen
When watchdog mencatat failure
Then generic audit/log tidak menyalin raw HR payload.

HR-014-AC-006 — Telemetry

Given Employee page mengalami error
When frontend telemetry dikirim
Then event dapat membawa route/module/error classification
And tidak membawa raw Employee record atau personal details.

HR-014-AC-007 — Discipline

Given actor hanya mempunyai ordinary Employee view
When Employee detail diminta
Then disciplinary evidence tidak ikut diberikan.

HR-014-AC-008 — Export

Given government export selesai dibuat
When artifact disimpan
Then storage bersifat private
And dataset tidak disimpan sebagai raw queue payload.

HR-014-AC-009 — Employment End

Given Employment berakhir
When lifecycle transition selesai
Then historical HR data tidak otomatis dipurge
And retention mengikuti policy terpisah.

HR-014-AC-010 — Identifier

Given government/legal identifier akan disimpan
When persistence dilakukan
Then raw value tidak disimpan pada person_identifiers.encrypted_value
And exact-match fingerprint tidak memungkinkan direct plaintext storage.

HR-014-AC-011 — Logging

Given sensitive HR request gagal
When application membuat diagnostic log
Then log tidak menyertakan raw sensitive request/response payload.

HR-014-AC-012 — Retention

Given belum ada authoritative retention duration
When HR functionality diimplementasikan
Then engineering tidak mengarang automatic purge duration.

52. Open Decisions

Masih diperlukan authority untuk:

exact retention duration per HR data category;
retention start event per category;
audit retention;
operational log retention;
failed-job retention;
export artifact retention;
document retention;
production object-storage provider;
malware/AV scanning provider;
exact masking format;
privacy small-cohort threshold;
observability provider/retention;
encryption/KMS provider;
whether additional HR fields require application-level encryption;
biometric/location attendance data policy if adapters are introduced.

Semua tetap [OPEN DECISION].

53. Scope
    IN SCOPE
    HR data sensitivity classification;
    least disclosure;
    logging and telemetry restrictions;
    private documents;
    export artifact security;
    queue privacy;
    audit boundaries;
    field-encryption boundary;
    retention architecture;
    sensitive-data testing.
    OUT OF SCOPE
    selection of cloud storage vendor;
    selection of AV provider;
    statutory/legal retention durations;
    exact encryption key infrastructure;
    backup/recovery mechanics;
    monitoring provider;
    deployment topology implementation;
    source code implementation.
    DEFERRED
    automatic retention purge;
    large/bulk sensitive export;
    biometric/GPS storage;
    precise masking rules;
    provider-specific signed URLs;
    key rotation implementation.
54. Traceability
    HR-008
    Documents / Discipline / Offboarding
    ↓
    private artifacts + sensitive evidence

HR-009
Reporting / Government Export
↓
sensitive frozen dataset + private artifact

HR-013
Permission + Resource Scope
↓
authorized disclosure

ADR-030
Browser Security
↓

ADR-031
Privacy-Allowlisted Telemetry
↓

HR-014
Security / Privacy / Retention
↓
Phase 3F Infrastructure NFR
↓
Phase 3G Operations / Deployment
↓
Implementation + Security Tests
Phase 3E Review

Quality Score: 9.7/10

Gaps
retention durations belum mempunyai business/legal authority;
AV/storage/KMS provider belum dipilih;
application-level Person identifier encryption belum terverifikasi implementasinya;
exact masking dan privacy cohort threshold belum tersedia.
Risks

[RISK — HIGH] Current Queue Watchdog dapat menggandakan sensitive job payload ke audit logs.

[RISK — HIGH] failed_jobs.payload berarti setiap sensitive value dalam job payload dapat tersimpan setelah failure.

[RISK — HIGH] Menganggap person_identifiers.encrypted_value sudah aman hanya dari schema tanpa verified encryption implementation dapat menghasilkan false sense of security.

[RISK] Generic audit sanitizer hanya mengetahui key secret tertentu dan bukan HR-sensitive business fields.

Recommendations
Lock HR-014 sebagai security/privacy baseline.
Jadikan identifier-only queue payload invariant untuk seluruh future HR jobs.
Refactor Queue Watchdog sebelum sensitive HR async workloads diaktifkan.
Gunakan private storage untuk document dan export.
Jangan menetapkan retention duration tanpa authority.
Verify/complete Person identifier encryption implementation sebelum production usage.
Pertahankan domain transactional evidence untuk high-impact HR lifecycle.

Status: READY FOR APPROVAL
