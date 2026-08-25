HR-015 — HR Performance, Scalability, Backup & Recovery Requirements

Version: 0.1 Draft
Phase: 3F — Performance, Scalability, Backup & Recovery
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
Depends On: HR-001–HR-014, ADR-031, FE-008

1. Objective

HR-015 memastikan pertumbuhan data dan traffic HR tidak mengorbankan:

data integrity;
tenant isolation;
authorization scope;
transactional consistency;
recoverability.

Prinsip utamanya:

Correctness
→ Data Integrity
→ Security
→ Maintainability
→ Measured Performance
→ Scalability

Optimasi tidak boleh dilakukan hanya berdasarkan asumsi skala.

2. Resource Audit
   [FAKTA] Repository baseline

Repository tetap berada pada:

26b475b695aa4511064b1410db03d1f0c8bdd6ce

Tidak ada delta repository terhadap handoff HR-009.

[FAKTA] Current Employee API

Current Employee list:

GET /v1/hr/employees

menggunakan:

LengthAwarePaginator
default per_page = 15
maximum per_page = 100

Query melakukan:

employees
JOIN memberships
JOIN persons

WHERE employees.tenant_id = ?
AND memberships.tenant_id = ?
AND employees.deleted_at IS NULL

ORDER BY persons.name
ORDER BY employees.id
[FAKTA] Existing Employee indexes

Current employees mempunyai:

PRIMARY KEY(id)

UNIQUE membership_id

UNIQUE (tenant_id, nip)

INDEX (tenant_id, created_at)

Belum ada index khusus yang secara langsung mengoptimalkan:

tenant + persons.name sort 3. Existing Infrastructure Findings
Area Current state Classification
Employee pagination Bounded ≤100 KEEP
Tenant filter Existing KEEP
Organizational scoped query Belum ada ADD before scoped rollout
Database queue Existing KEEP + HARDEN
Queue retry 3 tries / 30s base job KEEP as current implementation, not HR SLA
Queue after_commit false CONSTRAINT / explicit handling required
Private filesystem Existing KEEP + EXTEND
HR reporting projection Belum ada DEFER until justified
Application backup package Tidak ditemukan RESOURCE GAP
Verified restore process Tidak ditemukan RESOURCE GAP
RPO/RTO Tidak tersedia OPEN DECISION
Production storage provider Belum dipilih OPEN DECISION 4. Persistence Conflict Remains

[CONFLICT]

.env.example masih menggunakan:

DB_CONNECTION=sqlite

sementara schema terbaru memakai PostgreSQL-specific integrity constructs seperti:

partial unique indexes;
composite FK semantics;
jsonb;
PostgreSQL-specific constraint statements.
HR-015-BR-001

Production HR architecture tidak boleh menurunkan relational integrity agar tetap compatible dengan stale SQLite development default.

Target persistence environment harus mengikuti authoritative schema requirements.

5. Performance Strategy

HR tidak menggunakan:

optimize everything upfront

Baseline:

Measure
→ identify bottleneck
→ optimize narrow path
→ validate correctness
→ measure again
HR-015-BR-002

Tidak boleh memperkenalkan:

generic cache layer;
generic reporting projection;
EAV metric store;
distributed service;
new queue backend;
denormalized Employee shadow table;

hanya untuk hypothetical scalability.

6. Workload Classification

HR workload dibedakan menjadi:

A. Transactional

Contoh:

Employee provisioning
Employment lifecycle
Leave approval
Attendance reconciliation
Compensation changes
Performance finalization
Discipline
Offboarding

Prioritas:

integrity > latency
B. Interactive Read

Contoh:

Employee directory
Employee detail
Approval worklist
Attendance worklist

Prioritas:

bounded query

- scope filtering
- pagination
  C. Reporting

Contoh:

dashboard
period report
aggregate KPI

Baseline tetap:

direct-query-first

Projection hanya setelah evidence.

D. Heavy / asynchronous

