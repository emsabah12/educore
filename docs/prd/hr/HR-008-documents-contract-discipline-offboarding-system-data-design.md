# HR-008 — HR Documents, Contract Administration, Discipline & Offboarding System/Data Design

**Version:** 1.0  
**Status:** Approved — Locked  
**Phase:** 2G — System Architecture & Data Design  
**Primary Module:** `Modules/HR`  
**Baseline Repository:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`  
**Depends On:** HR-001 (Approved), ADR-032 (Accepted), HR-002 (Approved), HR-003 (Approved), HR-004 (Approved), HR-005 (Approved), HR-006 (Approved), HR-007 (Approved)

---

# 1. Executive Summary

HR-008 mendesain fondasi untuk empat area lifecycle HR yang saling berhubungan:

1. **HR Document & Digital Archive**
2. **Employment Contract / SK Administration**
3. **Discipline & Corrective Action**
4. **Offboarding / Employment Exit**

Desain mengikuti prinsip EduCore yang sudah dikunci:

```text
Person / Membership / User
        ↓ owned by Core
Employee / Employment
        ↓ owned by HR
Document / Agreement / Discipline / Offboarding
        ↓ owned by HR
Finance settlement
        ↓ future Finance
Asset registry
        ↓ future Asset/Operations capability
Authorization / organizational participation
        ↓ Core
```

Fase ini tidak membuat sistem document management platform-wide dari nol. Repository saat ini belum memiliki canonical shared Document bounded context, sehingga Phase 2G membuat **HR-owned document registry + storage abstraction** yang hanya memenuhi kebutuhan HR. Jika nanti Academic, Finance, PPDB, atau domain lain menunjukkan kebutuhan yang sama secara konsisten, extraction menjadi shared platform capability dapat diputuskan melalui ADR terpisah.

Prinsip penting lain:

- binary file tidak disimpan sebagai database BLOB;
- document version yang sudah finalized/signed immutable;
- signed legal artifact tidak boleh diganti diam-diam;
- `Employment Agreement` berbeda dari binary document;
- contract expiry menghasilkan reminder/work queue, bukan automatic renewal;
- `SP1/SP2/SP3` adalah tenant-configurable disciplinary action catalog, bukan hardcoded state machine;
- disciplinary action tidak otomatis melakukan termination;
- termination/offboarding tidak menghapus Person, Employee, atau User;
- HR tidak otomatis menonaktifkan Membership karena Membership dapat dipakai persona/domain lain;
- access revocation harus explicit dan auditable;
- HR hanya menyediakan **final-settlement facts**, bukan menghitung pesangon/net final pay;
- Core Audit menjadi supporting audit trail, bukan satu-satunya legal evidence karena implementasinya bersifat best-effort/fail-open.

---

# 2. Project Resource Audit

## 2.1 Resources reviewed

Resource yang diperiksa ulang:

- `Modules/HR`
- `Modules/Core`
- `Modules/Auth`
- `Modules/User`
- `Modules/Academic`
- Core Person/Membership/User
- Core OrganizationalAssignment
- Core membership/scoped role persistence
- Core Audit infrastructure
- Core Notification infrastructure
- `config/filesystems.php`
- repository module manifests
- HR-001 s.d. HR-007
- ADR-032
- current OpenAPI/folder architecture documentation
- current Indonesian employment/electronic-document regulatory baseline for architectural context

## 2.2 Existing repository facts

**[FAKTA]** `Modules/HR` existing belum memiliki:

- document registry;
- employee file archive;
- employment agreement/contract model;
- SK lifecycle;
- disciplinary case/action;
- offboarding case;
- exit interview;
- handover workflow;
- final-settlement handoff.

**[FAKTA]** filesystem repository menyediakan:

```text
local → storage/app/private
public
s3
```

Private `local` dan private S3-compatible storage dapat menjadi infrastructure adapter. HR document tidak boleh menggunakan public disk sebagai canonical storage.

**[FAKTA]** Core mempunyai append-only `audit_logs`, tetapi `DatabaseAuditTrailService` menangkap persistence failure dan hanya melaporkan error. Dengan kata lain audit Core adalah **best-effort operational audit**, bukan transactional legal evidence store.

**Implikasi:** domain HR yang sensitif harus mempunyai actor/time/reason dan immutable evidence sendiri. Core audit tetap digunakan sebagai second layer.

**[FAKTA]** Core sudah mempunyai notification delivery infrastructure:

- asynchronous notification job;
- notification channel interface;
- WhatsApp channel;
- notification attempt persistence;
- queue retry/idempotency.

**[CONFLICT]** HR-007 dan beberapa dokumen sebelumnya menyebut Notification sebagai belum tersedia. Yang lebih tepat:

```text
Core notification delivery infrastructure     = AVAILABLE
HR reminder orchestration / scheduler contract = NOT AVAILABLE
```

Phase 2G mengikuti fakta repository terbaru ini.

**[FAKTA]** Core `OrganizationalAssignmentService` sudah dapat deactivate assignment.

**[FAKTA]** organizational scoped role repository sudah dapat revoke role.

**[FAKTA]** `membership_roles` dan `organizational_assignment_roles` belum menyimpan provenance/source grant.

**Implikasi:** offboarding tidak dapat menebak aman role mana yang “milik employment” dan role mana yang diberikan karena persona/tanggung jawab lain.

**[FAKTA]** `User` adalah account global milik satu Person, bukan account employment.

**[FAKTA]** `Membership` adalah Person × Tenant, bukan Employee account.

Karena satu Membership dapat menjadi dasar partisipasi domain lain, terminasi Employment tidak boleh otomatis melakukan:

```text
Membership → INACTIVE
User → INACTIVE
```

tanpa cross-domain participation review.

## 2.3 Current regulatory context — architecture only

Per 22 August 2026, sumber resmi yang diverifikasi menunjukkan:

- PP 35/2021 masih tercatat berlaku dan mengatur antara lain PKWT, kompensasi PKWT, tata cara PHK, pesangon/penghargaan masa kerja/penggantian hak;
- UU 6/2023 menetapkan Perpu Cipta Kerja menjadi Undang-Undang;
- PP 71/2019 masih tercatat berlaku untuk Penyelenggaraan Sistem dan Transaksi Elektronik dan mengatur persyaratan Tanda Tangan Elektronik, termasuk certified dan non-certified electronic signature;
- UU 1/2024 merupakan perubahan kedua UU ITE dan masih berlaku.

**Design implication:** EduCore perlu menyimpan contract/offboarding evidence dan signature-provider metadata secara auditable, tetapi **tidak meng-hardcode formula kompensasi/PHK atau menganggap checkbox internal sebagai legally certified e-signature**.

## 2.4 Resource gaps

**[RESOURCE GAP]**

Belum tersedia:

- policy resmi yayasan untuk numbering SK/contract;
- default document retention period;
- exact contract taxonomy yayasan;
- exact disciplinary policy/SP sequence;
- whether appeal/review process is mandatory;
- external e-sign provider;
- malware/antivirus scanning service;
- shared Asset module;
- Finance payroll/final-settlement module;
- cross-domain participation registry yang dapat menentukan kapan Membership aman dinonaktifkan;
- RBAC grant provenance;
- exact PKWT/PKWTT mapping per tipe lembaga/pegawai;
- legal review of institution-specific termination/compensation rules.

Tidak ada gap tersebut yang memblokir domain foundation, tetapi statutory formula dan automated account deactivation tidak boleh dikunci pada fase ini.

---

# 3. Scope

## 3.1 IN SCOPE

### HR Documents

- tenant-scoped document type catalog;
- logical HR document;
- immutable document versions;
- employee/candidate/domain document association;
- private file storage metadata;
- file checksum;
- document numbering metadata;
- issue/effective/expiry dates;
- document status lifecycle;
- finalized/signed artifact protection;
- signature envelope/signatory evidence;
- document expiry query/reminder candidate;
- permission-controlled download.

### Contract / SK Administration

- employment agreement type catalog;
- employment agreement;
- PKWT/PKWTT/internal agreement/appointment decree representation through configurable catalog;
- agreement effective dates;
- renewal/extension/supersession relationship;
- signed-document evidence;
- expiration reminder;
- contract history;
- agreement termination/closure;
- contract-to-Employment traceability.

### Discipline

- disciplinary case;
- employee response/acknowledgement;
- findings;
- tenant-configurable action types such as SP1/SP2/SP3;
- corrective/coaching follow-up;
- disciplinary document;
- action validity/effective period;
- case history;
- optional recommendation to start offboarding.

### Offboarding

- resignation/termination/contract-end/retirement/death/other reason catalog;
- offboarding request/case;
- approval;
- proposed/effective employment end date;
- checklist template;
- handover/asset-return/access/final-settlement tasks;
- access review;
- exit interview;
- Employment end orchestration;
- final settlement facts/handoff;
- case completion;
- complete historical record.

## 3.2 OUT OF SCOPE

- legal advice engine;
- statutory compensation formula engine;
- payroll final calculation;
- accounting;
- bank/payment;
- asset inventory source of truth;
- hardware/device access revocation;
- global shared enterprise DMS;
- certificate authority / PSrE implementation;
- private signing key custody;
- document OCR;
- automatic Person/User deletion;
- automatic Membership deactivation;
- generic BPM/workflow engine;
- generic rules DSL.

## 3.3 FUTURE SCOPE

- PSrE/e-sign provider adapter;
- malware scanning adapter;
- shared Document Platform extraction;
- shared reminder scheduler/orchestrator;
- Finance final-settlement contract;
- Asset module integration;
- IAM grant provenance;
- automated safe Membership deactivation after cross-domain participation review;
- digital personnel dossier export.

---

# 4. Domain Boundary

```text
Core
├── Person
├── Membership
├── User
├── Organization / OrganizationalAssignment
├── RBAC / scoped role grants
├── Audit
├── Queue
└── Notification delivery infrastructure

