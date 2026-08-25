# HR-016 — HR Logging, Monitoring, Deployment & Rollback Readiness

**Version:** 0.1 Draft
**Phase:** 3G — Logging, Monitoring, Deployment & Rollback
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
**Depends On:** HR-001–HR-015, ADR-020, ADR-025, ADR-030, ADR-031, FE-008

---

# 1. Objective

HR-016 mendefinisikan operational readiness HR agar production deployment dapat:

```text
observe
detect
diagnose
deploy
verify
roll forward
roll back where safe
recover
```

tanpa:

- membocorkan sensitive HR data;
- menjadikan log sebagai source of truth;
- mengorbankan tenant isolation;
- menganggap database rollback selalu aman;
- mengasumsikan frontend/backend/worker deploy secara simultan.

Target:

```text
Immutable Release
      ↓
Controlled Deployment
      ↓
Health Verification
      ↓
Operational Monitoring
      ↓
Safe Roll Forward / Rollback
```

---

# 2. Resource Audit

Repository HEAD tetap:

```text
26b475b695aa4511064b1410db03d1f0c8bdd6ce
```

Tidak ada delta terhadap handoff HR-009.

Existing operational foundation:

| Area                                | Current State                            | Classification        |
| ----------------------------------- | ---------------------------------------- | --------------------- |
| Laravel `/up`                       | Existing framework health surface        | **KEEP**              |
| `/api/v1/core/health`               | Existing Core dependency health endpoint | **REFACTOR**          |
| Database health check               | Existing                                 | **KEEP + HARDEN**     |
| Storage read/write health check     | Existing                                 | **KEEP + ADAPT**      |
| Core Queue Watchdog                 | Existing                                 | **REFACTOR**          |
| Laravel logging channels            | Existing                                 | **KEEP + CONFIGURE**  |
| Core Audit                          | Existing / fail-open                     | **KEEP supplemental** |
| Frontend observability ADR-031      | Accepted                                 | **KEEP / REUSE**      |
| Frontend immutable artifact         | Locked                                   | **KEEP / REUSE**      |
| Backend request correlation         | Not implemented                          | **ADD**               |
| Centralized backend observability   | Not implemented                          | **RESOURCE GAP**      |
| CI/CD pipeline                      | Not found                                | **RESOURCE GAP**      |
| Worker supervisor/deployment config | Not found                                | **RESOURCE GAP**      |
| Deployment runbook                  | Not found                                | **RESOURCE GAP**      |
| Rollback runbook                    | Not found                                | **RESOURCE GAP**      |
| Backup/restore runbook              | Not found                                | **RESOURCE GAP**      |

---

# 3. Critical Finding — Health Information Leakage

Current:

```text
GET /api/v1/core/health
```

tidak memiliki application authorization middleware.

`SystemHealthService` currently includes dependency exception details in health response, for example conceptually:

```text
Database connection failed: <raw dependency message>
```

### [RISK — HIGH]

Public dependency health response dapat membocorkan:

- database details;
- storage details;
- driver information;
- infrastructure error information.

### HR-016-BR-001

Public/probe health output must contain only operationally safe status.

Example:

```text
database: UP / DOWN
storage: UP / DOWN
```

Detailed exception:

```text
→ internal operational log only
```

Tidak dikirim ke unauthenticated health caller.

---

# 4. Liveness vs Readiness

Current repository effectively mempunyai dua surfaces:

```text
/up

/api/v1/core/health
```

### [REKOMENDASI]

Tetapkan semantics terpisah:

```text
/up
→ LIVENESS

/api/v1/core/health
→ READINESS / DEPENDENCY HEALTH
```

---

## 4.1 Liveness

Menjawab:

> Apakah application process dapat menerima request?

Liveness **tidak boleh gagal hanya karena**:

- PostgreSQL temporarily unavailable;
- object storage unavailable;
- external provider unavailable.

Jika dependency failure menyebabkan liveness restart loop, infrastructure dapat memperburuk outage.

---

## 4.2 Readiness

Menjawab:

> Apakah instance siap melayani operation yang membutuhkan dependencies?

Readiness dapat memeriksa:

```text
database
required storage
other mandatory platform dependency
```

sesuai deployment.

---

# 5. Health Check Side Effects

Current storage health melakukan:

```text
write
→ read
→ delete
```

file pada setiap check.

Classification:

```text
KEEP concept
+
REVIEW probe implementation
```

### HR-016-NFR-001

Readiness check harus:

- bounded;
- low-cost;
- tidak membuat unbounded artifacts;
- tidak menghasilkan sensitive data;
- tidak membuat business transaction;
- sesuai dengan actual production storage provider.

Exact implementation mengikuti storage adapter/provider yang dipilih.

---

# 6. No HR-Specific Health Framework

HR tidak membuat:

```text
/hr/health
```

atau custom health subsystem.

HR-specific dependencies harus diintegrasikan ke shared operational health/metrics boundary bila memang menjadi mandatory runtime dependency.

---

# 7. Logging Architecture

Backend operational logging tetap centralized technical concern.

Conceptual:

```text
Application / Module
        ↓
Shared Logging Contract
        ↓
Production Log Collection
        ↓
Operational Search / Alerting
```

Business module tidak boleh bergantung pada vendor tertentu.

Forbidden dependency:

```text
HR
→ Datadog SDK directly

HR
→ Sentry SDK directly

HR
→ proprietary log API
```

---

# 8. Current Logging Configuration

Repository saat ini menyediakan:

```text
single
daily
stderr
syslog
slack
papertrail
```

dan local baseline:

```text
LOG_STACK=single
LOG_LEVEL=debug
```

### [FAKTA]

Ini development configuration, bukan production operational policy.

### HR-016-BR-002

Production multi-instance deployment tidak boleh bergantung pada local `storage/logs/laravel.log` sebagai satu-satunya observability source.

Logs harus dapat dikumpulkan lintas application instance.

Exact collector/provider:

**[OPEN DECISION]**

---

# 9. Production Debug Policy

Production wajib mengikuti existing security baseline:

```text
APP_DEBUG=false
```

Exact `LOG_LEVEL`:

**[OPEN DECISION]**

Namun production tidak boleh menggunakan debug-level payload dumping yang menyebabkan unnecessary sensitive-data exposure.

---

# 10. Structured Operational Context

Backend log event harus dapat menyertakan safe context seperti:

```text
environment
release_id
module
operation
request_id
error_code
HTTP status
job class
queue
```

where available.

Avoid default context:

```text
employee_name
national_id
salary
leave_reason
discipline_evidence
document_content
raw request body
raw response body
government export dataset
```

---

# 11. Request Correlation

ADR-031 sudah menyediakan integration point untuk:

```text
request_id
trace_id
correlation_id
```

tetapi repository belum mempunyai canonical backend implementation.

### HR-016-FR-001

Platform backend harus memperkenalkan **request-level correlation identifier** yang:

- generated/validated server-side;
- tersedia selama request lifecycle;
- disertakan pada safe operational logs;
- dikembalikan kepada client melalui documented API transport;
- dapat diteruskan ke frontend observability bila aman.

Exact transport/header name:

**[OPEN DECISION / platform API contract]**

---

# 12. Request ID ≠ Distributed Trace

HR-016 tidak menganggap:

```text
request_id
=
trace_id
```

Jika future infrastructure menggunakan distributed tracing:

```text
trace_id
```

dapat ditambahkan melalui platform observability contract.

Tidak perlu membuat fake distributed-tracing semantics sekarang.

---

# 13. Background Job Correlation

Async operation membutuhkan correlation berbeda dari HTTP request.

Preferred durable identity:

```text
business run ID
export_run_id
document_processing_run_id
```

dibanding hanya menggunakan originating request ID.

Request ID boleh menjadi supplemental correlation metadata jika tersedia.

---

# 14. Privacy in Logs

HR-014 tetap authoritative.

### HR-016-BR-003

Operational diagnostic logging menggunakan:

```text
allowlisted context
```

bukan serialization arbitrary domain object.

Forbidden:

```text
Log::error(..., employee.toArray())

Log::debug(..., request.all())

Log::info(..., exportDataset)
```

untuk HR sensitive flows.

---

# 15. Core Audit Remains Separate

Operational log:

```text
diagnose system
```

Core Audit:

```text
supplemental governance trail
```

Domain transaction evidence:

```text
prove business lifecycle
```

Ketiganya tidak boleh disatukan.

```text
Laravel log
≠ HR audit
≠ domain history
```

---

# 16. Queue Watchdog Remediation

HR-014 telah menemukan current watchdog menyalin:

```text
input_payload
```

ke Audit metadata.

### HR-016-BR-004

Sebelum sensitive HR asynchronous workload diaktifkan:

```text
QueueWatchdogListener
→ REFACTOR
```

Audit/log hanya membawa allowlisted metadata seperti:

```text
job_class
queue
exception_class
attempt
business_run_id
```

Raw serialized job payload tidak disalin.

---

# 17. Failed Job Logging

`failed_jobs.payload` sendiri menyimpan serialized job.

Maka invariant tetap:

```text
Sensitive HR job
→ identifier-only payload
```

Monitoring tidak boleh mencoba menyelesaikan masalah privacy dengan hanya menyensor UI failed-job viewer.

Sensitive data tidak boleh masuk payload sejak awal.

---

# 18. Backend Metrics

Minimum operational metric families yang relevan bagi HR:

```text
HTTP request volume
HTTP error rate
HTTP latency

database health / query latency
slow-query indicators

queue depth
oldest-job age
job processing duration
failed jobs

private storage failures

HR export duration/failure
report projection freshness/failure
```

Tidak semuanya harus diimplementasikan oleh HR module sendiri.

---

# 19. Metric Dimensions

Preferred low-cardinality dimensions:

```text
environment
release
module
operation
HTTP status class
machine error code
job type
queue
```

Avoid:

```text
employee_id
candidate_id
document_id
tenant name
organization name
national ID
```

sebagai metrics labels.

Resource IDs dapat tersedia di protected logs bila legitimately required, bukan high-cardinality global metrics.

---

# 20. HR Business Metrics ≠ Operational Metrics

Contoh:

```text
employee headcount
leave utilization
recruitment conversion
```

adalah **business/reporting metrics**.

Sedangkan:

```text
API latency
job failures
storage errors
```

adalah **operational metrics**.

HR-009 reporting tidak boleh digunakan sebagai infrastructure monitoring system.

---

# 21. Alert Principles

Alert harus:

```text
actionable
aggregated
low-noise
security-safe
```

Potential alert classes:

- readiness dependency failure;
- sustained 5xx increase;
- sustained latency regression;
- queue backlog/age growth;
- repeated job failures;
- private storage failure;
- government export processing failure spike;
- reporting projection failure/staleness;
- backup failure;
- restore-test failure.

---

# 22. No Alert Per Expected User Error

Normal:

```text
VALIDATION_FAILED
AUTHORIZATION_DENIED
RESOURCE_NOT_FOUND
business 409 conflict
```

tidak menghasilkan alert per occurrence.

Namun unusual aggregate spike dapat menjadi observability signal.

Exact thresholds/windows:

**[OPEN DECISION]**

---

# 23. Alert Destination

Pager/on-call/chat/email provider:

**[OPEN DECISION]**

Business module tidak menyimpan direct provider dependency.

---

# 24. Frontend Observability Reuse

Frontend tetap mengikuti ADR-031.

Production frontend observability minimum dapat mencakup:

```text
runtime errors
API failures
contract failures
chunk failures
Core Web Vitals
bootstrap outcome
Tenant/Workspace transition outcome
release ID
backend request correlation
```

HR tidak membuat separate HR telemetry SDK.

---

# 25. Release Identity

Setiap production frontend build sudah diwajibkan memiliki immutable:

```text
releaseId
```

HR-016 memperluas principle ini ke operational release secara keseluruhan.

### HR-016-FR-002

Backend deployment juga harus dapat diidentifikasi melalui safe release/build identity.

Candidate source:

```text
git SHA
CI build ID
application version
```

Exact formatting remains implementation detail.

---

# 26. Release Correlation

Operational event sebaiknya dapat menjawab:

> Failure ini mulai muncul pada release mana?

Safe correlation:

```text
environment
+
release_id
+
operation
+
error code
```

Tidak membutuhkan HR domain payload.

---

# 27. Environment Classes

Existing frontend NFR menetapkan minimum:

```text
Development
Test / CI
Staging
Production
```

HR menggunakan environment classes yang sama.

### HR-016-BR-005

Perbedaan environment tidak boleh mengubah:

- tenant isolation;
- authorization semantics;
- organizational scope semantics;
- data-integrity rules.

---

# 28. Staging

Staging harus cukup representative untuk menguji:

- migrations;
- authorization flow;
- queue processing;
- private storage integration;
- frontend/backend compatibility;
- production security configuration;
- critical HR workflow;
- health/readiness behavior.

Staging tidak harus mempunyai production-sized dataset.

---

# 29. Secrets

Deployment secrets tidak boleh:

- masuk Git;
- masuk frontend artifact;
- masuk release metadata;
- masuk logs;
- masuk audit payload.

Exact secret manager/KMS:

**[OPEN DECISION]**

---

# 30. Deployment Artifact Model

Frontend sudah locked:

```text
CI Build
→ Immutable Artifact
→ Release Activation
```

### [REKOMENDASI]

Backend menggunakan prinsip equivalent:

```text
Source Commit
→ Tests / Build
→ Immutable/Versioned Backend Release
→ Production Activation
```

Production server tidak menjadi tempat manual source editing.

---

# 31. No Manual Production Patch

Forbidden baseline:

```text
SSH production
→ edit PHP file
→ fix issue
```

atau:

```text
edit built JS directly on CDN
```

Emergency fix tetap harus menghasilkan traceable release artifact.

---

# 32. Deployment Is Not Feature Activation

EduCore already separates:

```text
module deployment
≠ tenant availability
≠ authorization
```

HR capability yang sudah terdapat pada release tidak otomatis usable oleh seluruh user.

```text
deployed
+
permission
+
scope
+
business readiness
```

tetap menentukan exposure.

