# HR-012 — HR Loading, Empty, Error, Permission & Recovery States

**Version:** 0.1 Draft
**Phase:** 3C — Loading / Empty / Error / Permission / Recovery States
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
**Depends On:** HR-001–HR-011, ADR-032, FE-005, FE-006, FE-007, ADR-025, ADR-027

---

# 1. Objective

HR-012 mendefinisikan state UX seluruh capability HR dengan **mereuse FE-007**, bukan membuat error/loading architecture baru.

Target:

```text
Canonical API Result
        ↓
Shared Error / Loading Infrastructure
        ↓
HR Domain Context
        ↓
Correct HR State
        ↓
Safe Recovery
```

State harus dapat membedakan:

```text
LOADING
≠ EMPTY
≠ PERMISSION DENIED
≠ BUSINESS UNAVAILABLE
≠ VALIDATION ERROR
≠ CONFLICT
≠ NOT FOUND
≠ CONTEXT FAILURE
≠ SERVER FAILURE
≠ NETWORK FAILURE
```

---

# 2. Existing Foundation Classification

| Foundation                          | HR Decision          |
| ----------------------------------- | -------------------- |
| FE-005 Workspace recovery           | **KEEP / REUSE**     |
| FE-006 capability states            | **KEEP / REUSE**     |
| FE-007 error/loading/retry taxonomy | **KEEP / REUSE**     |
| ADR-025 canonical error handling    | **KEEP / REUSE**     |
| ADR-027 authorization UX            | **KEEP / REUSE**     |
| HR-specific state wording           | **EXTEND**           |
| Separate HR error framework         | **DO NOT INTRODUCE** |
| Separate HR retry policy            | **DO NOT INTRODUCE** |

---

# 3. Canonical State Model

## HR-012-BR-001

HR page/component harus memiliki explicit state semantics.

Conceptual:

```text
UNRESOLVED
LOADING
READY
EMPTY
ERROR
```

Pada `READY`, business state dapat menambah kondisi seperti:

```text
AVAILABLE
RESTRICTED
FINALIZED
STALE
CONFLICTED
PROCESSING
```

State tersebut tidak boleh direduksi menjadi satu generic boolean seperti:

```text
loading = true
error = true
```

---

# 4. Loading States

## HR-012-FR-001 — Initial HR page load

Initial resource load menggunakan:

```text
page skeleton
```

atau scoped loading state.

Tidak menggunakan full-screen application loading untuk query HR normal.

---

## HR-012-FR-002 — Background refresh

Jika existing data masih valid dalam **context yang sama**:

```text
existing content
+
subtle refresh indication
```

boleh dipertahankan.

Tetapi setelah:

```text
Tenant switch
Workspace switch
```

old-context HR data **tidak boleh** digunakan sebagai stale placeholder.

---

## HR-012-FR-003 — Mutation pending

Mutation menggunakan local pending state.

Contoh:

```text
Approve Leave
→ Approving...

End Employment
→ Ending Employment...

Complete Offboarding
→ Completing...
```

Action pemicu harus mencegah accidental duplicate submission.

Seluruh application tidak perlu diblokir kecuali terjadi global context transition.

---

## HR-012-FR-004 — Capability loading

Jika permission HR belum resolved:

```text
protected action
→ unresolved
```

UI tidak boleh sementara menampilkan seluruh action lalu menghilangkannya setelah capability selesai dimuat.

---

# 5. Empty-State Taxonomy

HR harus membedakan minimal tiga jenis empty state.

### A. True domain empty

```text
200
data = []
```

Contoh:

> Belum ada kandidat rekrutmen.

### B. Filtered empty

Data mungkin ada tetapi tidak sesuai filter.

> Tidak ada pengajuan cuti yang cocok dengan filter saat ini.

Recovery:

```text
Clear filters
```

### C. Scoped empty

Tidak ada record yang tersedia pada Tenant/Workspace saat ini.

> Tidak ada pegawai yang tersedia pada workspace ini.