HR
├── Employee
├── Employment
├── HR Document
├── Employment Agreement / SK
├── Discipline
├── Offboarding
├── Exit Interview
├── Access Review Work Item
└── Final Settlement Facts

Finance (future)
├── Final payroll calculation
├── PKWT/PHK monetary calculation
├── deduction
├── payable
├── payment
└── accounting

Asset/Operations (future)
└── Asset registry / custody source of truth
```

HR boleh mencatat bahwa sebuah asset harus dikembalikan, tetapi tidak boleh menjadi owner inventory asset.

---

# 5. Decisions Proposed for Approval

| ID | Decision |
|---|---|
| **OD-HR-LIFE-001** | HR Documents memakai logical document + immutable version model; binary berada di private object/file storage, bukan DB BLOB. |
| **OD-HR-LIFE-002** | Finalized/signed document version immutable; koreksi membuat version baru atau superseding document. |
| **OD-HR-LIFE-003** | Core Audit adalah supporting evidence; legal-sensitive lifecycle mempunyai domain-level actor/time/reason/evidence sendiri. |
| **OD-HR-LIFE-004** | Employment Agreement adalah domain record terpisah dari binary document; satu Employment dapat mempunyai banyak Agreement sepanjang waktu. |
| **OD-HR-LIFE-005** | Agreement renewal/extension tidak otomatis membuat Employee/Employment baru; new Employment hanya untuk employment episode baru setelah relationship berakhir. |
| **OD-HR-LIFE-006** | Agreement expiry tidak melakukan auto-renew atau auto-end Employment; sistem membuat reminder/work item dan membutuhkan explicit business command. |
| **OD-HR-LIFE-007** | E-signature menggunakan adapter/provider boundary; EduCore tidak menyimpan private signing keys dan tidak menganggap internal checkbox sebagai certified e-signature. |
| **OD-HR-LIFE-008** | SP1/SP2/SP3 dan sanksi lain adalah tenant-scoped action catalog, bukan hardcoded state machine. |
| **OD-HR-LIFE-009** | Disciplinary case/action tidak otomatis mengubah Position, Compensation, Role, atau Employment status. |
| **OD-HR-LIFE-010** | Disciplinary termination recommendation harus membuat/merujuk explicit Offboarding Case; termination tetap workflow terpisah. |
| **OD-HR-LIFE-011** | Offboarding Case terkait satu Employment episode; normal completion tidak soft-delete Employee. |
| **OD-HR-LIFE-012** | Employment ending dan Offboarding completion adalah dua state berbeda; Employment dapat berakhir pada effective date sementara settlement/handover tasks masih berlangsung. |
| **OD-HR-LIFE-013** | Offboarding menutup HR Position/Placement melalui HR-002 `EndEmployment`; Person/User tidak dihapus. |
| **OD-HR-LIFE-014** | Membership/User tidak otomatis dinonaktifkan saat offboarding. |
| **OD-HR-LIFE-015** | Existing RBAC grants disnapshot sebagai access-review items; hanya grant yang secara eksplisit diputuskan `REVOKE` oleh actor berwenang yang dicabut. |
| **OD-HR-LIFE-016** | Offboarding menyimpan final-settlement facts/status/reference, bukan nilai pesangon/net final pay; monetary calculation tetap Finance. |
| **OD-HR-LIFE-017** | Asset handover dicatat sebagai offboarding task/reference; asset registry tidak dibuat di HR. |
| **OD-HR-LIFE-018** | HR memakai Core notification delivery infrastructure untuk future reminders melalui narrow dispatch contract; HR tetap owner reminder eligibility/business timing. |

---

# 6. HR Document Model

## 6.1 Logical model

```text
HRDocument
   ↓ 1..n
HRDocumentVersion
   ↓ 0..n
SignatureEnvelope
   ↓ 1..n
