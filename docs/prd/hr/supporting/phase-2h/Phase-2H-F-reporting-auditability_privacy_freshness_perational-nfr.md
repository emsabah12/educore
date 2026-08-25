# Phase 2H-F — Reporting Auditability, Privacy, Freshness & Operational NFR

## 1. Status Fase Sebelumnya

**2H-E — Government Export Boundary: APPROVED / LOCKED.**

Dengan demikian baseline Phase 2H sekarang mempertahankan:

```text
Canonical Data
   ↓
Authorized Reporting
   ↓
Direct Query / Projection
   ↓
Dashboard / Report
   ↓
Frozen Export
```

serta government integration tetap berbasis **versioned mapping + validation + frozen export**, bukan direct synchronization tanpa kontrak resmi. Handoff juga secara eksplisit mensyaratkan export auditability, reporting security/privacy, serta read-model freshness strategy.

---

# 2. Project Resource Check

Repository tetap pada baseline:

```text
26b475b695aa4511064b1410db03d1f0c8bdd6ce
```

Resource operasional yang sudah tersedia:

| Existing Resource                | Assessment                            |
| -------------------------------- | ------------------------------------- |
| Core `DatabaseAuditTrailService` | **KEEP / REUSE**, tetapi supplemental |
| `audit_logs` append-only table   | **KEEP**                              |
| Audit metadata sanitization      | **KEEP + EXTEND POLICY**              |
| `BaseTenantAwareJob`             | **KEEP / REUSE**                      |
| `RestoreTenantContext`           | **KEEP / REUSE**                      |
| Queue failure watchdog           | **KEEP + HARDEN USAGE**               |
| Database-backed queue            | **KEEP initially**                    |
| Failed jobs persistence          | **KEEP**                              |
| Private local filesystem root    | **KEEP as baseline**                  |
| Public filesystem                | **DO NOT USE for HR exports**         |
| Database cache                   | **KEEP AVAILABLE, NOT REQUIRED**      |
| Laravel logging                  | **KEEP / CONFIGURE per environment**  |

---

# 3. Important Existing Risks

## [RISK] Core Audit adalah fail-open

`DatabaseAuditTrailService` menangkap kegagalan penyimpanan audit lalu hanya menulis critical application log.

Artinya:

```text
Business transaction
      ✓

Core audit insert
      ✗
```

business transaction tetap dapat berhasil.

Ini sesuai dengan handoff yang sudah mengidentifikasi Core Audit sebagai **best-effort/fail-open**.

### Dampak

Core Audit **tidak boleh menjadi satu-satunya evidence** untuk proses seperti:

- frozen export generation;
- government export lifecycle;
- sensitive asynchronous report;
- mapping/version yang digunakan pada export.

---

# 4. Audit vs Domain Evidence vs Application Log

Saya rekomendasikan tiga lapisan yang jelas.

```text
┌───────────────────────┐
│ Transactional Evidence│
│ ExportRun / Projection│
└──────────┬────────────┘
           │
           ├─────────────┐
           ▼             ▼
     Core Audit      App / Ops Log
```

### 1. Transactional evidence

Untuk data yang merupakan bagian dari business/operational lifecycle.

Contoh:

```text
GovernmentExportRun
ReportExportRun
ProjectionBuild state
```

### 2. Core Audit

Menjawab:

> siapa melakukan aksi apa dan kapan?

### 3. Application/operational logging

Menjawab:

> apa yang salah secara teknis?

## HRR-NFR-010

> Ketiga jenis evidence tersebut tidak boleh diperlakukan sebagai hal yang sama.

---

# 5. Export Auditability

## HRR-AUD-001 — Export Run Is Transactional Evidence

Setiap sensitive/government export harus mempunyai execution record yang persisten.

Conceptual:

```text
export_run_id
tenant_id
target/report_type
effective_scope
requested_by
definition_version
mapping_version
source_as_of
requested_at
generated_at
status
artifact_reference
```

Ini adalah evidence utama lifecycle export.

Core Audit tetap mencatat event seperti:

```text
hr.reporting.export.requested
hr.reporting.export.generated
hr.reporting.export.downloaded
hr.reporting.export.failed
```

tetapi tidak menggantikan `ExportRun`.

---

# 6. Sensitive View Audit

Tidak semua dashboard view perlu menghasilkan immutable audit row.

Jika setiap card headcount menulis audit:

```text
page refresh × many users
→ huge low-value audit volume
```

Saya rekomendasikan tiering:

| Activity                           | Audit expectation                               |
| ---------------------------------- | ----------------------------------------------- |
| S1 aggregate view                  | Operational/security log bila diperlukan        |
| S2 aggregate                       | Standard application access controls            |
| S3 individual sensitive drill-down | Audit recommended                               |
| S4 compensation/discipline access  | **Audit required**                              |
| Export generation                  | **Audit required**                              |
| Export download                    | **Audit required**                              |
| Government export                  | **Audit required + transactional run evidence** |

---

# 7. Audit Metadata Privacy

Existing audit sanitizer hanya mengenali secret-like keys seperti:

```text
password
access_token
api_key
client_secret
token
```

Ia **tidak otomatis memahami HR PII**.

Karena itu:

## HRR-PRIV-010

HR Reporting tidak boleh mengirim metadata seperti:

```text
employee_name
salary
bank_account
disciplinary_narrative
exit_interview
document_content
```

ke generic audit trail hanya karena technically possible.

Preferred metadata:

```text
report_type
export_run_id
scope_type
organization_id
record_count
definition_version
status
```

---

# 8. Queue Payload Privacy

Ada temuan penting.

Existing `QueueWatchdogListener` dapat memasukkan:

```text
input_payload
```

dari failed `BaseTenantAwareJob` ke Core Audit.

Ini aman hanya jika payload job memang bersih.

## HRR-PRIV-011

Reporting/export jobs **tidak boleh membawa dataset atau sensitive HR payload** sebagai serialized queue payload.

Preferred:

```text
tenant_id
operator_id
export_run_id
projection_run_id
```

lalu job mengambil data sesuai authorized/persisted run definition.

Bukan:

```text
payload:
[
  employee names,
  salaries,
  discipline records,
  document metadata...
]
```

### Keuntungan

- queue storage tidak menjadi shadow sensitive database;
- failed jobs tidak menyimpan HR dataset;
- watchdog audit tidak membocorkan PII;
- retry lebih aman;
- payload lebih kecil.

---

# 9. Queue Transaction Boundary

Current queue config memakai:

```text
after_commit = false
```

secara default.

Ini tidak harus kita ubah secara global, tetapi:

## HRR-NFR-011

Jika projection/export job dipicu oleh transaction yang baru membuat source/run state:

```text
DB Transaction
    ↓
COMMIT
    ↓
Dispatch async job
```

Job tidak boleh diproses sebelum source transaction committed.

**[REKOMENDASI]** gunakan explicit after-commit behavior pada use case terkait, bukan mengubah seluruh queue platform tanpa impact analysis.

---

# 10. Freshness Contract

Kita lock tiga semantics dari 2H-C.

## DIRECT

```text
freshness_mode = LIVE
generated_at = T
```

Data dibaca dari canonical persistence ketika request dijalankan.

---

## PROJECTED

```text
freshness_mode = PROJECTED
source_as_of = T1
generated_at = T2
projection_state = READY | STALE | FAILED
```

---

## FROZEN

```text
freshness_mode = FROZEN
source_as_of = T
definition_version = V
```

Digunakan khususnya untuk reproducible exports.

---

# 11. Freshness SLA Framework

Saya **tidak merekomendasikan mengarang angka SLA** seperti 5 menit atau 1 jam sekarang.

Sebaliknya setiap projection nantinya wajib mendefinisikan:

```text
freshness_target
stale_after
refresh_strategy
rebuild_strategy
failure_behavior
```

## HRR-NFR-012

SLA freshness harus didefinisikan **per projection/use case**, bukan satu angka global untuk seluruh HR.

Contoh:

- executive workforce trend mungkin tolerant terhadap eventual consistency;
- operational leave view dapat membutuhkan current truth;
- export harus frozen.

---

# 12. Stale Data Behavior

Jika projection melewati `stale_after`:

```text
READY
 ↓
STALE
```

UI/API harus mengungkap status tersebut.

Tidak boleh:

```text
projection 3 jam lama
→ tampil seperti LIVE
```

Response conceptual:

```text
freshness_mode: PROJECTED
source_as_of: ...
status: STALE
```

---

# 13. Failed Projection Behavior

Jika refresh gagal:

```text
last valid projection
+
new refresh FAILED
```

ada dua kemungkinan yang sah:

### A. Serve last-known result sebagai STALE