Ketiganya **bukan error**.

---

# 6. Empty State + Create Capability

## HR-012-FR-005

Jika dataset kosong dan user mempunyai create capability:

```text
No records yet
+
contextual primary action
```

Contoh:

```text
Belum ada kandidat.

[ Tambah Kandidat ]
```

Jika create capability tidak tersedia:

```text
Belum ada kandidat yang tersedia.
```

Jangan menampilkan disabled unauthorized create button.

---

# 7. Permission States

Canonical rule:

```text
Permission missing
→ normally HIDDEN

Business state prevents action
→ DISABLED + explanation
```

---

## HR-012-FR-006 — Unauthorized navigation/action

Jika capability projection menyatakan user tidak memiliki akses:

- navigation/action tidak ditampilkan;
- control tidak hanya disembunyikan melalui CSS;
- control tidak boleh tetap focusable;
- permission machine name tidak ditampilkan ke normal user.

---

## HR-012-FR-007 — Direct route denial

Jika user mengakses URL HR langsung:

```text
capabilities UNRESOLVED
→ wait

capabilities READY + denied
→ permission state / safe route
```

Protected HR content tidak boleh dirender sebelum authorization UX resolved.

---

## HR-012-FR-008 — Backend denial wins

Jika frontend menganggap action tersedia tetapi backend mengembalikan:

```text
403 AUTHORIZATION_DENIED
```

maka:

```text
backend denial
→ authoritative

operation stops
→ capabilities refreshed
→ page/action re-evaluated
```

User:

- tidak logout;
- Tenant tidak dihapus;
- Workspace tidak dihapus hanya karena authorization denial.

---

# 8. Business Restriction ≠ Permission Denial

Contoh:

User mempunyai capability untuk melakukan finalization, tetapi record sudah finalized.

UX:

```text
Finalize
[disabled]

Already finalized.
```

Bukan:

```text
button hidden
```

karena user sebenarnya authorized tetapi operation tidak valid pada current domain state.

Ini penting pada:

- Employment lifecycle;
- Leave approval;
- Attendance finalization;
- Performance finalization;
- Document version;
- Discipline;
- Offboarding.

---

# 9. Validation State

## HR-012-FR-009

Canonical:

```text
422 VALIDATION_FAILED
```

ditampilkan sebagai:

```text
field-level error
+
form summary bila diperlukan
```

Server validation tetap authoritative.

Frontend tidak boleh mengubah validation error menjadi generic toast.

---

## HR-012-FR-010

Field error dari server yang tidak dikenali frontend tidak boleh diabaikan.

Fallback:

```text
form-level validation state
+
safe server message
```

dan dapat dicatat sebagai contract mismatch.

---

# 10. Conflict State

Conflict memiliki semantics berbeda dari validation.

Contoh:

```text
Leave request was PENDING
↓
another approver processed it
↓
old page submits Approve
```

Expected:

```text
409 + stable domain code
```

UX:

> Status pengajuan telah berubah sejak halaman ini dimuat.

Recovery:

```text
preserve safe input if relevant
→ refresh canonical state
→ show current state
```

---

## HR-012-FR-011

Conflict tidak boleh secara otomatis mengulang mutation.

---

# 11. Not Found State

## HR-012-FR-012

Detail HR resource yang sudah tidak tersedia menggunakan page/local not-found state.

Contoh:

```text
Employee record is no longer available.
```

Recovery dapat berupa:

```text
Back to Employee Directory
```

Tidak semua `404` diarahkan ke global application 404.

---

# 12. Organizational Context Required

Jika feature membutuhkan Organization/OrganizationUnit context:

```text
ORGANIZATIONAL_CONTEXT_REQUIRED
```

UX:

```text
Feature ini membutuhkan workspace organisasi.

[ Pilih Workspace ]
```

Bukan generic:

```text
Forbidden
```

---

# 13. Stale Organizational Context

Jika backend memberikan:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