SignatureSigner
```

Context:

```text
Candidate ── CandidateDocumentLink ── HRDocument
Employee  ── EmployeeDocumentLink  ── HRDocument
Agreement ─────────────────────────── HRDocument
Discipline Case ─ CaseDocumentLink ─ HRDocument
Offboarding Case ─ CaseDocumentLink ─ HRDocument
```

Explicit link tables dipilih untuk mempertahankan FK/tenant integrity. Generic polymorphic `subject_type + subject_id` tidak dijadikan baseline.

## 6.2 `hr_document_types`

| Column | Type | Null | Rule |
|---|---|---:|---|
| `id` | UUID | No | UUIDv7 |
| `tenant_id` | UUID | No | owner |
| `code` | varchar(60) | No | stable tenant code |
| `name` | varchar(160) | No | display |
| `category` | varchar(30) | No | controlled semantic category |
| `requires_expiry` | boolean | No | default false |
| `default_confidentiality` | varchar(20) | No | `STANDARD`, `SENSITIVE`, `RESTRICTED` |
| `is_active` | boolean | No | catalog lifecycle |
| timestamps | | | |

Suggested semantic categories:

```text
EMPLOYMENT
APPOINTMENT_DECREE
CONTRACT
IDENTITY_SUPPORT
EDUCATION
CERTIFICATION
PERFORMANCE
DISCIPLINE
OFFBOARDING
OTHER
```

Category bukan legal interpretation engine; tenant catalog tetap menentukan type konkretnya.

Constraints:

```text
UNIQUE (tenant_id, code)
```

## 6.3 `hr_documents`

| Column | Type | Null | Rule |
|---|---|---:|---|
| `id` | UUID | No | UUIDv7 |
| `tenant_id` | UUID | No | owner |
| `document_type_id` | UUID | No | tenant-safe FK |
| `document_number` | varchar(120) | Yes | official/internal number |
| `title` | varchar(255) | No | display |
| `status` | varchar(20) | No | `DRAFT`, `ISSUED`, `SUPERSEDED`, `VOIDED`, `ARCHIVED` |
| `confidentiality` | varchar(20) | No | snapshot |
| `issued_at` | date | Yes | legal/business issue date |
| `effective_from` | date | Yes | |
| `effective_to` | date | Yes | |
| `expires_at` | date | Yes | independent expiry |
| `created_by_membership_id` | UUID | No | actor |
| `supersedes_document_id` | UUID | Yes | same-tenant document |
| `void_reason` | text | Yes | required when VOIDED |
| timestamps | | | |

Rules:

```text
effective_to IS NULL OR effective_to >= effective_from
expires_at may differ from effective_to
supersedes_document_id != id
```

Document number uniqueness is tenant-policy dependent.

Recommended baseline partial uniqueness:

```text
UNIQUE (tenant_id, document_type_id, document_number)
WHERE document_number IS NOT NULL
AND status <> 'VOIDED'
```

If institutional numbering permits duplicates by year/issuer, constraint must be adapted from verified policy before implementation.

## 6.4 `hr_document_versions`

| Column | Type | Null | Rule |
|---|---|---:|---|
| `id` | UUID | No | UUIDv7 |
| `tenant_id` | UUID | No | owner |
| `document_id` | UUID | No | parent |
| `version_no` | integer | No | starts at 1 |
| `storage_disk` | varchar(40) | No | allowlisted private disk |
| `storage_key` | varchar(1024) | No | opaque private object key |
| `original_filename` | varchar(255) | No | display only |
| `mime_type` | varchar(120) | No | server-verified where possible |
| `byte_size` | bigint | No | > 0 |
| `sha256` | char(64) | No | content integrity |
| `state` | varchar(20) | No | `UPLOADED`, `FINALIZED`, `SIGNED`, `REJECTED` |
| `uploaded_by_membership_id` | UUID | No | actor |
| `finalized_by_membership_id` | UUID | Yes | |
| `finalized_at` | timestamptz | Yes | |
| timestamps | | | |

Constraints:

```text
UNIQUE (document_id, version_no)
UNIQUE (tenant_id, storage_key)
byte_size > 0
```

Once state is `FINALIZED` or `SIGNED`:

```text
storage_key
sha256
byte_size
mime_type
binary content
```

must not mutate.

If correction is required:

```text
Version N
    ↓
Version N+1
```

or a new superseding logical Document when legal identity itself changes.

## 6.5 Storage policy

Canonical storage:

```text
private local disk
OR
private S3-compatible object storage
```

Never:

```text
public disk
guessable direct URL
database BLOB
```

Downloads flow through authorized application endpoint or short-lived signed object URL generated only after permission/scope check.

Recommended storage path is opaque, e.g.:

```text
tenants/{tenant_uuid}/hr/documents/{document_uuid}/{version_uuid}
```

Filename is metadata, not path authority.

## 6.6 Upload security

Baseline:

- allowlisted MIME/extension;
- size limit configurable;
- generated object key;
- SHA-256 checksum;
- authorization before upload/finalization/download;
- private storage;
- Content-Disposition attachment where appropriate.

**[RESOURCE GAP]** Malware scanning capability belum tersedia.

Recommended future:

```text
upload → QUARANTINED → AV scan → CLEAN → usable
```

Tidak di-hardcode sampai scanner tersedia.

---

# 7. Document Context Links

## 7.1 `employee_document_links`

| Column | Type | Null |
|---|---|---:|
| `document_id` | UUID | No |
| `employee_id` | UUID | No |
| `employment_id` | UUID | Yes |
| `purpose` | varchar(40) | No |

Primary:

```text
(document_id, employee_id, purpose)
```

If `employment_id` present, employment must belong to employee.

## 7.2 `recruitment_candidate_documents`

Resolves HR-003 document-storage gap.

| Column | Type | Null |
|---|---|---:|
| `document_id` | UUID | No |
| `candidate_id` | UUID | No |
| `purpose` | varchar(40) | No |

Candidate remains Recruitment-owned; document remains HR document.

A rejected candidate document does not require creation of Person/Employee.

---

# 8. E-Signature Boundary

## 8.1 Principle

```text
EduCore document approval
≠
certified electronic signature
```

If institution requires electronic signature with legal assurance, provider/certificate semantics are handled through external signing adapter.

## 8.2 Proposed contract

```text
ElectronicSignatureProviderInterface
├── createEnvelope(...)
├── addSigner(...)
├── send(...)
├── getStatus(...)
├── cancel(...)
└── fetchEvidence(...)
```

HR domain depends on interface, not provider SDK.

## 8.3 `hr_document_signature_envelopes`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `tenant_id` | UUID | No |
| `document_version_id` | UUID | No |
| `provider_code` | varchar(60) | No |
| `external_envelope_id` | varchar(255) | No |
| `signature_class` | varchar(30) | Yes |
| `status` | varchar(30) | No |
| `requested_by_membership_id` | UUID | No |
| `requested_at` | timestamptz | No |
| `completed_at` | timestamptz | Yes |
| `cancelled_at` | timestamptz | Yes |

No private signing key/certificate private material stored in HR tables.

## 8.4 `hr_document_signature_signers`

Legal evidence may require immutable signatory snapshot.

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `envelope_id` | UUID | No |
| `sequence_no` | integer | No |
| `signer_person_id` | UUID | Yes |
| `signer_membership_id` | UUID | Yes |
| `signer_name_snapshot` | varchar(255) | No |
| `signer_capacity_snapshot` | varchar(160) | Yes |
| `status` | varchar(30) | No |
| `signed_at` | timestamptz | Yes |
| `provider_evidence_ref` | varchar(512) | Yes |

Snapshot name/capacity is intentional legal evidence, not canonical Person replacement.

---

# 9. Employment Agreement / Contract Administration

## 9.1 Model

```text
Employment
   ↓ 1..n
EmploymentAgreement
   ↓
HRDocument
   ↓
Signed Document Version
```

One Employment can have:

- initial agreement;
- extension;
- addendum;
- appointment decree;
- amendment;
- subsequent signed agreement.

This does not necessarily mean a new Employment episode.

## 9.2 `employment_agreement_types`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `tenant_id` | UUID | No |
| `code` | varchar(60) | No |
| `name` | varchar(160) | No |
| `semantic_category` | varchar(30) | No |
| `is_fixed_term` | boolean | No |
| `is_active` | boolean | No |

Semantic category examples:

```text
EMPLOYMENT_CONTRACT
APPOINTMENT_DECREE
ADDENDUM
EXTENSION
OTHER
```

Legal label such as PKWT/PKWTT should be configured from verified policy, not inferred solely from `Employment Type`.

## 9.3 `employment_agreements`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `tenant_id` | UUID | No |
| `employment_id` | UUID | No |
| `agreement_type_id` | UUID | No |
| `document_id` | UUID | Yes |
| `signed_document_version_id` | UUID | Yes |
| `agreement_number` | varchar(120) | Yes |
| `status` | varchar(30) | No |
| `effective_from` | date | No |
| `effective_to` | date | Yes |
| `signed_at` | date | Yes |
| `previous_agreement_id` | UUID | Yes |
| `created_by_membership_id` | UUID | No |
| `approved_by_membership_id` | UUID | Yes |
| `approved_at` | timestamptz | Yes |
| `ended_reason` | text | Yes |
| timestamps | | | |

Status:

```text
DRAFT
PENDING_SIGNATURE
ACTIVE
ENDED
SUPERSEDED
VOIDED
```

Rules:

```text
effective_to IS NULL OR effective_to >= effective_from
previous_agreement_id must reference same Employment
signed_document_version_id must belong to document_id
ACTIVE agreement requiring signature must reference signed/finalized version
```

## 9.4 Renewal / extension

Renewal does not mutate historical agreement dates.

```text
Agreement A
2026-01-01 → 2026-12-31
       ↓