Jika business use case mengizinkan.

### B. Fail report

Jika stale result dapat menyesatkan.

Keputusan harus per report.

## HRR-NFR-013

Jangan otomatis mengubah failure menjadi:

```text
0
empty dataset
```

karena itu memberikan business meaning yang salah.

---

# 14. Rebuild & Reconciliation

Persisted projection wajib mempunyai:

```text
Incremental refresh
        +
Full rebuild
        +
Reconciliation
```

## HRR-NFR-014

Projection dapat dianggap recoverable jika:

```text
delete projection
→ rebuild canonical source
→ equivalent result
```

untuk definition/source version yang sama.

---

# 15. Backup Strategy

Ini menghasilkan boundary yang sederhana.

### Canonical domain data

Mengikuti backup strategy database platform.

### Rebuildable projection

**Tidak perlu dianggap sebagai primary backup asset.**

Karena:

```text
lost projection
→ rebuild
```

### Frozen export evidence

Metadata export/run harus mengikuti retention/backup policy jika secara bisnis diperlukan sebagai evidence.

### Export artifact

Retention-nya terpisah dari metadata.

Contoh:

```text
ExportRun metadata
still exists

artifact
already expired/deleted
```

adalah state yang valid.

---

# 16. Retention Classes

Belum ada retention policy HR yang authoritative.

Maka saya tidak akan menentukan angka tahun/bulan.

Kita dapat mengunci klasifikasi:

| Resource                       | Retention behavior           |
| ------------------------------ | ---------------------------- |
| Projection                     | Rebuildable / purgeable      |
| Cache                          | Ephemeral                    |
| Operational log                | Platform retention           |
| Core Audit                     | Audit retention policy       |
| Export run metadata            | Evidence retention           |
| Export artifact                | Sensitive artifact retention |
| Government mapping definitions | Version history required     |

## [RESOURCE GAP]

Repository belum memiliki policy retention terpusat yang cukup untuk kita jadikan authority bagi HR Reporting.

**[OPEN DECISION]** durasi retention.

---

# 17. Artifact Storage

Existing default local disk mengarah ke:

```text
storage/app/private
```

sedangkan public disk terpisah.

### HRR-SEC-010

HR export harus menggunakan **private storage**, tidak pernah public filesystem.

Tidak boleh:

```text
/storage/hr/export.csv
```

melalui public symlink.

Access flow:

```text
User request
   ↓
Authorize
   ↓
Resolve ExportRun
   ↓
Validate tenant/scope
   ↓
Serve private artifact
```

---

# 18. Download Authorization

Artifact URL/reference bukan authorization.

```text
export_run_id
artifact_path
signed token
```

semuanya hanya locator.

## HRR-SEC-011

Setiap download harus melakukan server-side authorization terhadap:

```text
actor
tenant
export permission
effective scope
export ownership/policy
artifact state
```

---

# 19. Artifact Expiration

Jika artifact sudah dihapus menurut retention policy:

```text
ExportRun = exists
Artifact = expired
```

UI harus menampilkan:

```text
EXPIRED
```

bukan:

```text
404 unknown export
```

jika user memang authorized melihat run history.

Regeneration harus menghasilkan **run baru**, karena data/as-of dapat berbeda.

---

# 20. Logging Policy

Application logs tidak boleh menjadi alternate HR data store.

## HRR-PRIV-012

Jangan log:

- full report response;
- export contents;
- Person data;
- salary;
- disciplinary narrative;
- document contents;
- raw request payload untuk sensitive reports.

Allowed operational context:

```text
request_id
tenant_id
report_type
export_run_id
projection_type
duration
result_count
status
error_code
```

---

# 21. Environment Logging Gap

Repository `.env.example` saat ini menggunakan:

```text
LOG_LEVEL=debug
LOG_STACK=single
```

Ini hanya baseline development configuration dan **tidak boleh diasumsikan sebagai production policy**.

**[OPEN DECISION]**

- production log level;
- centralized log sink;
- retention;
- alert routing.

Tidak perlu kita desain logging platform baru dalam HR.

---

# 22. Observability Model

Saya rekomendasikan empat kelompok operational telemetry.

### Request metrics

```text
report request count
latency
failure count
```

### Projection metrics

```text
last successful refresh
source watermark
refresh duration
state
rebuild failure
```

### Export metrics

```text
queue duration
generation duration
status
artifact generation failure
```

### Security metrics