---

# 33. API Compatibility During Deployment

Frontend/backend tidak diasumsikan deploy pada waktu identik.

Required:

```text
/api/v1
+
OpenAPI
+
backward-compatible release transition
```

Breaking contract tidak boleh dilakukan dalam single uncoordinated release.

---

# 34. Database Migration Strategy

Database migration merupakan area paling sulit untuk rollback.

### HR-016-BR-006

Default production evolution menggunakan:

```text
EXPAND
→ MIGRATE / BACKFILL
→ SWITCH
→ CONTRACT
```

bila perubahan memerlukan schema transition.

---

# 35. Expand

Release pertama menambahkan schema secara backward-compatible.

Examples:

```text
new nullable column
new table
new index
new relationship support
```

Old application version tetap dapat berjalan selama transition jika memungkinkan.

---

# 36. Migrate / Backfill

Jika data existing harus dipindahkan:

```text
bounded backfill
+
observable progress
+
restart/retry safety
```

Backfill besar tidak harus menjadi blocking migration.

---

# 37. Switch

Application mulai membaca canonical structure baru setelah:

```text
schema available
+
data ready
+
validation passed
```

---

# 38. Contract

Legacy schema baru boleh dihapus setelah:

- old code tidak lagi digunakan;
- data migration verified;
- rollback window selesai;
- dependency lain sudah diperiksa.

Example future:

```text
employees.jabatan
```

tidak boleh di-drop pada release yang sama ketika frontend/backend masih mungkin membaca field tersebut.

---

# 39. Migration Rollback

Semua migration repository saat ini mempunyai `down()` method.

Namun:

```text
has down()
≠ safe production rollback
```

### HR-016-BR-007

Production incident tidak boleh otomatis menjalankan:

```text
php artisan migrate:rollback
```

tanpa impact assessment.

Database dapat telah menerima new writes yang tidak representable dalam schema lama.

---

# 40. Preferred Database Recovery

Default:

```text
application issue
→ artifact rollback if schema-compatible

schema/data issue
→ controlled roll-forward/fix migration
```

Database rollback digunakan hanya jika explicitly verified safe.

---

# 41. Destructive Migration Gate

Migration yang:

- drops column/table;
- changes meaning;
- rewrites large dataset;
- destroys information;

membutuhkan:

```text
explicit migration plan
+
dependency review
+
verified backup/recovery
+
rollback or roll-forward strategy
```

sebelum production.

---

# 42. Migration Execution

Repository `composer setup` saat ini menjalankan:

```text
php artisan migrate --force
```

tetapi itu **bukan canonical production deployment pipeline**.

### [RESOURCE GAP]

Belum ada documented release pipeline yang menentukan:

- kapan migration dijalankan;
- oleh siapa;
- once-per-release semantics;
- failure handling;
- multi-instance coordination.

Ini harus diselesaikan pada implementation/deployment plan.

---

# 43. Deployment Sequence

Baseline recommended sequence untuk normal compatible release:

```text
1. Build/test artifacts
2. Validate configuration
3. Apply backward-compatible schema changes
4. Deploy backend release
5. Restart/replace workers
6. Run required backfill/reconciliation
7. Deploy/activate frontend artifact
8. Execute readiness checks
9. Execute smoke tests
10. Observe release health
```

Exact sequence dapat berubah jika release tidak menyentuh seluruh layer.

---

# 44. Queue Worker Deployment

Current database queue menggunakan serialized job classes.

Consequently:

```text
queued old job
+
new application code
```

dapat menciptakan compatibility risk.

### HR-016-NFR-002

Setiap release yang mengubah queued HR job harus mempunyai strategy untuk:

- backward-compatible payload/class handling;
- drain old jobs;
- version job contract;
- atau explicitly reconcile obsolete jobs.

Tidak boleh mengasumsikan queued serialized job otomatis compatible dengan code release baru.

---

# 45. Worker Restart

Long-running workers memuat application code ke memory.

### HR-016-FR-003

Deployment backend yang mengubah worker code harus memastikan worker lifecycle direfresh/restarted melalui deployment mechanism.

Exact supervisor:

**[OPEN DECISION]**

Possible implementation:

```text
Supervisor
systemd
container orchestrator
managed queue worker
```

tidak dikunci di HR specification.

---

# 46. Worker Readiness

Release dianggap belum fully healthy jika web API sehat tetapi required queue workers tidak berjalan untuk capability yang bergantung pada queue.

Monitoring harus dapat mendeteksi:

```text
jobs accumulating
+
no effective processing
```

---

# 47. After-Commit Requirement

HR-015 remains locked:

```text
new DB state required by job
→ dispatch after commit
```

Deployment/runbook harus memastikan change tersebut tidak hilang karena environment queue configuration masih:

```text
after_commit=false
```