Agreement B
2027-01-01 → 2027-12-31
previous_agreement_id = A
```

Historical A remains unchanged.

Whether B means extension vs new contract is determined by agreement type/policy, not a destructive update.

## 9.5 Expiry handling

Agreement expiry creates:

```text
EXPIRING AGREEMENT candidate
→ HR work queue / reminder
→ explicit decision:
   renew
   replace
   end
   no action with authorized reason
```

It must not directly execute:

```text
Employment → ENDED
```

because contract administration and employment lifecycle may differ by policy and exception.

---

# 10. Reminder Architecture

## 10.1 Ownership

HR owns:

```text
what expires
when it is considered reminder-eligible
who should be notified according to HR policy
```

Core owns:

```text
notification delivery mechanics
queue
retry
attempt persistence
```

## 10.2 Existing Core alignment

Current Core exposes infrastructure-level notification channel/job. HR should not couple directly to concrete WhatsApp implementation.

**[REKOMENDASI]** introduce narrow platform contract:

```text
NotificationDispatchInterface
dispatch(
    tenant,
    recipient,
    template/body,
    idempotency key,
    options
)
```

Core remains owner of channel/gateway.

HR reminder scheduler/job can then query:

```text
expiring agreements
expiring documents
future certification expiry (HR-007 integration)
```

and dispatch idempotently.

No generic reminder workflow engine is needed for Phase 2G.

---

# 11. Discipline Domain

## 11.1 Model

```text
Employee / Employment
      ↓
Disciplinary Case
      ├── response / acknowledgement
      ├── finding
      ├── evidence document(s)
      ├── action(s)
      └── follow-up / coaching
```

## 11.2 `disciplinary_action_types`

Tenant-scoped catalog.

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `tenant_id` | UUID | No |
| `code` | varchar(60) | No |
| `name` | varchar(160) | No |
| `severity_order` | integer | Yes |
| `default_validity_days` | integer | Yes |
| `is_active` | boolean | No |

Examples can include:

```text
COACHING
VERBAL_WARNING
SP1
SP2
SP3
SUSPENSION_RECOMMENDATION
TERMINATION_RECOMMENDATION
```

But seed list is not legal/business truth until policy is verified.

No rule:

```text
SP1 → must SP2 → must SP3
```

is hardcoded.

## 11.3 `disciplinary_cases`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `tenant_id` | UUID | No |
| `employee_id` | UUID | No |
| `employment_id` | UUID | No |
| `employment_placement_id` | UUID | Yes |
| `case_number` | varchar(120) | Yes |
| `incident_at` | timestamptz | Yes |
| `opened_at` | timestamptz | No |
| `summary` | text | No |
| `status` | varchar(30) | No |
| `opened_by_membership_id` | UUID | No |
| `decided_by_membership_id` | UUID | Yes |
| `decided_at` | timestamptz | Yes |
| `finding` | varchar(30) | Yes |
| `finding_summary` | text | Yes |
| `closed_at` | timestamptz | Yes |

Status:

```text
OPEN
UNDER_REVIEW
AWAITING_RESPONSE
DECIDED
CLOSED
CANCELLED
```

Finding:

```text
SUBSTANTIATED
PARTIALLY_SUBSTANTIATED
NOT_SUBSTANTIATED
NO_FINDING
```

## 11.4 Employee response

`disciplinary_case_responses`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `case_id` | UUID | No |
| `submitted_by_membership_id` | UUID | Yes |
| `response_text` | text | No |
| `submitted_at` | timestamptz | No |

This records response opportunity/evidence but does not define legal appeal rights universally.

## 11.5 `disciplinary_actions`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `case_id` | UUID | No |
| `action_type_id` | UUID | No |
| `document_id` | UUID | Yes |
| `status` | varchar(30) | No |
| `effective_from` | date | No |
| `effective_to` | date | Yes |
| `issued_by_membership_id` | UUID | No |
| `approved_by_membership_id` | UUID | Yes |
| `approved_at` | timestamptz | Yes |
| `reason` | text | No |
| `revoked_at` | timestamptz | Yes |
| `revoked_reason` | text | Yes |

Action status:

```text
PROPOSED
APPROVED
ISSUED
EXPIRED
REVOKED
VOIDED
```

A `TERMINATION_RECOMMENDATION` is only evidence/input.

It must not directly:

```text
Employment → ENDED
```

Instead:

```text
disciplinary action
      ↓ explicit command
Offboarding Case
      ↓ independent authorization
Employment end
```

## 11.6 Follow-up

`disciplinary_followups`

```text
case_id
action_id nullable
type
description
due_at
completed_at
completed_by_membership_id
outcome
```

Can track:

- coaching;
- mentoring;
- corrective plan;
- meeting;
- acknowledgement;
- other remediation.

---

# 12. Offboarding Domain

## 12.1 Model

```text
Employment
    ↓
Offboarding Case
    ├── reason
    ├── approval
    ├── effective end
    ├── checklist/tasks
    ├── access review
    ├── exit interview
    ├── settlement handoff
    └── completion
```

## 12.2 `offboarding_reasons`

Tenant catalog:

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `tenant_id` | UUID | No |
| `code` | varchar(60) | No |
| `name` | varchar(160) | No |
| `category` | varchar(30) | No |
| `is_active` | boolean | No |

Category:

```text
RESIGNATION
TERMINATION
CONTRACT_END
RETIREMENT
DEATH
OTHER
```

This category is workflow/reporting semantics only, not automatic legal settlement formula.

## 12.3 `offboarding_cases`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `tenant_id` | UUID | No |
| `employee_id` | UUID | No |
| `employment_id` | UUID | No |
| `reason_id` | UUID | No |
| `disciplinary_case_id` | UUID | Yes |
| `status` | varchar(30) | No |
| `requested_end_date` | date | No |
| `approved_end_date` | date | Yes |
| `employment_ended_at` | timestamptz | Yes |
| `initiated_by_membership_id` | UUID | No |
| `approved_by_membership_id` | UUID | Yes |
| `approved_at` | timestamptz | Yes |
| `completed_by_membership_id` | UUID | Yes |
| `completed_at` | timestamptz | Yes |
| `cancel_reason` | text | Yes |
| timestamps | | | |

Constraint:

```text
UNIQUE (employment_id)
```

One Employment episode has at most one non-void offboarding lifecycle.

Status:

```text
DRAFT
PENDING_APPROVAL
APPROVED
IN_PROGRESS
EMPLOYMENT_ENDED
READY_TO_COMPLETE
COMPLETED
CANCELLED
```

## 12.4 Important lifecycle distinction

Offboarding completion does not define legal Employment end timing.

Example:

```text
31 Aug
Employment ENDED

1–5 Sep
asset handover / final settlement reconciliation