Contoh:

large export
government export
document processing
report materialization when justified

Dapat menggunakan queue.

7. Query Scope First
   HR-015-BR-003

Authorization filtering harus masuk ke query sebelum:

pagination
sorting
aggregation
export

Target:

Tenant

- Organizational Scope
- Domain Filter
  ↓
  Query
  ↓
  Pagination / Aggregation

Forbidden:

query all Tenant rows
→ paginate
→ filter unauthorized rows

Selain security issue, pola tersebut juga tidak scalable.

8. Employee Listing

Current Employee list tetap KEEP untuk tenant-wide baseline.

Tetapi current query mempunyai potential scaling concern:

tenant filter

- join memberships/persons
- sort persons.name
- COUNT for LengthAwarePaginator
  [REKOMENDASI]

Jangan menambah index baru hanya berdasarkan tebakan.

Sebelum optimasi:

representative dataset
→ EXPLAIN / query plan
→ query latency measurement
→ index decision 9. Index Strategy

Index harus mengikuti access pattern yang benar-benar digunakan.

Baseline principle:

Tenant / scope columns
→ leading consideration

high-selectivity filters
→ evaluate

sort columns
→ evaluate with query plan
HR-015-BR-004

Setiap index baru harus mempunyai documented query/use case.

Contoh justification valid:

Employee directory
WHERE tenant_id = ?
ORDER BY ...

atau:

Leave approval worklist
WHERE tenant_id = ?
AND status = ?
AND effective organizational scope ...

Bukan:

mungkin akan lebih cepat.

10. Over-Indexing

Index mempunyai cost:

storage

- INSERT cost
- UPDATE cost
- maintenance

Karena HR mempunyai banyak lifecycle transactions, indiscriminate indexing dilarang.

[REKOMENDASI]

Index review dilakukan bersama API/query specification setiap capability.

11. Pagination Strategy
    HR-015-FR-001

Collection HR yang dapat bertumbuh harus mempunyai bounded pagination.

Current:

Employee per_page ≤ 100

tetap valid untuk endpoint tersebut.

Namun:

100

bukan universal HR page size.

Exact limit per high-volume API dapat ditentukan berdasarkan workload.

12. Length-Aware vs Cursor Pagination

Current Employee API membutuhkan:

total
last_page

sehingga menggunakan LengthAwarePaginator.

Classification:

KEEP

untuk current contract.

Namun exact count dapat menjadi mahal pada dataset besar.

[REKOMENDASI]

Untuk future high-volume streams/worklists:

if exact total required
→ length-aware pagination

if stable forward traversal is enough
→ evaluate cursor pagination

[OPEN DECISION] Tidak ada migration ke cursor sampai evidence menunjukkan kebutuhan.

13. Search

Employee search di masa depan tidak boleh dilakukan dengan:

load all Employee
→ browser filter
HR-015-FR-002

Search/filter untuk dataset besar harus server-side dan authorization-aware.

Search index/engine khusus:

Elasticsearch
OpenSearch
Meilisearch

tidak justified saat ini.

Baseline tetap relational query.

14. Avoid N+1 / Request Amplification

Employee/detail workspace dapat menggabungkan banyak capability.

Forbidden:

Employee page
→ 1 request Employee
→ N requests per Employment
→ N requests per document
→ N requests per other row

tanpa kebutuhan.

HR-015-NFR-001

API/UI design harus membatasi request amplification melalui:

purpose-specific endpoints;
sensible aggregation;
batched retrieval jika justified;
server-state query deduplication.

Tetap:

one screen
≠ one domain ownership 15. Reporting Scalability

HR-009 tetap authoritative:

Direct Query
→ first choice

Projection hanya diperkenalkan jika measured evidence menunjukkan:

unacceptable query cost;
repeated expensive aggregation;
concurrency pressure;
dashboard freshness requirement yang tidak dapat dipenuhi direct query.
HR-015-BR-005