secara global.

Implementation harus menggunakan explicit application/framework mechanism.

---

# 48. Scheduled Jobs

Current repository belum mempunyai HR scheduling orchestration.

`routes/console.php` hanya berisi default console command.

Therefore:

```text
attendance cutoff
contract expiry reminders
report refresh
retention purge
```

tidak boleh dianggap sudah scheduled.

Masing-masing tetap membutuhkan requirement/implementation authority.

---

# 49. Private Storage Deployment

HR production deployment yang mengaktifkan:

- documents;
- contracts;
- exports;

harus memiliki private storage yang:

- reachable;
- authorized;
- monitored;
- backed up sesuai classification;
- tidak public by default.

Current local private disk merupakan development-capable baseline, bukan automatic production architecture.

---

# 50. Storage Failure During Release

Deployment smoke/readiness verification harus dapat mendeteksi required storage dependency failure sebelum capability dianggap healthy.

Tetapi sensitive test artifact tidak boleh digunakan.

---

# 51. Frontend Deployment Rollback

Frontend FE-008 already locks:

```text
Previous tested immutable artifact
→ reactivate
```

### HR-016-BR-008

Frontend rollback tidak melakukan rebuild dengan dependency versions terbaru.

Rollback menggunakan exact previously tested artifact.

---

# 52. Static Asset Compatibility

Old browser document dapat tetap aktif saat release baru terjadi.

Therefore deployment harus mempertahankan required immutable hashed assets untuk appropriate transition window.

Forbidden:

```text
activate release B
→ immediately delete all release A chunks
```

jika active clients masih dapat mereferensikannya.

Exact CDN retention window is deployment policy.

---

# 53. Backend Rollback

Backend code rollback hanya aman jika database/API state tetap compatible.

Decision:

```text
Backend artifact rollback
→ conditional
```

bukan automatic.

Checklist minimum:

- migration compatibility;
- queued job compatibility;
- new data compatibility;
- API client compatibility.

---

# 54. Database Rollback

Classification:

```text
LAST RESORT / CASE-SPECIFIC
```

Prefer:

```text
roll forward
```

untuk production schema correction ketika data baru sudah masuk.

---

# 55. Worker Rollback

Worker rollback harus mempertimbangkan:

```text
existing queued jobs
job payload version
database schema
side effects already executed
```

Tidak boleh mengganti worker binary/code tanpa reconciliation jika contract berubah.

---

# 56. Release Failure Classification

### Frontend-only failure

Possible:

```text
reactivate previous static artifact
```

jika API compatibility tetap ada.

### Backend-only failure

Possible:

```text
rollback backend artifact
```

jika schema/job compatibility aman.

### Migration failure

Requires:

```text
stop release
assess database state
roll forward / restore / rollback only when proven safe
```

### Queue failure

Requires:

```text
pause affected processing
preserve/reconcile business runs
fix worker/job
resume safely
```

### Storage failure

Do not finalize business operation requiring unavailable artifact.

---

# 57. Deployment Health Verification

After release, minimum verification should cover:

```text
application liveness
database readiness
required storage readiness
authentication
Tenant context
Workspace context where applicable
capability resolution
critical HR read
critical protected mutation contract
queue processing when applicable
```

Smoke tests must use controlled non-sensitive test data/environment strategy.

---

# 58. HR Production Readiness Gates

Before broader HR production exposure, minimum gates include:

### P0 Security

- HR permission catalog implemented.
- Current Employee routes protected.
- organizational resource-scope rules implemented where exposed.
- no Position/Jabatan authorization.

### P0 Privacy

- QueueWatchdog raw-payload logging remediated.
- sensitive jobs identifier-only.
- Person identifier encryption implementation verified.
- sensitive DTO disclosure controlled.

### P0 Storage

- HR documents/exports use private storage.
- production storage provider operational if those capabilities are enabled.

### P0 API

- HR routes removed from deferred/hardening state where exposed.
- canonical API error contract implemented.
- OpenAPI + contract tests aligned.

### P0 Operations

- production deployment mechanism documented.
- worker deployment/restart mechanism documented.
- health response sanitized.
- production logs collectable centrally.
- release identity available.

### P0 Recovery

- backup strategy implemented.
- restore process tested for required persistence classes.
- RPO/RTO decisions recorded before production commitments.

---

# 59. Progressive Capability Release

Not all HR capabilities need to become production-ready simultaneously.

Example:

```text
Employee Directory
→ ready

Government Export
→ not ready
```

is acceptable if feature exposure prevents incomplete capability use.

### HR-016-BR-009

Deployment readiness may be evaluated **per capability**, while Core HR security invariants remain mandatory globally.

---

# 60. Feature Flags

No enterprise feature flag system is currently required.

If introduced later:

```text
feature flag
≠ permission
```