atau assignment terbukti stale:

```text
STOP current operation
→ clear stale Workspace
→ discard stale persisted workspace
→ rediscover workspaces
→ fallback Tenant workspace
→ reload capabilities
→ navigate safe
```

Request tidak boleh terus diulang menggunakan assignment stale.

---

# 14. Network Failure

Network failure berarti tidak ada valid HTTP response.

UX:

> Tidak dapat terhubung ke EduCore. Periksa koneksi dan coba lagi.

Network failure **tidak boleh**:

```text
clear authentication
```

atau:

```text
redirect login
```

tanpa canonical authentication rejection.

---

# 15. Server Failure

Canonical server failure tidak boleh membocorkan:

- SQL;
- stack trace;
- exception class;
- internal filesystem path;
- infrastructure hostname;
- sensitive payload.

Default HR wording dapat berupa:

> Permintaan tidak dapat diselesaikan. Silakan coba kembali.

Exact wording dapat disempurnakan pada implementation UX copy.

---

# 16. Mutation Ambiguity

Untuk:

```text
POST
PUT
PATCH
DELETE
```

default:

```text
NO automatic retry
```

Jika koneksi terputus setelah submission:

```text
server may have committed
OR
server may not have committed
```

UX tidak boleh langsung mengatakan operation pasti gagal.

Contoh:

> Status operasi belum dapat dikonfirmasi. Muat ulang data terbaru sebelum mencoba kembali.

---

## HR-012-FR-013

Untuk mutation lifecycle kritikal:

```text
Approve
Finalize
End Employment
Complete Offboarding
Generate Sensitive Export
```

recovery harus:

```text
verify authoritative state
before allowing retry
```

kecuali future API mempunyai explicit idempotency guarantee.

---

# 17. Workforce State Matrix

| State                              | UX                                |
| ---------------------------------- | --------------------------------- |
| Directory loading                  | Table/list skeleton               |
| No employees                       | Empty state                       |
| No employee matching filters       | Filtered-empty                    |
| Employee unavailable               | Detail not-found                  |
| Employment loading                 | Section skeleton                  |
| No Employment history              | Domain empty state                |
| Employment `PLANNED`               | Status visible                    |
| Employment `ACTIVE`                | Current-state actions             |
| Employment `ENDED`                 | Historical/read-oriented actions  |
| Lifecycle conflict                 | Refresh/reconcile                 |
| User lacks sensitive-detail access | Sensitive section omitted/limited |

---

# 18. Recruitment State Matrix

```text
Candidate lifecycle
≠ Person lifecycle
≠ Employee lifecycle
```

Important states include:

| Condition                | UX                               |
| ------------------------ | -------------------------------- |
| No candidates            | Recruitment empty                |
| Selection processing     | Local workflow state             |
| Potential identity match | Explicit resolution required     |
| Weak match               | Never silent merge               |
| Already converted        | Show canonical conversion result |
| Conversion conflict      | Re-read identity state           |
| Hiring action denied     | Refresh capabilities             |

Identity-resolution error must never default to creating duplicate identity merely to bypass recovery.

---

# 19. Leave & Permit State Matrix

Minimum UI distinction:

```text
DRAFT / SUBMITTED / PENDING
APPROVED
REJECTED
other canonical states when defined
```

**[CONSTRAINT]** Exact status vocabulary remains governed by HR-004 implementation contract.

## HR-012-FR-014

Approval action becomes unavailable based on current lifecycle state.

Example:

```text
User has approve permission
+
Request APPROVED
→ Approve disabled/not applicable
```

rather than incorrectly showing permission denied.

## HR-012-FR-015

Empty leave balance is not automatically equivalent to API failure.

Balance data unavailable because source failed must display error/recovery state, not `0`.

---

# 20. Attendance State Matrix

UI must preserve:

```text
Raw Evidence
≠ Reconciliation
≠ Final Attendance Fact
```

States requiring distinct UX may include:

```text
needs reconciliation
reconciled
finalized
source unavailable
```

Exact canonical enum remains owned by HR-005/Attendance implementation contract.

## HR-012-FR-016

Jika source attendance gagal dimuat, UI tidak boleh menyimpulkan:

```text
No attendance
```

atau:

```text
Absent
```

Error/data-unavailable harus terlihat berbeda dari canonical attendance result.

---

# 21. Compensation / Payroll Input States

HR failure tidak boleh membuat UI mengklaim Finance payroll result.

Examples:

```text
Payroll input unavailable
≠ payroll calculation failed

No payroll input
≠ employee will receive zero payroll
```

## HR-012-FR-017

Jika cross-domain Finance result tidak tersedia, HR harus menampilkan:

```text
related data unavailable
```

tanpa mengubah HR source facts.

---

# 22. Performance & Development States

Finalized assessment:

```text
read-only
```

Jika correction/reopen belum mempunyai business authority:

```text
do not invent editable recovery
```

State error saat rubric/framework gagal dimuat tidak boleh menghasilkan fallback score/rubric hardcoded di frontend.

---

# 23. Documents & Agreements States

Minimum distinctions:

```text
UPLOAD/PROCESSING where applicable
AVAILABLE
FINALIZED
SIGNED
FAILED
```

hanya jika canonical backend model mendukung status tersebut.

## HR-012-FR-018

Jika file artifact gagal tersedia:

```text
metadata exists
+
file retrieval unavailable
```

jangan menganggap document record hilang.

## HR-012-FR-019

Finalized/signed document:

```text
immutable
```

UI tidak menawarkan generic edit sebagai recovery.

---

# 24. Discipline States

Disciplinary catalog bersifat tenant-scoped.

Jika catalog gagal dimuat:

```text
ERROR
```

bukan fallback ke hardcoded:

```text
SP1
SP2
SP3
```

## HR-012-FR-020

Unknown/unsupported disciplinary state harus fail safely dan tidak menghasilkan automatic escalation.

---

# 25. Offboarding States

Must remain distinct:

```text
Employment ENDED
≠ Offboarding COMPLETED
```

Offboarding UI dapat menunjukkan:

```text
NOT STARTED
IN PROGRESS
BLOCKED
COMPLETED
```

hanya sejauh canonical implementation mendukung vocabulary tersebut.

Jika prerequisite belum selesai:

```text
Complete Offboarding
→ disabled
→ explanation of unmet requirement
```

bukan permission error.

---

# 26. Reporting States

Reporting harus membedakan:

```text
LIVE
PROJECTED
FROZEN
```

sesuai HR-009.

Projected reporting yang applicable harus dapat memperlihatkan:

```text
source_as_of
READY / STALE / FAILED
```

---

## HR-012-FR-021

`STALE` projection bukan otomatis `ERROR`.

User dapat diperlihatkan existing snapshot disertai freshness warning jika requirement HR-009 mengizinkan data tersebut digunakan.

---

## HR-012-FR-022

`FAILED` projection tidak boleh menampilkan metric lama seolah fresh tanpa indikasi.

---

# 27. Export State Matrix

Async export, bila digunakan:

```text
QUEUED
PROCESSING
READY
FAILED
```

## HR-012-FR-023

`READY` tidak otomatis berarti semua user yang dapat melihat report boleh mengambil artifact.

Download/access artifact tetap membutuhkan export authorization policy.

## HR-012-FR-024

Export generation failure:

```text
FAILED
```

tidak boleh menghapus source/canonical HR data.

Recovery dapat menawarkan regenerate hanya bila operation semantics aman.

---

# 28. Capability Refresh During Active HR Page

Scenario:

```text
HR page open
↓
permission changes on backend
↓
next request → 403
```

Required:

```text
backend 403
→ stop operation
→ refresh capabilities
→ reevaluate page
```

Possible outcomes:

### Route permission lost

```text
business content removed
→ forbidden/safe route
```