5 Sep
Offboarding COMPLETED
```

Therefore:

```text
Employment.end_date
≠
Offboarding.completed_at
```

`Employment` remains source of truth for whether employment is active.

---

# 13. Offboarding Checklist

## 13.1 Template

```text
offboarding_checklist_templates
offboarding_checklist_template_tasks
```

Template is tenant-scoped/versioned.

Task type baseline:

```text
HANDOVER
ASSET_RETURN
ACCESS_REVIEW
DOCUMENT
EXIT_INTERVIEW
FINAL_SETTLEMENT
OTHER
```

## 13.2 Case task

`offboarding_case_tasks`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `case_id` | UUID | No |
| `task_type` | varchar(30) | No |
| `title` | varchar(255) | No |
| `description` | text | Yes |
| `required` | boolean | No |
| `status` | varchar(30) | No |
| `assigned_to_membership_id` | UUID | Yes |
| `due_at` | timestamptz | Yes |
| `completed_at` | timestamptz | Yes |
| `completed_by_membership_id` | UUID | Yes |
| `external_reference` | varchar(255) | Yes |
| `evidence_document_id` | UUID | Yes |

No inventory data is duplicated here. For example:

```text
task = "Return Laptop"
external_reference = future Asset ID / manual reference
```

Asset description can exist as task text until Asset module exists, but HR is not asset source of truth.

---

# 14. Access Review & Revocation

## 14.1 Problem in existing repository

Current role grants:

```text
membership_roles
organizational_assignment_roles
```

do not store why/source that grant exists.

A Membership may also be used for:

```text
Employee
Guardian
other future persona
```

Therefore the following is unsafe:

```text
Employment ended
→ delete all membership roles
→ deactivate Membership
→ disable User
```

## 14.2 Baseline design

When Offboarding becomes APPROVED/IN_PROGRESS, HR creates snapshot review items from active Core grants relevant to the Membership and employment placements.

`offboarding_access_review_items`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `case_id` | UUID | No |
| `grant_scope` | varchar(30) | No |
| `membership_id` | UUID | No |
| `organizational_assignment_id` | UUID | Yes |
| `role_id` | UUID | No |
| `decision` | varchar(20) | No |
| `status` | varchar(20) | No |
| `reviewed_by_membership_id` | UUID | Yes |
| `reviewed_at` | timestamptz | Yes |
| `executed_at` | timestamptz | Yes |
| `reason` | text | Yes |

`grant_scope`:

```text
TENANT
ORGANIZATIONAL_ASSIGNMENT
```

Decision:

```text
PENDING
REVOKE
KEEP
NOT_APPLICABLE
```

HR does not invent a role grant. It snapshots an existing grant identifier.

## 14.3 Revocation rule

Only:

```text
decision = REVOKE
AND actor has appropriate Core authorization
```

may call existing Core revocation capability.

Tenant-level `KEEP` can be valid when the Person remains guardian/administrator/other participant.

## 14.4 OrganizationalAssignment deactivation

HR-002 closes HR `EmploymentPlacement`.

Core OrganizationalAssignment may be deactivated only when explicitly confirmed safe.

Why:

```text
Core OrganizationalAssignment
≠ guaranteed HR-exclusive object
```

Baseline does not automatically deactivate it unless the access reviewer confirms it is no longer required.

## 14.5 Membership/User state

Baseline:

```text
Employment end
→ DO NOT auto deactivate Membership
→ DO NOT auto deactivate User
```

Future automation requires:

- cross-domain participation registry or resolver;
- role/grant provenance;
- explicit Core lifecycle service.

Until then, final offboarding checklist may require an authorized tenant administrator to decide Membership state separately.

---

# 15. Execute Employment End

Explicit endpoint/command:

```text
POST /api/v1/hr/offboarding-cases/{id}/execute-employment-end
```

Preconditions:

```text
Offboarding APPROVED/IN_PROGRESS
approved_end_date available
actor authorized
Employment is PLANNED/ACTIVE per valid transition
no conflicting end
```

Command delegates to HR-002 Employment lifecycle service:

```text
End Employment
├── end open EmploymentPositionAssignments
├── end open EmploymentPlacements
└── Employment → ENDED
```

Offboarding then records `employment_ended_at`.

Must be idempotent.

If Employment already ended with the same case/effective date, retry returns current state.

If it ended through a conflicting process/date, command fails closed for manual reconciliation.

---

# 16. Exit Interview

`offboarding_exit_interviews`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `case_id` | UUID | No |
| `conducted_at` | timestamptz | No |
| `interviewer_membership_id` | UUID | No |
| `summary` | text | Yes |
| `employee_feedback` | text | Yes |
| `confidentiality` | varchar(20) | No |
| `created_at` | timestamptz | No |

Baseline deliberately avoids inferring:

```text
rehire eligible
performance score
disciplinary conclusion
```

from exit interview.

If tenant later needs structured questionnaire, introduce versioned template rather than mutate this evidence.

---

# 17. Final Settlement Boundary

## 17.1 HR facts

Offboarding may expose:

```text
Employment start date
Employment end date
offboarding reason/category
Employment type/classification
agreement facts
approved compensation facts
approved leave facts
remaining leave facts according to HR policy
approved HR adjustments
```

But HR does not calculate:

```text
pesangon
PKWT compensation amount
tax
BPJS final deduction
net final pay
payable
payment
```

## 17.2 `offboarding_settlement_handoffs`

| Column | Type | Null |
|---|---|---:|
| `id` | UUID | No |
| `case_id` | UUID | No |
| `status` | varchar(30) | No |
| `hr_payroll_input_snapshot_id` | UUID | Yes |
| `consumer_reference` | varchar(255) | Yes |
| `requested_at` | timestamptz | Yes |
| `confirmed_at` | timestamptz | Yes |
| `confirmed_by_membership_id` | UUID | Yes |

Status:

```text
NOT_REQUIRED
PENDING
FACTS_READY
SUBMITTED
CONFIRMED
```

No monetary columns.

## 17.3 Impact to HR-006

**[CHANGE IMPACT]** HR-006 payroll input snapshot currently models period/cutoff but not explicit snapshot purpose.

Recommended additive evolution after HR-008 approval:

```text
hr_payroll_input_snapshots.purpose
    REGULAR_PAYROLL
    FINAL_SETTLEMENT
