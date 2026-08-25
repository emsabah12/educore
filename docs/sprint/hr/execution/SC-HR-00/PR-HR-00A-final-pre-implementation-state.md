PR-HR-00A — Final Pre-Implementation State

[FAKTA] Repository HEAD

26b475b695aa4511064b1410db03d1f0c8bdd6ce

[FAKTA] Case-sensitive filesystem mereproduksi persis 3 mismatch yang sudah kita identifikasi:

Git tracked File aktual di archive Keputusan
Modules/HR/Database/Migrations/2026_07_17_000005_create_Employees_table.php .../2026_07_17_000005_create_employees_table.php Normalize ke lowercase employees
docs/architecture/adr/adr-011-multi-tenancy-strategy.md ADR-011-multi-tenancy-strategy.md Normalize ke ADR-011...
docs/architecture/adr/adr-012-tenant-aware-auth-guard.md ADR-012-tenant-aware-auth-guard.md Normalize ke ADR-012...

[FAKTA] Neighboring conventions mendukung target tersebut:

migration HR existing menggunakan lowercase create_employees_table.php;
ADR-009, ADR-010, ADR-013 dan ADR lain menggunakan prefix uppercase ADR-\*;
tidak ditemukan reference tekstual repository yang bergantung pada filename casing lama ADR-011/ADR-012.

Jadi blast radius PR ini rendah dan tidak memerlukan perubahan domain/API/schema.

Exact PR Scope

PR-HR-00A — Repository Canonical Casing

In scope hanya:

3 Git-aware casing renames

- case-sensitive repository verification
- migration/module regression verification

Explicitly out:

Employee business logic
HR authorization
jabatan
Employment
Position
database schema content
ADR content
PostgreSQL configuration

PR-HR-00B tetap menjadi perubahan terpisah.

Acceptance Gate

PR-HR-00A baru dianggap DONE jika:

git status tidak lagi melaporkan ketiga tracked file sebagai deleted karena casing;
git ls-files hanya memiliki canonical casing;
Employee migration terdeteksi tepat satu kali;
HR module tetap dapat ditemukan/load;
tidak ada content change pada migration maupun ADR selain path rename;
relevant regression tests tetap lulus;
repository tetap clean pada case-sensitive environment.
Risk Classification

[RISK — LOW] Business/architecture: tidak ada perubahan behavior.

[RISK — MEDIUM] Repository: rename yang dilakukan dengan mekanisme filesystem yang salah dapat gagal dikenali Git, terutama jika developer memakai case-insensitive filesystem. Karena itu implementasinya harus berupa explicit Git-aware rename, bukan copy/delete manual.

Execution Status
SC-HR-00
├── PR-HR-00A Canonical Casing
│ └── READY FOR IMPLEMENTATION
├── PR-HR-00B PostgreSQL Baseline
│ └── READY AFTER 00A
├── PR-HR-00C HR Documentation Integration
│ └── PARTIALLY RESOURCE-BLOCKED
└── PR-HR-00D Backend CI Gate
└── PLANNED

Quality Score: 10/10
Critical Gap: none untuk PR-HR-00A.
Status: READY FOR ENGINEERING IMPLEMENTATION