Persisted projection harus:

rebuildable
reconcilable
source_as_of-aware

dan bukan source of truth.

16. Projection Failure

Jika projection hilang:

canonical HR data
→ remains intact

Recovery:

rebuild projection from canonical state

Projection tidak membutuhkan disaster-recovery semantics yang sama dengan canonical HR tables selama rebuild procedure terbukti.

17. Cache Strategy

Generic server-side HR cache:

NOT BASELINE
[REKOMENDASI]

Cache hanya diperkenalkan jika:

query terbukti expensive;
invalidation boundary jelas;
Tenant/Workspace keying aman;
stale-data consequence dipahami.

Cache key minimum harus mempertimbangkan:

Tenant
Workspace/scope
query/filter
authorization-sensitive context where relevant 18. Cache Must Not Become Authority
cached permission
≠ backend authorization authority

cached Employee placement
≠ canonical organizational assignment

cached report
≠ canonical business fact

Security-sensitive decision tetap berdasarkan authoritative persistence/context.

19. Async Workload

Queue digunakan untuk operation yang memang tidak perlu selesai dalam synchronous request.

Candidates:

large export
government export
document scanning
heavy projection refresh

bukan ordinary CRUD secara default.

HR-015-BR-006

Moving work to queue tidak boleh digunakan untuk menyembunyikan inefficient synchronous architecture.

20. After-Commit Requirement

[FAKTA]

Current Laravel queue connections menggunakan:

after_commit = false

Sementara HR-009 telah mengunci:

async work depending on newly committed DB state
→ run after commit
HR-015-BR-007

Untuk HR job yang bergantung pada state yang baru dibuat/diubah:

DB Transaction
↓ COMMIT
Dispatch Job

harus dijamin secara eksplisit.

Implementation dapat menggunakan framework-supported after-commit mechanism atau equivalent application orchestration.

Tidak boleh:

enqueue
→ worker executes
→ transaction not committed yet 21. Queue Payload

HR-014 tetap berlaku:

identifier-only

Queue scalability tidak menjadi alasan memasukkan preloaded dataset besar ke payload.

Worker melakukan:

job identifier
→ load current required state
→ verify Tenant
→ execute 22. Idempotency

Queue retry berarti operation dapat dieksekusi lebih dari sekali.

HR-015-NFR-002

Async HR operation harus menentukan apakah:

idempotent

atau memiliki deduplication/business-run identity.

Particularly:

government export
document processing
projection refresh
notification side effects

Tidak boleh mengasumsikan tries=3 aman untuk seluruh operation.

23. Current Queue Retry Values

Core current baseline:

tries = 3
backoff = 30
[FAKTA]

Ini implementation default, bukan HR business SLA.

[OPEN DECISION]

Exact:

retry count;
backoff;
timeout;
maximum execution time;

harus ditentukan per job type atau operational policy bila diperlukan.

24. Concurrency

HR mempunyai lifecycle yang berisiko concurrent mutation.

Examples:

two leave approvers
two Employment updates
two offboarding completion attempts
two export generation requests
HR-015-BR-008

Database constraints dan transactional business rules tetap primary integrity mechanism.

Frontend button disabling tidak cukup sebagai concurrency control.

25. Concurrency Conflict

Where appropriate:

read state
→ validate transition
→ atomic mutation

Jika state telah berubah:

409 domain conflict

sesuai HR-012.

[REKOMENDASI]

Pessimistic lock, optimistic version, unique constraint, atau atomic conditional update dipilih per invariant.

Tidak ada one-size-fits-all global locking strategy.

26. Horizontal Scalability

EduCore tetap modular monolith.

HR tidak membutuhkan microservice untuk scale baseline.

Target architecture:

Stateless application instances
↓
Shared PostgreSQL
Shared private object storage
Shared queue infrastructure

deployment detail final berada di Phase 3G.

HR-015-BR-009

HR module tidak boleh menyimpan correctness-critical state dalam local application memory.