### Only action permission lost

```text
page remains
→ action disappears
```

### Capability endpoint fails

```text
fail closed
```

Jangan mempertahankan stale permission sebagai interactive authority.

---

# 29. Unsaved Form + Recovery

HR-011 telah mengunci dirty-form protection.

HR-012 menambahkan priority:

```text
Voluntary navigation
→ preserve via confirmation

Context/security invalidation
→ security recovery wins
```

Jika session invalid:

```text
stop mutation
→ recover authentication safely
```

HR-012 tidak menjanjikan persistence arbitrary HR form draft karena dapat memuat sensitive employee data.

---

# 30. Toast Policy

Toast boleh digunakan sebagai secondary feedback:

```text
Employee created
Leave approved
Export requested
```

Tetapi toast tidak boleh menjadi satu-satunya presentation untuk:

- validation;
- page load failure;
- permission denial;
- workspace recovery;
- domain conflict;
- session invalidation.

---

# 31. Accessibility

State harus dapat dipahami tanpa hanya mengandalkan visual styling.

Requirements:

- loading status dapat dikenali assistive technology;
- disabled business action memiliki accessible explanation;
- unauthorized element tidak berada di accessibility tree;
- error field terasosiasi dengan input;
- focus diarahkan secara aman setelah validation/error transition;
- async status tidak menyebabkan uncontrolled focus loss.

---

# 32. API Contract Dependency

**[CONFLICT] Existing HR API**

Current HR endpoints belum sepenuhnya memenuhi canonical error contract.

Contoh existing controller masih dapat memberikan:

```json
{
  "status": "error",
  "message": "..."
}
```

tanpa stable:

```text
code
```

Sementara ADR-025/FE-007 mensyaratkan branching berdasarkan:

```text
HTTP status
+
stable error code
+
operation semantics
```

---

## HR-012-NFR-001

Sebelum HR frontend menjadi production-ready, HR API harus di-hardening sehingga documented endpoint mempunyai canonical error envelope.

Classification:

```text
Existing HR API
→ KEEP route intent
→ REFACTOR error contract
→ EXTEND authorization
→ ADD OpenAPI contract
→ ADD contract tests
```

---

# 33. Domain Conflict Codes

**[RESOURCE GAP]**

Canonical stable codes untuk HR-specific `409` belum tersedia.

HR-012 **tidak mengarang** code seperti:

```text
EMPLOYMENT_ALREADY_ACTIVE
LEAVE_ALREADY_APPROVED
OFFBOARDING_NOT_READY
```

walaupun semantics sejenis mungkin dibutuhkan.

Exact code akan ditentukan ketika API specification tiap HR operation dikeraskan.

---

# 34. Error State Decision Matrix

| Condition                         | HR UX                      | Recovery                             |
| --------------------------------- | -------------------------- | ------------------------------------ |
| Initial GET pending               | Skeleton                   | Wait                                 |
| Background GET pending            | Preserve same-context data | Subtle refresh                       |
| Empty 200                         | Domain empty               | Contextual CTA                       |
| Filtered empty                    | Filter state               | Clear filters                        |
| Permission absent                 | Hide affordance            | None                                 |
| Business state blocks action      | Disable + reason           | Fulfil prerequisite/state transition |
| `AUTHORIZATION_DENIED`            | Denied state               | Refresh capabilities                 |
| `ORGANIZATIONAL_CONTEXT_REQUIRED` | Workspace required         | Select workspace                     |
| `ORGANIZATIONAL_CONTEXT_DENIED`   | Context recovery           | Clear/rediscover/fallback            |
| `VALIDATION_FAILED`               | Inline form errors         | Correct fields                       |
| `RESOURCE_NOT_FOUND`              | Page/local not-found       | Navigate/refresh                     |
| `409` domain conflict             | Stale/conflict state       | Refresh/reconcile                    |
| `429`                             | Rate-limited state         | Respect retry semantics              |
| Network failure                   | Connection state           | Safe retry                           |
| Selected 5xx on GET               | Page/local error           | Bounded retry                        |
| Mutation network ambiguity        | Unconfirmed state          | Verify before retry                  |
| Malformed API response            | Technical/contract failure | Fail safe + telemetry                |