Flag can control rollout.

Authorization still uses HR-013.

---

# 61. Monitoring During Rollout

Initial rollout should observe:

- authorization denials anomaly;
- server errors;
- latency regression;
- queue backlog;
- storage errors;
- domain conflict rate where meaningful;
- frontend runtime/chunk issues;
- API contract failure.

Exact observation period and thresholds:

**[OPEN DECISION]**

---

# 62. Logging During Incident

Incident response may temporarily increase diagnostic detail only through controlled configuration.

It must still respect:

```text
no raw HR sensitive payload
no credential logging
no government identifier logging
no document body logging
```

Privacy rules are not suspended during incidents.

---

# 63. Operational Runbooks

Before production, minimum runbooks should exist for:

1. application deploy;
2. frontend rollback;
3. backend rollback decision;
4. migration failure;
5. queue worker failure;
6. failed-job reconciliation;
7. storage outage;
8. database outage;
9. backup restore;
10. security-sensitive HR data incident.

Exact organizational owner:

**[OPEN DECISION]**

---

# 64. Incident Correlation

Operational troubleshooting should be able to move from:

```text
user-visible error
→ request ID
→ backend logs
→ release
→ dependency state
```

without requiring user to send sensitive HR payload.

---

# 65. Data Incident Handling

If monitoring discovers possible unauthorized HR disclosure:

```text
stop/contain affected capability
→ preserve appropriate evidence
→ investigate scope
→ remediate
```

Exact incident-response/compliance process belongs to organization/security governance and is not invented here.

---

# 66. OpenAPI Release Gate

When an exposed HR API contract changes:

```text
requirement
→ implementation
→ OpenAPI change
→ contract tests
→ frontend/client adaptation
```

No hidden PHP-only breaking change.

---

# 67. Frontend Build Gate

Existing FE-008/ADR-031 remains:

- immutable static artifact;
- bundle budget checking;
- hidden/private source maps if enabled;
- release ID;
- source maps not public.

HR module remains lazy-loaded.

---

# 68. Source Maps

Production source maps:

```text
may be generated
```

but:

```text
MUST NOT
be publicly deployed
```

If observability provider is later selected:

```text
source map
→ private provider/storage
→ release matched
```

Failure to upload must be visible in release pipeline when source-map debugging is expected.

---

# 69. Monitoring Provider Boundary

Centralized observability provider remains:

**[OPEN DECISION]**

Candidate technologies are implementation alternatives, not HR requirement.

Architecture:

```text
HR / Core
→ provider-neutral contracts
→ adapter/infrastructure
→ selected provider
```

---

# 70. High Availability

No explicit HA topology has been authorized.

HR-016 therefore does not invent:

```text
3 app nodes
multi-AZ
active-active database
```

However architecture remains compatible with stateless application horizontal scaling as defined in HR-015.

---

# 71. Logging Retention

Exact operational log retention remains:

**[OPEN DECISION]**

It must be distinct from:

- HR business record retention;
- audit retention;
- export artifact retention;
- failed-job retention.

---

# 72. Monitoring Retention

Metric/trace/event retention:

**[OPEN DECISION]**

Privacy classification must be considered before selecting retention.

---

# 73. Production Configuration Validation

Before deployment activation, configuration validation should detect invalid critical configuration such as:

- production debug accidentally enabled;
- insecure session configuration;
- required DB unavailable;
- required private storage missing;
- missing encryption/application secrets;
- invalid module composition.

Exact validation command/tool may be added at implementation stage.

---

# 74. SQLite Conflict

Existing `.env.example` still defaults:

```text
DB_CONNECTION=sqlite
```

while current schema uses PostgreSQL-specific semantics.

### HR-016-BR-010

Staging/production release validation must verify authoritative PostgreSQL-compatible persistence.

Do not allow a stale default to become production database selection.

---

# 75. Migration Filename Casing

Existing handoff already records casing mismatch risk for migrations/ADR files.

### HR-016-NFR-003

CI must run on a case-sensitive environment representative of production filesystem behavior.

This prevents:

```text
works on developer machine
fails on Linux deployment
```

---

# 76. CI Minimum Gates

**[REKOMENDASI]**

Before production artifact promotion:

```text
backend tests
frontend tests
production frontend build
OpenAPI validation / contract tests
migration loading check
security configuration tests
case-sensitive filesystem validation
```

plus module-specific critical tests.

Exact CI vendor remains open.

---

# 77. No Direct Production Build Dependency

Production availability should not depend on:

```text
npm install from Internet
composer update
```

executed interactively during activation.

Artifacts/dependencies should be resolved in controlled build/release process.

---

# 78. Rollback Decision Matrix