```text
authorization denial
sensitive export attempts
download failures
```

Tetapi telemetry tidak boleh membawa PII.

---

# 23. Correlation / Traceability

Report/export operation sebaiknya memiliki correlation identifier.

Contoh:

```text
request_id
export_run_id
projection_run_id
```

sehingga troubleshooting dapat menghubungkan:

```text
API
↓
queue
↓
projection/export
↓
audit
↓
operational log
```

tanpa mencari berdasarkan nama employee atau sensitive field.

---

# 24. Performance Strategy

Kita tetap menghindari premature optimization.

## HRR-PERF-001

Urutan optimasi:

```text
Correct Query
→ Appropriate Index
→ Measure
→ Optimize Query
→ Projection if justified
→ Cache if justified
```

Bukan:

```text
Cache everything
→ Redis everything
→ materialize everything
```

---

# 25. Detail Query Must Be Bounded

Semua list/detail reporting harus mendukung pagination atau bounded result.

Tidak boleh endpoint interactive seperti:

```text
GET all employees
GET all attendance history ever
GET all compensation facts
```

tanpa bound.

Candidate constraints:

```text
pagination
date range
organizational scope
report-specific filters
```

Exact maximum page/date range:

**[OPEN DECISION]** berdasarkan profiling/use case.

---

# 26. Large Exports

Interactive request tidak boleh dipaksa menunggu export besar.

Baseline conceptual:

```text
Small bounded report
→ synchronous allowed

Large/sensitive export
→ persistent ExportRun
→ async job
→ READY
```

**[OPEN DECISION]** threshold yang menentukan small vs large.

Threshold harus berdasarkan:

- record volume;
- payload size;
- execution time;
- infrastructure capacity.

---

# 27. Caching

Repository mempunyai database cache dan opsi Redis, tetapi belum ada alasan untuk menambah cache khusus HR.

## HRR-PERF-002

Cache hanya ditambahkan setelah profiling menunjukkan kebutuhan.

Jika digunakan, cache key wajib minimal memasukkan:

```text
tenant
authorized scope
report/metric
definition version
filter hash
```

agar tenant/scope tidak tercampur.

---

# 28. Cache Failure

Cache harus bersifat disposable:

```text
cache lost
→ report still correct
```

Jika hilangnya cache menyebabkan business data hilang:

> komponen tersebut bukan cache lagi.

---

# 29. Security Failure Model

Reporting harus fail-closed untuk authorization.

```text
cannot resolve scope
→ DENY

permission service uncertain
→ DENY
```

Berbeda dengan projection:

```text
projection refresh fails
→ source transaction remains valid
```

Ini mempertahankan distinction:

```text
Security = fail closed
Reporting projection = failure isolated
Audit supplemental = fail open
```

---

# 30. Privacy Failure Model

Jika sistem tidak dapat memastikan apakah field sensitive boleh ditampilkan:

```text
do not disclose
```

bukan:

```text
show then hide later
```

## HRR-SEC-012

Privacy decision harus default-deny untuk S3/S4 data.

---

# 31. Government Export Operational Security

Karena government export adalah S4:

minimum requirement:

```text
explicit target-specific permission
private artifact storage
frozen dataset
versioned mapping
validation
generation audit
download audit
source_as_of
artifact expiration policy
```

Dan tetap:

```text
Generated
≠
Submitted
≠
Accepted
```

seperti yang dikunci pada 2H-E.

---

# 32. Failure Taxonomy

Saya rekomendasikan reporting-level error categories:

```text
AUTHORIZATION_FAILED
SOURCE_UNAVAILABLE
SOURCE_DATA_INVALID
PROJECTION_STALE
PROJECTION_FAILED
EXPORT_VALIDATION_FAILED
EXPORT_GENERATION_FAILED
ARTIFACT_UNAVAILABLE
INTERNAL_REPORTING_ERROR
```

External government adapter dapat menambahkan taxonomy 2H-E.

---

# 33. Retry Policy

Tidak semua failure layak retry.

### Retryable

Contoh:

```text
temporary storage failure
temporary infrastructure error
transient source timeout
```

### Non-retryable

```text
mapping missing
required data missing
authorization denied
invalid definition
```

## HRR-NFR-015

Business/data validation error tidak boleh di-retry berulang seolah infrastructure error.

---

# 34. Current Queue Baseline

Existing `BaseTenantAwareJob` mempunyai:

```text
tries = 3
backoff = 30
```

Ini dapat dipakai sebagai baseline platform, tetapi **bukan SLA HR yang kita lock**.

Jika future export membutuhkan retry berbeda, harus berdasarkan failure characteristics.

Kita tidak perlu membuat queue framework baru.

---

# 35. Queue Failure Evidence

Existing Core sudah mempunyai:

```text
queue.job.failed_permanently
```

melalui `QueueWatchdogListener`.

### Classification

**KEEP / REUSE**

Tetapi reporting-specific state juga perlu diupdate:

```text
ExportRun
PROCESSING
   ↓
FAILED
```

Jangan hanya mengandalkan generic queue audit untuk mengetahui state bisnis export.

---

# 36. Recovery Behavior

### Projection failure

```text
retry
→ if permanent
FAILED
→ operator/system rebuild
```

### Export generation failure

```text
ExportRun = FAILED
artifact = none
```

User dapat memulai export baru setelah penyebab diperbaiki.

### Artifact loss

```text
Run metadata = retained
Artifact = unavailable/expired
```

Tidak mengubah canonical source.

---

# 37. Availability Boundary

Kegagalan reporting tidak boleh membuat core HR transaction unavailable.

Contoh:

```text
Dashboard projection broken
```

tidak boleh mencegah:

```text
Employment update
Leave approval
Onboarding
Offboarding
```

## HRR-NFR-016

Reporting adalah downstream consumer, bukan synchronous dependency untuk canonical command.

---

# 38. Data Integrity Boundary

Sebaliknya, jika canonical transaction gagal:

reporting **tidak boleh mengarang result sementara**.

```text
Canonical write FAILED
        ↓
No reporting update
```

Tidak boleh memperbarui projection terlebih dahulu lalu berharap canonical source menyusul.

---

# 39. Deployment & Rollback

Read-model change harus aman terhadap rolling deployment.

Untuk definition/projection version baru:

```text
V1 active
   ↓
deploy V2 builder
   ↓
build/reconcile V2
   ↓
switch reader
   ↓
retire V1 when safe
```

Lebih aman daripada mutation in-place jika semantic metric berubah.

---

# 40. Reporting Definition Rollback

Karena metric definitions versioned:

```text
Metric v2 problem
      ↓
reader can revert to v1
```

selama source dan projection compatibility masih tersedia.

Historical exports tetap menunjuk definition version yang digunakan saat generate.

---

# 41. NFR Baseline

| ID              | Requirement                                                                                             |
| --------------- | ------------------------------------------------------------------------------------------------------- |
| **HRR-NFR-010** | Audit, transactional evidence, dan operational logs harus dipisahkan.                                   |
| **HRR-NFR-011** | Async reporting work yang berasal dari transaction hanya boleh berjalan setelah source state committed. |
| **HRR-NFR-012** | Setiap projected report mempunyai explicit freshness policy.                                            |
| **HRR-NFR-013** | Stale/failure tidak boleh diubah menjadi zero atau current data.                                        |
| **HRR-NFR-014** | Persisted projection harus rebuildable dan reconcilable.                                                |
| **HRR-NFR-015** | Retry harus membedakan transient failure dari validation/business failure.                              |
| **HRR-NFR-016** | Reporting failure tidak boleh membatalkan canonical HR operation.                                       |
| **HRR-NFR-017** | Sensitive artifacts harus private dan server-authorized.                                                |
| **HRR-NFR-018** | Logs/audit tidak boleh menyimpan raw sensitive report payload.                                          |
| **HRR-NFR-019** | Reporting detail/query harus bounded dan tenant/scope aware.                                            |
| **HRR-NFR-020** | Optimization harus evidence-driven melalui measurement/profiling.                                       |

---

# 42. Operational Classification

| Existing Component             | Decision                                     |
| ------------------------------ | -------------------------------------------- |
| Core Audit                     | **KEEP / REUSE as supplemental audit**       |
| Audit as sole export evidence  | **DO NOT USE**                               |
| `BaseTenantAwareJob`           | **KEEP / REUSE**                             |
| Queue watchdog                 | **KEEP / HARDEN PAYLOAD POLICY**             |
| Database queue                 | **KEEP initially**                           |
| Global queue config            | **KEEP; explicit after-commit where needed** |
| Private local storage          | **KEEP baseline**                            |
| Public storage for HR export   | **DO NOT USE**                               |
| Database cache                 | **KEEP available / defer use**               |
| Redis introduction             | **DEFER until justified**                    |
| Generic observability platform | **DO NOT CREATE inside HR**                  |
| Projection backup              | **REBUILD instead**                          |