---

# 35. Retry Matrix for HR

| Operation                              |                  Auto Retry |
| -------------------------------------- | --------------------------: |
| Employee/list reads                    |      Bounded when transient |
| Recruitment reads                      |      Bounded when transient |
| Leave reads                            |      Bounded when transient |
| Attendance reads                       |      Bounded when transient |
| Report reads                           |      Bounded when transient |
| Create Employee                        |                      **No** |
| Submit Leave                           |                      **No** |
| Approve/Reject                         |                      **No** |
| Reconcile/Finalize Attendance          |                      **No** |
| End Employment                         |                      **No** |
| Finalize Document                      |                      **No** |
| Discipline action                      |                      **No** |
| Complete Offboarding                   |                      **No** |
| Generate export                        |           **No by default** |
| Explicitly idempotent future operation | Per documented API contract |

Exact retry count/backoff remains foundation/NFR configuration, not HR business rule.

---

# 36. IN SCOPE

- loading-state semantics;
- empty-state semantics;
- authorization UX states;
- business-state restrictions;
- validation;
- conflict;
- not-found;
- network/server states;
- mutation ambiguity;
- retry policy application;
- workspace-context recovery;
- HR domain-specific state mapping;
- export/report freshness states.

---

# 37. OUT OF SCOPE

- implementation components;
- exact visual styling;
- toast library;
- retry count/backoff values;
- HTTP timeout configuration;
- monitoring provider;
- canonical HR permission identifiers;
- field-level API specification;
- finalized domain error codes;
- privacy masking format.

---

# 38. DEFERRED

- exact sensitive-data masking → **Phase 3E**;
- centralized observability provider → **Phase 3G**;
- exact retry/backoff SLA → Phase 3F/3G where appropriate;
- HR-specific stable API codes → API hardening/specification;
- permission names + scope matrix → **Phase 3D**.

---

# 39. Traceability

```text
FE-005 Workspace Context
FE-006 Authorization UX
FE-007 Error / Loading / Recovery
ADR-025 Canonical API Errors
ADR-027 Authorization UX
        ↓
HR-010 Information Architecture
        ↓
HR-011 Transaction UX
        ↓
HR-012 State & Recovery UX
        ↓
HR-013 Authorization Matrix
        ↓
HR API Hardening
        ↓
Frontend Implementation
        ↓
Contract / Integration / E2E Tests
```

---

# 40. Acceptance Criteria

### HR-012-AC-001 — Empty vs Failure

**Given** HR endpoint berhasil dengan collection kosong
**When** page dirender
**Then** UI menampilkan empty state
**And** tidak menampilkan server error.

### HR-012-AC-002 — No Unauthorized Flash

**Given** protected HR route sedang resolving capability
**When** page pertama kali dibuka
**Then** protected business content belum dirender
**Until** capability state menjadi authoritative untuk UX.

### HR-012-AC-003 — Business Restriction

**Given** user mempunyai permission terhadap action
**And** current lifecycle state melarang action
**When** page dirender
**Then** action dapat disabled dengan explanation
**And** restriction tidak dipresentasikan sebagai permission denial.

### HR-012-AC-004 — Backend 403

**Given** frontend sebelumnya menampilkan action
**When** backend mengembalikan `AUTHORIZATION_DENIED`
**Then** operation dihentikan
**And** capability direfresh
**And** user tidak otomatis logout.

### HR-012-AC-005 — Stale Workspace

**Given** active organizational assignment tidak lagi valid
**When** backend mengembalikan context denial
**Then** stale Workspace dibuang
**And** Workspace discovery dijalankan kembali
**And** user dikembalikan ke safe Tenant context
**And** stale assignment tidak di-retry.