27. Frontend Performance

HR mewarisi FE-008.

Existing global frontend objectives:

Metric Existing target
LCP ≤ 2.5s p75
INP ≤ 200ms p75
CLS ≤ 0.1 p75

HR business module harus tetap:

lazy-loaded

dan tidak masuk initial application bundle bila route HR belum dibutuhkan.

HR-015-BR-010

Tidak ada HR-specific Web Vital baru tanpa measurement/business justification.

28. Backend Latency

[OPEN DECISION]

Belum terdapat authoritative HR/backend target seperti:

p95 < 200 ms
p95 < 500 ms

Karena itu HR-015 tidak mengarang API SLA.

Namun measurement minimum harus memungkinkan observasi:

endpoint latency;
database query duration;
query count;
queue waiting/execution time;
export duration;
projection freshness.

Exact thresholds akan ditentukan setelah workload/production evidence tersedia.

29. Scalability Thresholds

Open items HR-009 seperti:

large export threshold;
date-range limit;
dashboard latency SLA;
projection freshness SLA;

tetap [OPEN DECISION].

HR-015-BR-011

Threshold tidak boleh ditentukan dari arbitrary number hanya agar specification terlihat lengkap.

30. Data Recovery Classification

Tidak semua data membutuhkan recovery strategy yang sama.

A. Canonical / Non-Rebuildable

Contoh:

Person
Membership
Employee
Employment
Leave transactions
Attendance final facts
Compensation facts
Performance records
Discipline cases
Offboarding records
Domain transactional evidence
Document metadata

Harus termasuk backup strategy.

B. Binary Business Evidence

Contoh:

finalized document
signed agreement artifact
required HR attachment

Private files tersebut tidak dapat diasumsikan rebuildable.

Backup/recovery harus mencakup object storage.

C. Frozen Reporting Dataset

Government/report frozen dataset yang menjadi evidence harus diperlakukan sebagai durable business record sesuai retention policy.

D. Rebuildable

Contoh:

reporting projection
cache
derived dashboard aggregates

dapat direbuild dari canonical state jika rebuild contract tersedia.

E. Ephemeral / Operational

Contoh:

cache entries
temporary local files
temporary UI/server state

tidak menjadi primary disaster-recovery source.

31. Generated Export Artifact

ExportRun dan artifact tetap dipisahkan.

Jika:

Frozen Dataset
→ retained

dan export format deterministic/reproducible, binary artifact dapat berpotensi diregenerate.

Tetapi ini belum otomatis berlaku.

[OPEN DECISION]

Per export type perlu ditentukan apakah artifact:

canonical evidence

atau:

rebuildable derivative 32. Backup Baseline

[RESOURCE GAP]

Repository saat ini tidak menunjukkan:

backup package;
automated database dump;
PITR configuration;
object-storage backup;
restore runbook;
disaster recovery script.

Karena itu backup belum dapat dianggap implemented.

33. Backup Coverage Requirement

Production backup strategy minimum harus mempertimbangkan:

Primary Database

- Private HR Artifact Storage
- required application/configuration state

Secrets/key material membutuhkan secure backup/key-management strategy tersendiri.

HR-015-BR-012

Backup database tanpa private document storage bukan complete HR recovery.

Sebaliknya:

files
without canonical metadata/database

juga bukan complete recovery.

34. Backup Consistency

Database dan private storage dapat berubah pada waktu berbeda.

[REKOMENDASI]

Recovery design harus dapat menentukan relationship:

DB metadata
↔ object artifact

melalui stable object identifiers/checksums/status metadata.

Tujuannya mendeteksi:

metadata exists / object missing
object exists / metadata missing

setelah restore.

Exact provider-specific snapshot mechanism ditentukan pada deployment design.

35. Backup Security

Backup mengandung data dengan sensitivity setidaknya sama dengan production.

Therefore:

Production authorization
≠ sufficient protection for backup

