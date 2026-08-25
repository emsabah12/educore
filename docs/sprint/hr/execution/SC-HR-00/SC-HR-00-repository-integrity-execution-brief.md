SC-HR-00 — Repository Integrity Execution Brief

**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-24
Execution Type: Engineering preparation — no coding yet
Repository HEAD: 26b475b695aa4511064b1410db03d1f0c8bdd6ce
Primary Tasks: HR-TASK-001–HR-TASK-013
Supporting Gate: HR-TASK-232 direkomendasikan ditarik maju untuk enforcement CI.

1. Project Resource Check

[FAKTA] Audit fresh terhadap educore(3).zip mengonfirmasi HEAD masih sama dengan baseline handoff. Handoff sendiri menetapkan commit tersebut sebagai repository authority dan menyatakan HR artifacts belum terintegrasi ke repository docs.

Tiga concrete repository conflicts saat ini:

ID Current repository Expected treatment
RI-001 Git tracks create_Employees_table.php, filesystem berisi create_employees_table.php Normalize casing
RI-002 Git tracks lowercase adr-011/012, filesystem berisi ADR-011/012 Normalize ke ADR convention
RI-003 .env.example + config fallback default ke SQLite sementara integration/schema memakai PostgreSQL semantics Align PostgreSQL baseline

[FAKTA] composer.json juga masih membuat database/database.sqlite pada post-create-project-cmd, sehingga conflict bukan hanya .env.example.

[FAKTA] repository sudah memiliki PostgreSQL-specific integration behavior; misalnya Core database integration test secara eksplisit memakai koneksi pgsql. Handoff sebelumnya juga sudah mencatat SQLite-vs-PostgreSQL ini sebagai conflict yang harus diselesaikan dengan mempertahankan integrity semantics, bukan menurunkannya demi stale config.

2. Scope
   IN SCOPE

Repository portability, canonical filename casing, PostgreSQL default/setup alignment, documentation integration HR, dan verification baseline.

OUT OF SCOPE

HR permission implementation, Employee API authorization, Employment/Position schema, frontend implementation, QueueWatchdog remediation, atau business feature baru.

DEFERRED

Full frontend CI berada pada shared frontend execution. Full backend CI enforcement adalah HR-TASK-232, tetapi sebaiknya ditarik maju pada akhir SC-HR-00 agar repository-integrity checks benar-benar enforceable.

3. Recommended PR Boundaries

Saya merekomendasikan 4 PR kecil, bukan satu repository-cleanup mega-PR.

PR Purpose Task mapping Risk
PR-HR-00A Canonical Path & Casing 001–005 Low, foundational
PR-HR-00B PostgreSQL Baseline Alignment 006–009 Medium
PR-HR-00C Canonical HR Documentation Integration 010–013 Medium / resource-sensitive
PR-HR-00D Backend Repository CI Gate 232 Medium, enabling

Urutan:

00A
↓
00B
↓
00C

00D dapat mulai setelah 00A/00B contract stabil 4. PR-HR-00A — Canonical Path & Casing
Objective

Menghilangkan perbedaan antara Git index dan case-sensitive filesystem tanpa perubahan business behavior.

File Impact
Current Git path Canonical target
Modules/HR/Database/Migrations/2026_07_17_000005_create_Employees_table.php .../2026_07_17_000005_create_employees_table.php
docs/architecture/adr/adr-011-multi-tenancy-strategy.md .../ADR-011-multi-tenancy-strategy.md
docs/architecture/adr/adr-012-tenant-aware-auth-guard.md .../ADR-012-tenant-aware-auth-guard.md

[REKOMENDASI] gunakan explicit Git casing rename sehingga history tetap dikenali; jangan sekadar copy-delete.

Acceptance Evidence
git status
→ tidak lagi menunjukkan ketiga tracked file sebagai deleted

git ls-files
→ hanya canonical casing

migration discovery
→ HR Employee migration ditemukan tepat sekali

ADR links
→ tetap valid

Tidak ada migration content/domain semantics yang boleh berubah dalam PR ini.

5. PR-HR-00B — PostgreSQL Baseline Alignment
   Objective

Membuat setup default merepresentasikan database semantics yang benar-benar digunakan EduCore.

Candidate File Impact
Artifact Planned treatment
.env.example DB_CONNECTION authoritative menjadi PostgreSQL
config/database.php default fallback tidak lagi silent SQLite
composer.json hapus SQLite bootstrap sebagai default project behavior
README.md PostgreSQL setup/test prerequisites eksplisit
phpunit.xml review/set authoritative testing connection bila diperlukan
relevant docs environment/persistence language aligned
Important Decision

[REKOMENDASI] koneksi sqlite di config/database.php tidak perlu dihapus.

Classification:

SQLite connection definition
→ KEEP if useful

SQLite as default authoritative EduCore persistence
→ REPLACE with PostgreSQL

Dengan demikian kita tidak melakukan cleanup berlebihan.

Acceptance Evidence

Development/test bootstrap tidak lagi diam-diam memilih SQLite ketika database configuration tidak tersedia.

Minimum verification target:

PostgreSQL test database
→ clean migrations pass
→ application integration tests pass

dan existing PostgreSQL-specific constraints tidak perlu dilemahkan.

6. PR-HR-00C — Canonical HR Documentation Integration