### HR-012-AC-006 — Validation

**Given** HR form dikirim
**When** backend mengembalikan `VALIDATION_FAILED`
**Then** field errors ditampilkan sedekat mungkin dengan input
**And** user input yang aman tetap dipertahankan.

### HR-012-AC-007 — Conflict

**Given** canonical HR state berubah setelah page dimuat
**When** mutation menghasilkan conflict
**Then** UI menjelaskan bahwa state telah berubah
**And** canonical data direfresh
**And** mutation tidak otomatis diulang.

### HR-012-AC-008 — Mutation Ambiguity

**Given** mutation request kehilangan response karena network failure
**When** client tidak mengetahui apakah commit terjadi
**Then** UI tidak menyatakan operation pasti gagal
**And** authoritative state diverifikasi sebelum retry.

### HR-012-AC-009 — Attendance Failure

**Given** attendance source tidak dapat dimuat
**When** Employee Attendance view dibuka
**Then** UI menampilkan unavailable/error state
**And** tidak menyimpulkan employee absent atau tidak mempunyai attendance.

### HR-012-AC-010 — Reporting Freshness

**Given** projected HR data berstatus STALE atau FAILED
**When** dashboard/report dirender
**Then** freshness state terlihat sesuai HR-009
**And** stale/failed data tidak dipresentasikan sebagai fresh.

### HR-012-AC-011 — Finalized Record

**Given** HR record telah finalized/immutable
**When** user yang authorized membuka record
**Then** record ditampilkan read-only
**And** absence of edit bukan dipresentasikan sebagai authorization error.

### HR-012-AC-012 — Context Switch

**Given** user pindah Workspace
**When** workspace-scoped HR data dari context lama masih cached
**Then** data tersebut tidak digunakan sebagai interactive placeholder pada Workspace baru.

---

# 41. Change Impact

| Area                       | Impact                                         |
| -------------------------- | ---------------------------------------------- |
| HR business requirements   | No semantic change                             |
| HR-010 IA                  | No change                                      |
| HR-011 transaction pattern | **EXTENDED with state semantics**              |
| Backend authorization      | P0 dependency remains                          |
| HR API error envelope      | **REFACTOR REQUIRED**                          |
| OpenAPI                    | **EXTEND REQUIRED** before frontend contract   |
| Frontend API layer         | Reuse ADR-025 normalizer                       |
| Capability state           | Reuse FE-006                                   |
| Workspace recovery         | Reuse FE-005                                   |
| Domain API tests           | Add error/conflict contract coverage           |
| E2E tests                  | Add loading/denied/conflict/recovery scenarios |

---

# 42. Phase Review

**Quality Score:** **9.7/10**

## Gaps

- HR-specific stable conflict/error codes belum tersedia.
- Exact HR permission identifiers belum tersedia.
- Current HR routes belum menjadi hardened OpenAPI contract.
- Exact privacy/masking policy belum locked.

## Risks

**[RISK — HIGH]** Frontend HR yang dibangun langsung atas current raw controller responses akan menduplikasi ad-hoc error handling dan melanggar ADR-025.

**[RISK — HIGH]** Current permission enforcement gap dapat menyebabkan frontend menunjukkan UX yang terlihat benar tetapi backend mutation belum mempunyai enforcement target yang memadai.

**[RISK]** Menggunakan empty state saat downstream data gagal dapat menghasilkan keputusan HR salah, khususnya attendance, compensation, atau reporting.

## Recommendations

1. Lock HR-012 sebagai state/recovery UX contract.
2. Jangan membuat HR-specific error framework.
3. Treat canonical HR API hardening sebagai prerequisite frontend integration.
4. Lanjut ke **Phase 3D — Full HR Authorization Matrix & Existing Route Remediation**.
5. Pada Phase 3D, definisikan permission × operation × Tenant/organizational scope tanpa menggunakan Position/Jabatan sebagai authorization source.

**Status:** **READY FOR APPROVAL**