Requirements:

encrypted storage;
restricted operational access;
no public backup location;
secrets separated appropriately;
retention defined explicitly;
backup access auditable where infrastructure permits. 36. RPO

[OPEN DECISION]

Recovery Point Objective belum mempunyai business authority.

Kita tidak menetapkan angka seperti:

15 minutes
1 hour
24 hours

tanpa keputusan stakeholder.

Namun sebelum production launch, RPO harus diputuskan minimal untuk:

canonical HR database;
private HR documents;
frozen/export evidence where durable;
audit/domain evidence. 37. RTO

[OPEN DECISION]

Recovery Time Objective juga belum authoritative.

Harus ditetapkan berdasarkan business criticality, bukan kemampuan teknologi semata.

Potential classification dapat dibuat nanti:

Critical HR operations
Administrative HR
Reporting
Historical archive

tetapi angka belum dikunci.

38. Restore Priority

Conceptual restore order:

Infrastructure prerequisites
↓
Canonical Database
↓
Private Business Artifacts
↓
Integrity verification
↓
Authorization/context verification
↓
Rebuildable projections
↓
Resume async processing
HR-015-BR-013

Queue workers tidak boleh langsung dipulihkan sebelum canonical state dan dependency-nya siap.

39. Queue Recovery

jobs dan failed_jobs bukan canonical HR data source.

Setelah disaster recovery, blindly replaying old jobs dapat menghasilkan duplicate side effects.

HR-015-NFR-003

Queue recovery harus membedakan:

safe to retry
requires reconciliation
obsolete
already completed

berdasarkan business-run state.

40. Restore Validation

Restore dianggap berhasil bukan hanya jika database server menyala.

Minimum verification:

tenant integrity
membership integrity
Employee/Employment integrity
organizational relationships
authorization grants
document metadata ↔ artifact consistency
critical domain evidence

dan selected business smoke tests.

41. Restore Testing
    HR-015-NFR-004

Backup dianggap belum terbukti jika restore belum pernah diuji.

Required process:

backup
→ isolated restore environment
→ integrity validation
→ application smoke validation
→ result recorded

Frequency:

[OPEN DECISION]

Tidak dikunci tanpa operations policy.

42. Tenant-Level Restore

EduCore memakai shared multi-tenant persistence.

Karena itu:

restore Tenant A

jauh lebih kompleks daripada full database restore.

Risks:

shared Person references;
authorization relationships;
cross-module dependency;
UUID collision/current state;
audit continuity;
object storage relationship.
HR-015-BR-014

Per-tenant logical restore tidak diasumsikan tersedia.

Jika menjadi business requirement, harus dibuat separate architecture/runbook.

43. Point-in-Time Recovery

PostgreSQL PITR dapat menjadi relevant production capability, tetapi repository belum memberikan infrastructure authority.

Classification:

[REKOMENDASI]
evaluate at deployment/infrastructure layer

bukan locked implementation dalam HR module.

44. Failure Degradation

Failure pada noncanonical capability tidak boleh merusak canonical HR transaction.

Examples:

Reporting projection failed
→ HR transaction survives

Telemetry unavailable
→ HR transaction survives

Supplemental audit unavailable
→ follows locked fail-open semantics

Tetapi mandatory transactional/domain evidence tetap harus berada dalam business transaction jika required.

45. Object Storage Failure

Untuk transaction yang membutuhkan artifact sebagai bagian dari business completion:

database state

- artifact state

harus mempunyai explicit lifecycle.

Avoid:

DB says FINALIZED
but required artifact upload failed

tanpa recovery state.

Exact saga/orchestration bergantung capability, tetapi inconsistent “success” state dilarang.

46. Large Export

Baseline:

small/normal report
→ synchronous if measured safe

large/sensitive export
→ async when justified

Exact switch threshold:

[OPEN DECISION]

HR-015-BR-015

Threshold harus berdasarkan:

row count;
execution duration;
memory;
artifact size;
operational concurrency;

bukan hanya satu hardcoded arbitrary number.

47. Memory Safety

Export/report implementation harus menghindari:

load entire huge dataset into memory
[REKOMENDASI]

Untuk large workload:

bounded query/chunk/stream
→ controlled transformation
→ private artifact

dengan tenant/scope filter pada setiap source query.

48. Capacity Planning

Sebelum production scale-up, measurement perlu mencakup minimal:

Dimension Example
Employees per Tenant workforce size
HR transactions/day leave, attendance, etc.
Concurrent users operational concurrency
Document volume count + bytes
Export volume rows + bytes
Queue throughput wait/execution
DB growth canonical + audit
Report workload frequency + query cost

Exact expected numbers:

[RESOURCE GAP / OPEN DECISION]

49. Observability Handoff

Phase 3F mendefinisikan apa yang perlu diukur.

Phase 3G menentukan operational monitoring/deployment mechanism.

Minimum metrics candidates:

HR API latency
error rate
slow query
queue depth
queue age
job failure
export duration
projection freshness
artifact storage failure
database/storage capacity

No employee-sensitive labels in metrics.

50. Existing Implementation Impact
    Area Decision
    Employee bounded pagination KEEP
    Employee tenant query KEEP, later scope-aware extension
    Employee sort by Person name MEASURE before indexing
    (tenant_id, created_at) Employee index KEEP
    Additional HR indexes ADD only per measured query
    LengthAwarePaginator KEEP current endpoint
    Cursor pagination DEFER / evaluate per endpoint
    Database queue KEEP
    after_commit=false HANDLE EXPLICITLY
    Queue base retries KEEP as implementation default, not SLA
    Reporting projection DEFER until evidence
    Generic HR cache DO NOT INTRODUCE yet
    Backup implementation ADD at infrastructure/deployment layer
    Restore runbook ADD before production readiness
51. Acceptance Criteria
    HR-015-AC-001 — Bounded Collection

Given HR collection dapat bertumbuh
When collection diminta
Then API tidak mengembalikan unbounded dataset secara default.

HR-015-AC-002 — Scoped Pagination

Given user mempunyai organizational-scoped access
When list diproses
Then resource scope diterapkan sebelum pagination.

HR-015-AC-003 — Index Justification

Given index HR baru diusulkan
When schema change direview
Then index memiliki documented query/access pattern atau performance evidence.

HR-015-AC-004 — Projection

Given dashboard dapat dipenuhi dengan acceptable direct query
When architecture dipilih
Then persisted projection tidak diperkenalkan hanya sebagai speculative optimization.

HR-015-AC-005 — After Commit

Given job memerlukan state database yang baru dibuat
When transaction sedang berlangsung
Then job tidak boleh bergantung pada state tersebut sebelum commit berhasil.

HR-015-AC-006 — Retry Safety

Given queued HR job di-retry
When execution kedua terjadi
Then operation mempunyai idempotency/deduplication/reconciliation contract sesuai side effect-nya.

HR-015-AC-007 — Backup Coverage

Given production HR menyimpan canonical DB record dan private artifact
When backup strategy dibuat
Then kedua persistence classes tercakup atau secara eksplisit diklasifikasikan rebuildable.

HR-015-AC-008 — Restore Evidence

Given backup berhasil dibuat
When recoverability dinilai
Then status tidak dinyatakan proven sampai isolated restore dan integrity verification dilakukan.

HR-015-AC-009 — No Invented RPO/RTO

Given stakeholder belum menetapkan RPO/RTO
When deployment dirancang
Then engineering tidak mengubah angka asumsi menjadi SLA canonical.

HR-015-AC-010 — Tenant Restore

Given shared database multi-tenancy
When operator membutuhkan restore satu Tenant
Then system tidak mengasumsikan full-backup restore dapat langsung melakukan tenant-selective restoration.

HR-015-AC-011 — Rebuildable Projection