```

This does not change HR ownership or any existing snapshot content semantics.

Until Finance exists, `FINAL_SETTLEMENT` is a consumer-purpose marker only.

---

# 18. Domain-Level History / Evidence

Because Core Audit is best-effort, sensitive transitions must persist domain evidence transactionally.

Recommended append-only event tables:

```text
hr_document_events
employment_agreement_events
disciplinary_case_events
offboarding_case_events
```

Common columns:

```text
id
tenant_id
aggregate_id
event_type
actor_membership_id nullable for system
reason nullable
metadata jsonb limited/non-secret
occurred_at
```

These are not generic platform events and are not an event-sourcing architecture.

They are narrow immutable lifecycle evidence.

Core Audit is additionally written for observability.

---

# 19. Invariants

## INV-HR-LIFE-001 — Private document storage

HR binary must never use public canonical storage.

## INV-HR-LIFE-002 — Final version immutability

Finalized/signed version content and hash cannot be modified.

## INV-HR-LIFE-003 — Signed evidence not overwritten

Re-sign/revision creates a new version/envelope.

## INV-HR-LIFE-004 — Same-tenant references

Every HR document/agreement/case/offboarding relation must be tenant-consistent.

## INV-HR-LIFE-005 — Agreement belongs to Employment

Agreement cannot point across Employee/Employment.

## INV-HR-LIFE-006 — Agreement expiry is not Employment termination

No automatic lifecycle transition.

## INV-HR-LIFE-007 — Discipline action is not termination

Only Offboarding/Employment lifecycle command ends Employment.

## INV-HR-LIFE-008 — One offboarding lifecycle per Employment episode

Duplicate concurrent offboarding case prevented at DB + service level.

## INV-HR-LIFE-009 — Offboarding never deletes canonical identity

Person/User/Employee survive normal offboarding.

## INV-HR-LIFE-010 — Membership not auto-disabled

No automatic Membership status mutation without safe participation review.

## INV-HR-LIFE-011 — Explicit access revocation

Grant is revoked only after a recorded authorized `REVOKE` decision.

## INV-HR-LIFE-012 — Financial boundary

Offboarding does not store final monetary settlement result.

## INV-HR-LIFE-013 — Required checklist

Case cannot be marked COMPLETED while required tasks remain unresolved unless tenant policy explicitly permits authorized waiver with reason.

## INV-HR-LIFE-014 — Historical placement integrity

Discipline/offboarding case may snapshot/reference HR historical EmploymentPlacement; later transfers do not rewrite old context.

## INV-HR-LIFE-015 — No hard delete for legal lifecycle records

Issued documents, agreements, discipline actions, and completed offboarding evidence are lifecycle-ended/voided/superseded, not hard-deleted.

---

# 20. Concurrency

## 20.1 Offboarding case creation

Use transaction + unique constraint on Employment.

Two concurrent requests:

```text
Create Offboarding for Employment E
Create Offboarding for Employment E
```

must produce one canonical case or deterministic conflict.

## 20.2 Document finalization

Lock logical Document during version finalization/sign transition.

No two callers may finalize conflicting version number as current final state.

## 20.3 Agreement activation

Lock Employment + relevant agreement set.

Prevent overlapping incompatible agreements only if tenant/legal policy explicitly defines exclusivity.

Do not hardcode “one active agreement” because addendum/appointment decree can overlap contract evidence.

## 20.4 Employment end

Lock:

```text
OffboardingCase
Employment
open PositionAssignments
open EmploymentPlacements
```

in deterministic order consistent with HR-002.

---

# 21. Service Boundaries

Proposed application services:

```text
HRDocumentServiceInterface
├── createDocument
├── uploadVersion
├── finalizeVersion
├── issueDocument
├── supersedeDocument
├── voidDocument
└── authorizeDownload

ElectronicSignatureServiceInterface
├── requestSignature
├── refreshSignatureStatus
└── cancelSignature

EmploymentAgreementServiceInterface
├── createAgreement
├── submitForSignature
├── activateAgreement
├── supersedeAgreement
├── endAgreement
└── listExpiringAgreements

DisciplinaryCaseServiceInterface
├── openCase
├── recordResponse
├── recordFinding
├── proposeAction
├── approveAction
├── issueAction
├── revokeAction
├── addFollowup
└── closeCase

OffboardingServiceInterface
├── initiate
├── approve
├── generateChecklist
├── snapshotAccessReview
├── decideAccessItem
├── executeApprovedRevocations
├── executeEmploymentEnd
├── recordExitInterview
├── prepareSettlementFacts
└── complete
```

No service should call concrete storage/WhatsApp/e-sign provider directly; infrastructure adapters are injected behind contracts.

---

# 22. API Foundation

## Documents

```text
GET    /api/v1/hr/documents
POST   /api/v1/hr/documents
GET    /api/v1/hr/documents/{id}
POST   /api/v1/hr/documents/{id}/versions
POST   /api/v1/hr/documents/{id}/finalize
POST   /api/v1/hr/documents/{id}/issue
POST   /api/v1/hr/documents/{id}/supersede
POST   /api/v1/hr/documents/{id}/void
GET    /api/v1/hr/documents/{id}/download
POST   /api/v1/hr/documents/{id}/signature-envelopes
```

## Agreements

```text
GET    /api/v1/hr/employments/{employmentId}/agreements
POST   /api/v1/hr/employments/{employmentId}/agreements

GET    /api/v1/hr/employment-agreements/{id}
POST   /api/v1/hr/employment-agreements/{id}/activate
POST   /api/v1/hr/employment-agreements/{id}/supersede
POST   /api/v1/hr/employment-agreements/{id}/end

GET    /api/v1/hr/employment-agreements/expiring
```

## Discipline

```text
GET    /api/v1/hr/disciplinary-cases
POST   /api/v1/hr/disciplinary-cases
GET    /api/v1/hr/disciplinary-cases/{id}

POST   /api/v1/hr/disciplinary-cases/{id}/responses
POST   /api/v1/hr/disciplinary-cases/{id}/finding
POST   /api/v1/hr/disciplinary-cases/{id}/actions
POST   /api/v1/hr/disciplinary-actions/{id}/approve
POST   /api/v1/hr/disciplinary-actions/{id}/issue
POST   /api/v1/hr/disciplinary-actions/{id}/revoke
POST   /api/v1/hr/disciplinary-cases/{id}/followups
POST   /api/v1/hr/disciplinary-cases/{id}/close
```

## Offboarding

```text
GET    /api/v1/hr/offboarding-cases
POST   /api/v1/hr/offboarding-cases
GET    /api/v1/hr/offboarding-cases/{id}

POST   /api/v1/hr/offboarding-cases/{id}/submit
POST   /api/v1/hr/offboarding-cases/{id}/approve
POST   /api/v1/hr/offboarding-cases/{id}/access-review/snapshot
POST   /api/v1/hr/offboarding-access-review-items/{id}/decide
POST   /api/v1/hr/offboarding-cases/{id}/access-review/execute
POST   /api/v1/hr/offboarding-cases/{id}/execute-employment-end
POST   /api/v1/hr/offboarding-cases/{id}/exit-interview
POST   /api/v1/hr/offboarding-cases/{id}/settlement-facts
POST   /api/v1/hr/offboarding-cases/{id}/complete
```

Lifecycle status is command-driven, not arbitrary PATCH.

---

# 23. Authorization Catalog

Proposed:

```text
hr.document.self.read
hr.document.read
hr.document.manage
hr.document.issue
hr.document.void
hr.document.signature.request
hr.document.restricted.read

hr.agreement.self.read
hr.agreement.read
hr.agreement.manage
hr.agreement.approve

hr.discipline.self.read
hr.discipline.read
hr.discipline.manage
hr.discipline.decide
hr.discipline.action.approve
hr.discipline.action.revoke

hr.offboarding.self.read
hr.offboarding.read
hr.offboarding.manage
hr.offboarding.approve
hr.offboarding.access_review
hr.offboarding.employment_end
hr.offboarding.complete
hr.offboarding.exit_interview.read

hr.settlement_facts.read
hr.settlement_facts.generate
```

Capability separation is intentional:

```text
manage ≠ approve
discipline manage ≠ discipline decide
offboarding approve ≠ employment_end
document read ≠ restricted document read
```

Scope continues to use Core authorization context.

---

# 24. Self-Service Exposure

Employee self-service may read:

- own issued contract/SK;
- own permitted personnel documents;
- own disciplinary action/document if policy allows;
- own offboarding status;
- own checklist items assigned to them.

Self-service must not expose:

- investigator-only notes;
- confidential witness/evidence data;
- other employee documents;
- access-review role decisions not intended for employee;
- exit interview restricted analysis;
- provider secret metadata.

Response DTO must be purpose-shaped rather than returning raw database model.

---

# 25. Sensitive Data Classification

Recommended classes:

```text
STANDARD
SENSITIVE
RESTRICTED
```

Examples:

- employment contract → SENSITIVE;
- identity support document → RESTRICTED;
- disciplinary evidence → RESTRICTED;
- ordinary appointment memo → tenant policy.

Rules:

- restricted content requires dedicated permission;
- download access logged;
- no document binary/hash in general list endpoint;
- no signed URL persisted;
- no raw e-sign provider payload exposed;
- no confidential narrative in Core audit metadata.

---

# 26. Audit Events

Examples:

```text
hr.document.created
hr.document.version_uploaded
hr.document.version_finalized
hr.document.issued
hr.document.signature_requested
hr.document.signed
hr.document.superseded
hr.document.voided