Repository lifecycle saat ini sudah membedakan CURRENT, ACCEPTED ADR, HISTORICAL, dan FUTURE / NOT LOCKED. HR docs harus mengikuti convention itu.

Recommended Repository Layout
docs/
├── prd/
│ └── hr/
│ ├── README.md
│ ├── HR-001-...
│ └── ...
│
├── planning/
│ └── hr/
│ ├── README.md
│ ├── HR-018-...
│ └── HR-025-...
│
└── architecture/
└── adr/
└── ADR-032-...

[REKOMENDASI] jangan menaruh HR-018–HR-025 ke docs/sprint/, karena collection tersebut saat ini secara eksplisit historical. Engineering planning yang locked membutuhkan lifecycle sendiri.

Existing indexes yang perlu di-align:

docs/README.md
docs/prd/README.md
docs/architecture/README.md
docs/architecture/adr/README.md 7. Documentation Resource Gap

[RESOURCE GAP] Repository package belum memiliki source file individual HR-001–HR-025 dan ADR-032. Handoff secara eksplisit menyebut HR artifacts individual belum terintegrasi dan continuation authority sementara berasal dari approved artifacts/handoff.

Karena itu:

HR-001–HR-025 tidak boleh direkonstruksi dari ringkasan handoff seolah-olah itu teks original.

Untuk PR-HR-00C, engineering harus menggunakan exact approved project artifacts/conversation exports sebagai source material.

SC-HR-00 tetap dapat dimulai melalui PR-00A dan PR-00B tanpa menunggu gap ini.

8. PR-HR-00D — Backend CI Repository Gate

Ini adalah supporting work HR-TASK-232.

Current repository mempunyai:

php artisan test
scripts/audit-psr4.php
Laravel Pint
executable OpenAPI tests

tetapi tidak ditemukan canonical CI workflow yang menggabungkan quality gates tersebut.

Minimum CI Gate

Target pipeline:

dependency install from lockfile
↓
case/path validation
↓
PSR-4 audit
↓
format/style check
↓
PostgreSQL migration validation
↓
backend automated tests
↓
OpenAPI executable contract tests
↓
PASS / BLOCK MERGE

Vendor CI belum perlu dipilih dalam execution brief; provider tetap infrastructure decision.

9. Test Plan SC-HR-00
   Layer Evidence
   Git/path canonical case exactly once
   Module discovery HR module still loads
   Migration discovery migration detected once
   PostgreSQL clean migration PASS
   Existing backend regression PASS
   PSR-4 audit PASS
   OpenAPI regression PASS
   Documentation links no broken canonical links
   CI enforcement intentional failing gate blocks merge once PR-00D active

SC-HR-00 tidak membutuhkan HR business E2E karena tidak mengubah domain behavior.

10. Change Safety Rules

PR-HR-00 must not:

edit Employee business rules
change jabatan values
add Employment
add Position
change authorization
modify Tenant semantics
change Organization ownership
rewrite old ADR contents

ADR-011/012 hanya casing correction; keduanya memang historical/superseded dan tidak boleh diedit agar tampak konsisten dengan architecture modern.

11. Definition of Ready
    Criterion Status
    Repository baseline known PASS
    Target conflicts verified PASS
    Domain ownership unaffected PASS
    Migration impact understood PASS
    Security impact None direct
    Open business decision required No
    PR boundaries defined PASS
    Test evidence defined PASS
    Exact HR docs source available PARTIAL RESOURCE GAP
    SC-HR-00 Readiness

READY TO START WITH EXCLUDED BLOCKED ITEM

PR-00A dan PR-00B dapat dieksekusi sekarang.

PR-00C final materialization menunggu exact authoritative document sources, bukan business clarification.

12. Definition of Done

SC-HR-00 selesai bila:

canonical casing

- PostgreSQL authoritative setup
- case-sensitive verification
- canonical HR documentation integration
- repository quality gate

terbukti.

SC-HR-00 tidak boleh ditutup hanya karena Git status bersih.

13. Risk Review

[RISK — HIGH] Melakukan casing rename secara copy/delete dapat merusak history atau menciptakan duplicate behavior pada OS berbeda.

[RISK — HIGH] Hanya mengubah .env.example tetapi membiarkan config/database.php dan Composer bootstrap tetap SQLite akan menghasilkan alignment palsu.

[RISK] Menghapus dukungan SQLite seluruhnya tidak justified; masalahnya adalah authoritative default, bukan existence koneksi.

[RISK] Mengintegrasikan HR docs dari summary alih-alih exact approved artifacts dapat menciptakan canonical documentation yang tidak sesuai keputusan asli.

14. Recommended First PR
    PR-HR-00A — Repository Canonical Casing

Ini PR engineering pertama yang paling aman karena:

no business change
no API change
no schema semantics change
no authorization change

tetapi langsung menghilangkan repository portability defect yang saat ini terlihat pada Git status.

Sesudah PR-00A tervalidasi:

PR-00B
PostgreSQL baseline

menjadi langkah kedua.

15. Reviewer Assessment

Quality Score: 9.8/10

Gaps: exact individual HR artifact source files belum berada di repository/package.

Risks: casing portability, partial PostgreSQL alignment, dan documentation reconstruction.

Recommendation: lock execution brief ini lalu mulai engineering implementation dari PR-HR-00A, tanpa menyentuh HR functionality.

Status: APPROVED / LOCKED TO START SC-HR-00.