| Change                                  | Default Recovery                                            |
| --------------------------------------- | ----------------------------------------------------------- |
| Frontend static artifact only           | Reactivate previous tested artifact                         |
| Backend code, no schema incompatibility | Previous backend release may be reactivated                 |
| Additive schema + backend               | Backend rollback possible only if schema remains compatible |
| Destructive/schema semantic change      | Prefer roll-forward; rollback case-specific                 |
| Worker code change                      | Coordinate with queued job compatibility                    |
| Failed async business run               | Reconcile by run identity                                   |
| Reporting projection corruption         | Rebuild projection                                          |
| Sensitive export failure                | Retry/recreate only through authorized run semantics        |
| Canonical DB corruption                 | Restore/recovery procedure                                  |
| Private artifact loss                   | Storage restore/recovery procedure                          |

---

# 79. Change Impact Against Current Repository

| Component                                     | Decision                                                 |
| --------------------------------------------- | -------------------------------------------------------- |
| `/up`                                         | **KEEP as liveness**                                     |
| `/api/v1/core/health`                         | **REFACTOR as safe readiness**                           |
| `SystemHealthService` raw dependency messages | **REMOVE FROM PUBLIC RESPONSE**                          |
| `DatabaseHealthChecker`                       | **KEEP + sanitize operational handling**                 |
| Local storage health check                    | **ADAPT TO PRODUCTION STORAGE**                          |
| `config/logging.php`                          | **KEEP + production configuration**                      |
| Local single log                              | **KEEP development / insufficient alone for production** |
| Request correlation                           | **ADD Core/platform capability**                         |
| QueueWatchdog                                 | **REFACTOR P0**                                          |
| Database queue                                | **KEEP**                                                 |
| Worker release lifecycle                      | **ADD operational contract**                             |
| `after_commit=false`                          | **EXPLICIT HANDLING REQUIRED**                           |
| Module migrations                             | **KEEP**                                                 |
| Expand/contract migration policy              | **ADD**                                                  |
| CI/CD                                         | **ADD**                                                  |
| Release artifacts                             | **ADD backend / KEEP frontend requirement**              |
| Deployment runbook                            | **ADD**                                                  |
| Rollback runbook                              | **ADD**                                                  |

---

# 80. Acceptance Criteria

### HR-016-AC-001 — Safe Health Response

**Given** database readiness check gagal
**When** health endpoint dipanggil
**Then** caller menerima dependency status yang aman
**And** raw database exception detail tidak dikirim.

### HR-016-AC-002 — Liveness Isolation

**Given** external dependency sementara unavailable
**When** liveness diperiksa
**Then** application process tidak dinyatakan dead hanya karena dependency readiness failure.

### HR-016-AC-003 — Request Correlation

**Given** HR API request diproses
**When** diagnostic event dihasilkan
**Then** event dapat dikorelasikan dengan request identifier
**Without** logging raw HR payload.

### HR-016-AC-004 — Failed Sensitive Job

**Given** sensitive HR job gagal permanen
**When** QueueWatchdog merekam failure
**Then** raw job payload tidak disalin ke audit/log metadata.

### HR-016-AC-005 — Immutable Frontend Rollback

**Given** frontend HR release menyebabkan production regression
**When** rollback dipilih
**Then** previous tested immutable artifact dapat diaktifkan kembali
**Without** rebuild manual di production.

### HR-016-AC-006 — Migration Compatibility

**Given** release membutuhkan perubahan schema
**When** frontend/backend lama masih mungkin aktif
**Then** migration transition tidak menghapus structure yang masih dibutuhkan active release.

### HR-016-AC-007 — Database Rollback

**Given** production incident terjadi setelah migration dan new writes
**When** recovery ditentukan
**Then** `migrate:rollback` tidak dijalankan otomatis
**And** data/schema compatibility diperiksa terlebih dahulu.

### HR-016-AC-008 — Worker Release

**Given** release mengubah queued job implementation
**When** deployment dilakukan
**Then** queued job compatibility/drain/version strategy tersedia
**And** long-running worker menggunakan release code yang benar.

### HR-016-AC-009 — Production Logs

**Given** aplikasi berjalan pada lebih dari satu instance
**When** HR error terjadi
**Then** diagnostic logs dapat dikumpulkan lintas instance
**And** tidak hanya bergantung pada local logfile.

### HR-016-AC-010 — Deployment Health

**Given** release selesai diaktifkan
**When** deployment verification dilakukan
**Then** liveness/readiness dan critical HR smoke path diverifikasi sebelum release dianggap healthy.

### HR-016-AC-011 — Alert Noise

**Given** user menghasilkan expected validation error
**When** error terjadi
**Then** system tidak membuat infrastructure alert per occurrence.

### HR-016-AC-012 — Private Artifact Dependency

**Given** document/export capability membutuhkan private storage
**When** storage tidak tersedia
**Then** capability tidak menyatakan required artifact operation berhasil.