hr.agreement.created
hr.agreement.activated
hr.agreement.superseded
hr.agreement.ended

hr.discipline.case_opened
hr.discipline.response_recorded
hr.discipline.finding_decided
hr.discipline.action_approved
hr.discipline.action_issued
hr.discipline.action_revoked
hr.discipline.case_closed

hr.offboarding.initiated
hr.offboarding.approved
hr.offboarding.access_review_snapshotted
hr.offboarding.access_revoked
hr.offboarding.employment_ended
hr.offboarding.exit_interview_recorded
hr.offboarding.settlement_facts_ready
hr.offboarding.completed
```

Domain event row = durable lifecycle evidence.

Core audit = supplemental operational audit.

---

# 27. Change Impact to Previous Locked Phases

## HR-002 Workforce Foundation

**KEEP.**

Offboarding calls existing Employment end semantics.

Additional clarification:

```text
Employment end
→ closes HR position/placement
→ does not automatically deactivate Core Membership/User
```

This resolves previously documented offboarding orchestration gap.

## HR-003 Recruitment / Onboarding

Document binary architecture gap is resolved by HR Document foundation.

Candidate document association uses explicit candidate-document link.

No Candidate → Person behavior changes.

## HR-004 Leave

No schema change required.

Offboarding may query remaining leave facts for Finance handoff but does not calculate monetary value.

## HR-005 Attendance

No schema change.

Attendance history survives Employee offboarding.

## HR-006 Compensation / Payroll Input

Potential additive `purpose` field:

```text
REGULAR_PAYROLL
FINAL_SETTLEMENT
```

requires formal update after HR-008 approval.

No monetary ownership changes.

## HR-007 Performance/Competency

HR Document foundation can later be linked to:

- certificate evidence;
- performance evidence;
- training certificate.

No immediate destructive schema modification.

## Core

No destructive change.

Potential narrow extensions:

1. `NotificationDispatchInterface`
2. future safe Participation Review/Lifecycle contract
3. future role-grant provenance

Only #1 is recommended near-term for reminders. #2/#3 remain future because the current repository does not have sufficient semantics for safe automation.

---

# 28. Migration Strategy

Repository currently has no corresponding HR tables, therefore migrations are additive.

Recommended order:

```text
1. hr_document_types
2. hr_documents
3. hr_document_versions
4. document context links
5. signature envelope/signers
6. employment_agreement_types
7. employment_agreements
8. disciplinary_action_types
9. disciplinary_cases / responses / actions / followups
10. offboarding_reasons
11. offboarding checklist templates/tasks
12. offboarding_cases
13. offboarding access review
14. exit interviews
15. settlement handoffs
16. domain lifecycle event tables
```

No backfill should invent:

- old contract dates;
- signed documents;
- disciplinary history;
- offboarding reason;
- settlement values.

Existing `Employee.deleted_at` is not converted into “resigned”.

Historical records should only be imported from verified source documents/data.

---

# 29. Index Strategy

Key indexes:

```text
hr_documents
(tenant_id, status)
(tenant_id, document_type_id)
(tenant_id, expires_at)
(tenant_id, document_number)

hr_document_versions
(document_id, version_no)
(tenant_id, sha256)

employment_agreements
(tenant_id, employment_id)
(tenant_id, status, effective_to)

disciplinary_cases
(tenant_id, employee_id, opened_at)
(tenant_id, employment_id, status)
(tenant_id, status, opened_at)

disciplinary_actions
(case_id, status)
(effective_to)

offboarding_cases
(tenant_id, status, requested_end_date)
(tenant_id, employee_id)
UNIQUE(employment_id)

offboarding_case_tasks
(case_id, status)
(assigned_to_membership_id, status, due_at)