---

# 43. Open Decisions

Belum perlu dipaksakan sekarang:

**[OPEN DECISION]**

1. exact freshness SLA per projection;
2. dashboard latency target;
3. synchronous-vs-async export threshold;
4. page size/max date range;
5. projection refresh schedule;
6. production log level;
7. log/audit retention;
8. export artifact retention;
9. backup retention;
10. privacy cohort threshold;
11. centralized monitoring/alert provider;
12. dedicated reporting queue;
13. storage provider untuk production;
14. exact incident escalation policy.

Tidak ada yang menjadi blocker architecture.

---

# 44. Change Impact terhadap Existing Repository

Satu issue existing menjadi lebih jelas.

## [RISK] Queue Watchdog Payload

Current watchdog menyalin `BaseTenantAwareJob.payload` ke audit metadata saat permanent failure.

Untuk existing generic jobs ini belum tentu bermasalah, tetapi **HR Reporting tidak boleh memasukkan sensitive dataset ke field tersebut**.

Saya merekomendasikan terlebih dahulu:

```text
HR reporting job payload
→ identifiers only
```

daripada langsung melakukan broad refactor pada Core watchdog.

Jika kelak platform menggunakan queue untuk banyak sensitive domains, barulah Core watchdog sanitizer perlu ditinjau sebagai platform-level change.

---

# 45. Target Operational Flow

```text
Authorized Request
       │
       ▼
 Reporting Use Case
       │
  ┌────┴──────────┐
  │               │
  ▼               ▼
DIRECT          ASYNC
  │               │
  │          Persist Run
  │               │
  │             COMMIT
  │               │
  │          Tenant-aware Job
  │               │
  │        ┌──────┴──────┐
  │        ▼             ▼
  │    Projection      Export
  │        │             │
  │        ▼             ▼
  │    Reconcile     Private Artifact
  │                      │
  └──────────┬───────────┘
             ▼
          Response
             │
     freshness / as-of
             │
        Core Audit
        (supplemental)
```

---

# 46. Traceability

Contoh government export:

```text
Government Compliance Need
        ↓
GOV-ARCH-001
Adapter-based export
        ↓
Frozen Export
        ↓
HRR-NFR-011
After committed source state
        ↓
HRR-AUD-001
Transactional ExportRun
        ↓
HRR-NFR-017
Private artifact
        ↓
HRR-PRIV-011
No sensitive queue payload
        ↓
Audit generation/download
        ↓
Recovery + retention policy
```

---

# 47. Reviewer Mode — Phase 2H-F

**Quality Score: 9.7/10**

### Gaps

**[RESOURCE GAP]** Belum ada authoritative retention policy, production observability standard, atau explicit reporting performance SLA pada repository.

### Risks

**[RISK — HIGH]** Sensitive queue payload dapat tersalin ke failed-job/audit infrastructure jika job dirancang membawa data mentah.

**[RISK]** Core Audit bersifat fail-open sehingga tidak memadai sebagai satu-satunya export evidence.

**[RISK]** Async job yang berjalan sebelum source transaction commit dapat membaca state yang belum valid.

**[RISK]** Export artifact menjadi jalur data exfiltration jika memakai public storage atau download tidak melakukan reauthorization.

**[RISK]** Premature caching/projection akan menambah complexity tanpa data performance nyata.

### Recommendations

Baseline yang saya rekomendasikan dikunci:

> **Transactional run evidence + supplemental Core Audit + privacy-safe operational logs; private artifacts; identifier-only queue payloads; explicit freshness semantics; rebuildable projections; performance optimization hanya setelah measurement.**

### Status

**READY FOR APPROVAL — MINOR OPERATIONAL POLICIES DEFERRED**

Jika 2H-F disetujui, berikutnya adalah **Phase 2H-G — Final HR Reporting Integration Review & Phase Closure**. Pada 2H-G kita tidak menambah desain besar baru; kita akan melakukan consistency check menyeluruh dari **2H-A sampai 2H-F**, change-impact terhadap HR-001–HR-008/Core, traceability, daftar locked/open decisions, serta menentukan apakah Phase 2H benar-benar layak ditutup atau masih memiliki critical gap.