### HR-016-AC-013 — OpenAPI Compatibility

**Given** exposed HR endpoint contract berubah
**When** release dibuat
**Then** OpenAPI dan contract test ikut diperbarui
**And** breaking change tidak hanya terdapat dalam controller code.

### HR-016-AC-014 — PostgreSQL Production

**Given** production configuration akan diaktifkan
**When** deployment validation dilakukan
**Then** stale SQLite default tidak menggantikan required PostgreSQL integrity semantics.

---

# 81. Open Decisions

Belum authoritative:

1. centralized backend logging provider;
2. metrics/APM provider;
3. distributed-tracing provider;
4. exact request-correlation transport/header;
5. alert threshold;
6. alert evaluation window;
7. alert destination/on-call tooling;
8. production `LOG_LEVEL`;
9. log retention;
10. metrics retention;
11. tracing retention;
12. CI/CD provider;
13. deployment runtime/container strategy;
14. backend immutable artifact packaging;
15. worker supervisor/orchestrator;
16. exact release rollout strategy;
17. production storage provider;
18. secret/KMS provider;
19. health probe cadence;
20. RPO/RTO from HR-015;
21. production backup tooling;
22. release observation window;
23. feature flag implementation if later required.

Tidak ada vendor atau numeric SLA yang diubah menjadi fakta.

---

# 82. IN SCOPE

- backend/frontend observability boundary;
- health/readiness;
- correlation;
- logging privacy;
- operational metrics;
- alert principles;
- release identity;
- deployment environments;
- migration strategy;
- worker deployment;
- private-storage readiness;
- production gates;
- rollback/roll-forward principles;
- operational runbooks.

# 83. OUT OF SCOPE

- selection of observability vendor;
- container/Kubernetes implementation;
- CI YAML implementation;
- Terraform/infrastructure code;
- source-code implementation;
- statutory incident response process;
- exact alert thresholds;
- exact RPO/RTO;
- exact log-retention duration.

# 84. DEFERRED

- distributed tracing;
- advanced canary deployment;
- automated progressive delivery;
- enterprise feature-flag platform;
- multi-region HA;
- tenant-specific restore;
- automated destructive migration rollback.

Semua memerlukan requirement/evidence tambahan.

---

# 85. Traceability

```text
HR-013
Authorization Enforcement
        ↓
HR-014
Security / Privacy / Sensitive Logging
        ↓
HR-015
Performance / Backup / Recovery
        ↓
ADR-031
Observability / Release Identity
        ↓
FE-008
Immutable Deployment / Rollback
        ↓
HR-016
Operational Readiness
        ↓
Phase 3H
Final Cross-Phase Review
        ↓
Engineering Implementation Readiness
```

---

# 86. Phase Review

**Quality Score:** **9.7/10**

## Gaps

- centralized observability provider belum tersedia;
- backend correlation contract belum implemented;
- CI/CD belum tersedia;
- deployment/rollback runbook belum tersedia;
- worker process supervision belum tersedia;
- backup/restore implementation dari HR-015 belum tersedia;
- RPO/RTO belum diputuskan.

## Risks

**[RISK — HIGH]** Current Core health endpoint dapat mengekspos dependency exception detail kepada unauthenticated caller.

**[RISK — HIGH]** Current QueueWatchdog masih dapat menggandakan serialized HR payload ke Audit metadata.

**[RISK — HIGH]** Production database rollback yang mengandalkan migration `down()` dapat menyebabkan data loss setelah new writes.

**[RISK — HIGH]** Long-running workers dan queued serialized jobs dapat menjalankan contract yang tidak kompatibel setelah code deployment jika worker/job lifecycle tidak dikelola.

**[RISK]** Local `single` logging tidak cukup sebagai satu-satunya observability mechanism dalam horizontally scaled production.

**[RISK]** Belum adanya release/correlation identity backend akan memperlambat incident diagnosis.

## Recommendations

1. Lock HR-016 sebagai operational-readiness contract.
2. Pertahankan `/up` sebagai liveness dan harden Core health sebagai sanitized readiness.
3. Tambahkan backend request correlation pada Core/platform.
4. Refactor QueueWatchdog sebelum sensitive HR jobs.
5. Gunakan expand/migrate/switch/contract untuk schema evolution.
6. Prefer roll-forward untuk unsafe database changes.
7. Gunakan immutable tested artifacts untuk frontend dan versioned/reproducible backend releases.
8. Tambahkan CI/CD + deployment + rollback + worker runbooks sebelum production.
9. Jangan menyatakan HR production-ready sebelum P0 gates HR-013–HR-016 terpenuhi.

**Specification Status:** **READY FOR APPROVAL**

**Current Implementation Production Readiness:**
**NOT READY — CRITICAL OPERATIONAL/SECURITY REMEDIATION PENDING**