offboarding_access_review_items
(case_id, status, decision)
(membership_id, status)
```

All scoped query paths must include tenant predicate.

---

# 30. Validation Rules

## Documents

- UUIDv7 IDs;
- private storage disk allowlist;
- filename length limits;
- byte size > 0;
- MIME allowlist;
- valid SHA-256;
- no issue/effective expiry contradictions where policy defines them;
- signed/final version immutable.

## Agreements

- Employment belongs tenant;
- agreement date range valid;
- previous agreement same Employment;
- signed version belongs specified document;
- activation requires approval/signature according to agreement type policy.

## Discipline

- Employment belongs Employee;
- actor scope authorized;
- action type active;
- final finding required before punitive action unless policy explicitly supports interim action;
- termination recommendation cannot call Employment end directly.

## Offboarding

- Employment belongs Employee;
- only one active case per Employment;
- approved end date required before execute end;
- Employment end command idempotent;
- required checklist completion/waiver validation;
- access review unresolved items block final completion if tenant policy marks them required.

---

# 31. Test Contract

## 31.1 Document

- tenant cannot access other tenant document;
- public disk rejected;
- same document version cannot mutate after finalization;
- hash mismatch rejected;
- signed version immutable;
- supersede preserves old document;
- restricted document requires restricted permission;
- unauthorized download fails closed.

## 31.2 Agreement

- Agreement history preserved across renewal;
- expiration does not auto-end Employment;
- previous agreement cross-employment rejected;
- signed version/document mismatch rejected;
- concurrent activation remains consistent.

## 31.3 Discipline

- actor without scope cannot open/decide;
- employee cannot finalize own disciplinary decision unless explicit policy/capability allows;
- SP code not hardcoded;
- action does not automatically change Employment;
- action history survives revoke/expiry;
- case documents remain private.

## 31.4 Offboarding

- duplicate case concurrency prevented;
- offboarding does not delete Employee/Person/User;
- Employment end closes HR child assignments through HR-002;
- Membership remains unchanged by default;
- access review snapshots real grants;
- only approved REVOKE item executes revocation;
- KEEP role survives;
- effective employment end can precede offboarding completion;
- final settlement contains no final monetary amount;
- completed case remains queryable.

## 31.5 Regression

Existing:

```text
Employee provisioning
Academic teacher employee linkage
Core membership switching
Core role assignment
OrganizationalAssignment
Attendance history
Leave history
Compensation history
Performance history
```

must remain unchanged.

---

# 32. Traceability

| Business Need | Requirement | Technical Design | Test |
|---|---|---|---|
| Digital HR archive | HR-001 Document Admin | HRDocument + Version | immutable/private download test |
| Contract renewal | HR-001 Contract | EmploymentAgreement chain | renewal history test |
| SK management | HR-001 Document | DocumentType + issued/signed artifact | issued version test |
| E-signature | HR-001 E-sign | Provider adapter + signature evidence | signer/evidence immutability |
| SP1/SP2/SP3 | HR-001 Discipline | tenant action catalog | no hardcoded sequence |
| Coaching | HR-001 Discipline | disciplinary_followups | follow-up lifecycle |
| Resignation/termination | HR-001 Offboarding | OffboardingCase + EndEmployment | lifecycle test |
| Exit interview | HR-001 Offboarding | OffboardingExitInterview | access/privacy test |
| Handover/assets | HR-001 Offboarding | case tasks/reference | required task test |
| Final rights | HR-001 Offboarding + HR-006 | settlement facts handoff | financial boundary test |
| Access revocation | Security/NFR | explicit access review | keep/revoke test |
| Audit trail | NFR | domain event + Core Audit | immutable evidence test |

---

# 33. Non-Functional Requirements

## NFR-HR-LIFE-001 — Document confidentiality

All HR documents private by default and permission-controlled.

## NFR-HR-LIFE-002 — Content integrity

Finalized/signed document is hash-verifiable.

## NFR-HR-LIFE-003 — Historical integrity

Document/agreement/discipline/offboarding evidence cannot be silently overwritten.

## NFR-HR-LIFE-004 — Tenant isolation

Every query/storage relation must be tenant-scoped.

## NFR-HR-LIFE-005 — Secure storage

Binary must use private storage with opaque keys.

## NFR-HR-LIFE-006 — Authorization correctness

Job title is never an approval/access mechanism.

## NFR-HR-LIFE-007 — Identity preservation

Offboarding must not destructively remove canonical identity.

## NFR-HR-LIFE-008 — Module acyclicity

HR does not create dependency on future Finance/Asset implementations.

## NFR-HR-LIFE-009 — Auditability

Critical transitions record actor/time/reason transactionally and also emit Core audit where possible.

## NFR-HR-LIFE-010 — External-provider isolation

E-sign/notification/storage providers are behind contracts.

## NFR-HR-LIFE-011 — Legal adaptability

Contract/discipline/offboarding reason catalogs and policy metadata are configurable/version-aware rather than hardcoded national assumptions.

## NFR-HR-LIFE-012 — Backward compatibility

No existing employee/person/academic endpoint or persistence is destructively modified.

---

# 34. Risks

## [RISK] R-001 — Public document leakage

**Mitigation:** private disk only, authorization-gated download, no persisted public URL.

## [RISK] R-002 — Core audit mistaken for legal evidence

Current audit is fail-open.

**Mitigation:** domain-level immutable event/evidence plus Core audit.

## [RISK] R-003 — E-sign checkbox treated as legal signature

**Mitigation:** provider/evidence model; internal approval and external e-sign are separate concepts.

## [RISK] R-004 — Contract expiry accidentally terminates employee

**Mitigation:** reminder + explicit Employment end command.

## [RISK] R-005 — SP sequence hardcoded

Institution/regulation can differ.

**Mitigation:** tenant action catalog.

## [RISK] R-006 — Disciplinary action bypasses termination approval

**Mitigation:** recommendation creates explicit Offboarding Case.

## [RISK] R-007 — Offboarding removes guardian/other access

Membership/User can represent more than Employee.

**Mitigation:** explicit role access review; no auto Membership/User deactivation.

## [RISK] R-008 — Stale authorization after termination

Conservative access handling may leave role if reviewer misses it.

**Mitigation:** required access-review checklist, due date/escalation, final completion gate, future grant provenance.

## [RISK] R-009 — HR becomes Asset system

**Mitigation:** asset return is task/reference only.

## [RISK] R-010 — HR calculates legally incorrect termination amount

**Mitigation:** settlement facts only; Finance/legal policy owns monetary formula.

## [RISK] R-011 — Retention policy deletes needed evidence

**Mitigation:** no automated purge until tenant/legal retention policy is approved.

## [RISK] R-012 — File upload malware

**Mitigation:** strict MIME/size/private storage now; add quarantine/AV adapter before broad external uploads in production.

---

# 35. Open Items

Do not block foundation:

1. **[OPEN DECISION]** Document retention periods.
2. **[OPEN DECISION]** Document numbering policy.
3. **[OPEN DECISION]** Default HR document type catalog.
4. **[OPEN DECISION]** Exact agreement/PKWT/PKWTT taxonomy per institution.
5. **[OPEN DECISION]** Which agreement types legally require signature.
6. **[OPEN DECISION]** E-sign/PSrE provider.
7. **[OPEN DECISION]** Malware scanner/provider.
8. **[OPEN DECISION]** Contract expiry reminder thresholds.
9. **[OPEN DECISION]** Exact SP/discipline policy and action validity.
10. **[OPEN DECISION]** Formal employee appeal/review workflow.
11. **[OPEN DECISION]** Offboarding approval chain.
12. **[OPEN DECISION]** Which offboarding tasks are mandatory per reason.
13. **[OPEN DECISION]** Whether Exit Interview is mandatory.
14. **[OPEN DECISION]** Asset module/reference integration.
15. **[OPEN DECISION]** Finance final-settlement contract.
16. **[OPEN DECISION]** Membership deactivation policy after cross-domain participation review.
17. **[OPEN DECISION]** Role-grant provenance model.
18. **[OPEN DECISION]** Statutory/institutional termination formula.
19. **[OPEN DECISION]** Document archival/purge implementation.
20. **[OPEN DECISION]** Structured exit-interview template.

---

# 36. ADR Assessment

No new ADR is required for Phase 2G foundation.

ADR-032 already establishes:

- HR owns Employment lifecycle;
- Core owns identity/authorization/organization;
- Finance owns financial settlement;
- Person/User not deleted by employment changes.

Potential future ADRs only when a platform-wide decision emerges:

```text
Shared Document Platform Extraction
Cross-Domain Participation & Membership Deactivation
Authorization Grant Provenance
```

These should not be created prematurely.

---

# 37. Reviewer Assessment — Phase 2G

**Quality Score:** 9.5/10

**Gaps:**

- no institution-specific legal/contract policy yet;
- no e-sign provider;
- no AV scanner;
- no Asset module;
- no Finance final settlement engine;
- no role-grant provenance/cross-domain participation registry.

**Risks:**

- document privacy leakage;
- signed evidence mutation;
- auto termination based on contract expiry;
- hardcoded disciplinary process;
- unsafe Membership/User deactivation;
- stale access after offboarding;
- financial/legal formulas being placed inside HR;
- overengineering a shared DMS prematurely.

**Recommendations:**

1. approve logical document + immutable version foundation;
2. keep storage private and provider-abstracted;
3. retain employment agreement as separate domain record from file;
4. use explicit offboarding case and Employment end command;
5. require access review and never auto-disable Membership/User at baseline;
6. keep monetary settlement in future Finance;
7. use domain lifecycle events for legally sensitive evidence;
8. reuse Core notification delivery, but introduce a narrow dispatch contract before HR reminder implementation;
9. defer generic DMS extraction until multiple domains prove the need;
10. verify institution-specific disciplinary/contract policy before seeding catalogs.

**Status: APPROVED — LOCKED**

---

# 38. Recommended Next Phase

After HR-008 approval, recommended continuation:

```text
Phase 2H — HR Reporting, Dashboard & Government Export Boundary
```

This phase should consolidate:

```text
Workforce
Recruitment
Leave
Attendance
Compensation facts
Performance/Competency
Documents/Contracts
Discipline
Offboarding
```

into read models/reporting/export contracts without turning reporting tables into new sources of truth.

After Phase 2H is stable and approved, Phase 2 System/Data Design can be reviewed as a complete HR architecture before proceeding to Phase 3 — UI/UX, Security & Deployment.

---

# 39. Approval Record

**Decision:** APPROVED  
**Locked Version:** 1.0  
**Approval Date:** 2026-08-22  

HR-008 menjadi baseline resmi untuk HR Documents & Digital Archive, Employment Contract / SK Administration, Discipline & Corrective Action, Offboarding / Employment Exit, access review, dan final-settlement facts handoff boundary.

Perubahan setelah versi ini wajib diperlakukan sebagai change request dan melalui impact analysis terhadap HR-001 s.d. HR-008, ADR-032, Core Identity/Membership/RBAC/Organization, serta integration boundary Finance/Attendance/Academic.