Given reporting projection hilang
When canonical HR state masih intact
Then projection dapat direbuild tanpa menjadikannya source of truth.

HR-015-AC-012 — Object Consistency

Given required HR artifact gagal disimpan
When business lifecycle diproses
Then record tidak boleh tampil sebagai successfully completed bila required artifact belum mencapai state yang diperlukan.

52. Open Decisions

Tetap belum authoritative:

HR/backend latency SLA;
expected employee volume per Tenant;
concurrent HR users;
dashboard latency SLA;
projection freshness SLA;
large-export threshold;
API date-range limits;
cache TTL;
queue timeout/retry/backoff per workload;
production database topology;
production queue backend;
production object-storage provider;
backup provider/tool;
backup schedule;
backup retention;
database RPO;
artifact-storage RPO;
system RTO;
restore-test frequency;
PITR requirements;
per-Tenant restore requirement;
classification of individual generated export artifacts as durable vs rebuildable.

Tidak ada nilai numerik yang saya ubah menjadi fakta tanpa authority.

53. Scope
    IN SCOPE
    HR query/performance principles;
    pagination;
    indexing strategy;
    reporting projection scalability;
    cache boundary;
    asynchronous workloads;
    concurrency/retry safety;
    capacity measurement;
    backup classification;
    backup coverage;
    restore architecture;
    RPO/RTO requirement boundary.
    OUT OF SCOPE
    infrastructure vendor selection;
    Kubernetes/cloud topology;
    source-code implementation;
    exact database sizing;
    exact SLA numbers;
    production backup command/script;
    monitoring vendor;
    deployment/rollback procedure.
    DEFERRED
    cursor migration;
    search engine;
    distributed cache;
    read replicas;
    database partitioning;
    per-Tenant restore implementation;
    PITR implementation;
    generic data warehouse.

Semua hanya diperkenalkan jika evidence atau business requirement membutuhkannya.

54. Traceability
    HR-009
    Direct Query / Projection / Export
    ↓
    HR-012
    Failure & Recovery UX
    ↓
    HR-013
    Scope-aware Authorization
    ↓
    HR-014
    Private Storage / Sensitive Queue
    ↓
    HR-015
    Performance / Scalability / Backup / Recovery
    ↓
    HR-016
    Logging / Monitoring / Deployment / Rollback
    ↓
    Implementation
    ↓
    Load / Recovery / Integration Tests
55. Phase Review

Quality Score: 9.7/10

Gaps
belum ada production capacity targets;
belum ada backend SLA;
belum ada RPO/RTO;
backup/restore implementation belum tersedia;
production DB/storage/queue provider belum diputuskan.
Risks

[RISK — HIGH] Menambahkan organizational authorization tanpa scope-aware query dapat menyebabkan data leak sekaligus query overhead tenant-wide.

[RISK — HIGH] Queue after_commit=false dapat menyebabkan worker membaca state yang belum committed jika future HR dispatch tidak dirancang dengan benar.

[RISK — HIGH] Backup yang hanya mencakup database akan kehilangan finalized documents/private artifacts yang tidak rebuildable.

[RISK — HIGH] Backup tanpa restore testing memberi false confidence terhadap disaster recovery.

[RISK] LengthAwarePaginator dan name sorting dapat menjadi expensive pada dataset besar, tetapi premature replacement tanpa measurement juga berisiko memperumit API.

Recommendations
Lock HR-015 sebagai performance/recovery baseline.
Pertahankan bounded pagination existing.
Gunakan query-plan evidence sebelum menambah index.
Jangan memperkenalkan projection/cache/search-engine secara prematur.
Explicit after-commit dispatch wajib untuk dependent HR jobs.
Sebelum production, tetapkan RPO/RTO bersama stakeholder.
Backup harus mencakup canonical DB + non-rebuildable private artifacts.
Restore test harus menjadi production-readiness evidence.

Status: READY FOR APPROVAL
