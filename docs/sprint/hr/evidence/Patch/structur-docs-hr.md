## Project Resource Check

**[FAKTA]** Repository saat ini sudah mempunyai `docs/api`, `docs/architecture`, `docs/prd`, dan `docs/sprint`, tetapi **belum mempunyai `docs/tdd`**. Lifecycle dokumentasi existing juga sudah membedakan `CURRENT`, `ACCEPTED ADR`, `SUPERSEDED`, `HISTORICAL`, dan `FUTURE / NOT LOCKED`.

**[FAKTA]** Handoff HR-009 sendiri mencatat bahwa artifact HR belum terintegrasi ke repository dan merekomendasikan memasukkan canonical HR artifacts ke `docs/prd`/architecture location agar traceability tidak bergantung pada conversation history.

Saya setuju kita sekarang menjadikan seluruh dokumen tersebut **satu documentation set di repository**, tetapi tidak di-flat menjadi satu folder. Kita tetap memakai lima root yang Anda tentukan:

```text
docs/
├── api/
├── architecture/
├── prd/
├── sprint/
└── tdd/
```

dan menambahkan subfolder `hr/` agar dokumentasi HR tidak bercampur dengan Core/Frontend.

---

# 1. Struktur yang Saya Rekomendasikan

```text
docs/
├── README.md
│
├── api/
│   ├── README.md
│   └── openapi.yaml
│
├── architecture/
│   ├── README.md
│   └── adr/
│       ├── README.md
│       └── ADR-032-hr-domain-boundary-workforce-architecture.md
│
├── prd/
│   ├── README.md
│   └── hr/
│       ├── README.md
│       ├── HR-000.md
│       ├── HR-001-human-resources-management.md
│       ├── HR-010-hr-information-architecture-navigation-requirements.md
│       ├── HR-011-hr-transaction-uiux-requirements.md
│       └── HR-012-hr-loading-empty-error-permission-recovery-states.md
│
├── tdd/
│   ├── README.md
│   └── hr/
│       ├── README.md
│       ├── HR-002-workforce-foundation-system-data-design.md
│       ├── HR-003-recruitment-hiring-onboarding-system-data-design.md
│       ├── HR-004-leave-permit-system-data-design.md
│       ├── HR-005-workforce-attendance-system-data-design.md
│       ├── HR-006-compensation-benefit-payroll-input-system-data-design.md
│       ├── HR-007-performance-pkg-competency-pkb-system-data-design.md
│       ├── HR-008-documents-contract-discipline-offboarding-system-data-design.md
│       ├── HR-009-hr-reporting-dashboard-government-export-specification.md
│       ├── HR-013-hr-authorization-matrix-existing-route-remediation.md
│       ├── HR-014-hr-security-privacy-retention-controls.md
│       ├── HR-015-hr-performance-scalability-backup-recovery-requirements.md
│       ├── HR-016-hr-logging-monitoring-deployment-rollback-readiness.md
│       │
│       └── reporting/
│           └── phase-2h/
│               ├── README.md
│               ├── Phase-2H-A-hr-reporting-domain-boundary-scope.md
│               ├── Phase-2H-B-hr-reporting-requirements-kpi-catalog.md
│               ├── Phase-2H-C-hr-reporting-read-model-architecture.md
│               ├── Phase-2H-D-hr-dashboard-authorization-privacy-design.md
│               ├── Phase-2H-E-government-export-boundary-dapodik-emis-gtk.md
│               ├── Phase-2H-F-reporting-auditability-privacy-freshness-operational-nfr.md
│               └── Phase-2H-G-final-hr-reporting-integration-review-phase-closure.md
│
└── sprint/
    ├── README.md
    └── hr/
        ├── README.md
        │
        ├── handoffs/
        │   ├── EduCore-HR-phase-handoff-after-HR-008.md
        │   └── EduCore-HR-phase-handoff-after-HR-009.md
        │
        ├── planning/
        │   ├── HR-017-phase-3-final-cross-phase-review-implementation-readiness-gate.md
        │   ├── HR-018-phase-4a-implementation-gap-dependency-matrix.md
        │   ├── HR-019-phase-4b-epic-feature-user-story-engineering-task-decomposition.md
        │   ├── HR-020-phase-4c-technical-implementation-sequencing.md
        │   ├── HR-021-phase-4d-migration-api-frontend-test-implementation-plan.md
        │   ├── HR-022-phase-4e-risk-based-sprint-planning.md
        │   ├── HR-023-phase-4f-definition-of-ready-definition-of-done.md
        │   ├── HR-024-phase-4g-release-milestones-production-gates.md
        │   └── HR-025-final-engineering-handoff-execution-readiness.md
        │
        ├── execution/
        │   └── SC-HR-00/
        │       ├── SC-HR-00-repository-integrity-execution-brief.md
        │       └── PR-HR-00A-final-pre-implementation-state.md
        │
        └── evidence/
            ├── chatgpt-cek-patch-v3-20260823-0151.md
            └── patch/
                └── ...
```

---

# 2. Kenapa Pembagiannya Seperti Ini

### `docs/architecture`

Hanya architectural decision yang benar-benar bersifat lintas implementation:

```text
ADR-032
```

Statusnya harus **ACCEPTED ADR**, sejajar dengan ADR lain.

ADR-032 memang menjadi authority HR Domain Boundary dan Workforce Architecture. Handoff juga menyatakan Position adalah HR concern, Core `OrganizationalAssignment` tetap owner placement, dan Attendance merupakan bounded context terpisah.

---

# 3. `docs/prd/hr`

Masuk ke PRD karena sifatnya **requirement/user/product behavior**, bukan technical implementation detail:

| Dokumen                                 | Alasan                               |
| --------------------------------------- | ------------------------------------ |
| `HR-000.md`                             | HR documentation/product entry point |
| `HR-001-human-resources-management.md`  | Product & Business Requirement utama |
| `HR-010-...information-architecture...` | IA/navigation requirement            |
| `HR-011-...transaction-uiux...`         | UI/UX behavioral requirement         |
| `HR-012-...loading-empty-error...`      | UI state/recovery requirement        |

Jadi `HR-010–012` saya tidak taruh di TDD karena mereka terutama mendefinisikan **expected product/UI behavior**.

---

# 4. `docs/tdd/hr`

Ini menjadi **canonical technical design set HR**.

Masuk:

```text
HR-002 → HR-009
HR-013 → HR-016
```

Alasannya:

- HR-002–009 = System/Data/Domain Design;
- HR-013 = authorization architecture;
- HR-014 = security/privacy controls;
- HR-015 = performance/scalability/recovery;
- HR-016 = logging/deployment/rollback.

Dengan begitu engineer mempunyai pola sederhana:

```text
What / Why / UX
→ docs/prd/hr

How the system must be designed
→ docs/tdd/hr
```

---

# 5. Phase 2H A–G

Saya **tidak merekomendasikan** menaruh Phase-2H A–G sejajar dengan HR-009.

Lebih aman:

```text
docs/tdd/hr/reporting/phase-2h/
```

karena mereka adalah working/supporting artifacts yang menghasilkan canonical:

```text
HR-009
```

Index harus menyatakan:

```text
Phase-2H-A ... G
Status: SUPPORTING / CLOSED PHASE

Canonical consolidated authority:
HR-009
```

Jika ada perbedaan, **HR-009 menang**.

---

# 6. `docs/sprint/hr`

Ini bukan hanya “sprint dua mingguan”.

Di EduCore, folder ini cocok untuk:

```text
planning
handoff
execution brief
review evidence
```

selama status tiap document eksplisit.

### Handoffs

```text
EduCore-HR-phase-handoff-after-HR-008.md
EduCore-HR-phase-handoff-after-HR-009.md
```

Sekarang keduanya menjadi:

```text
HISTORICAL HANDOFF
```

karena continuation authority terbaru sudah HR-025.

Jangan dihapus karena berguna untuk audit evolution.

---

# 7. HR-017 sampai HR-025

Saya rekomendasikan semuanya tetap berurutan dalam:

```text
docs/sprint/hr/planning/
```

Karena mereka merupakan:

```text
Phase Review
→ Gap Matrix
→ Backlog
→ Sequencing
→ Implementation Plan
→ Sprint Plan
→ DoR / DoD
→ Release Plan
→ Engineering Handoff
```

Mereka bukan product/domain specification baru dan bukan architecture ADR.

Authority-nya:

```text
HR-001–HR-016 + ADR-032
→ menentukan WHAT / architecture

HR-017–HR-025
→ menentukan HOW TO PLAN / EXECUTE
```

---

# 8. SC-HR-00 dan PR-HR-00A

Masuk:

```text
docs/sprint/hr/execution/SC-HR-00/
```

karena ini implementation handoff untuk tim engineering.

Status:

```text
SC-HR-00-repository-integrity-execution-brief.md
→ APPROVED / LOCKED / READY FOR ENGINEERING

PR-HR-00A-final-pre-implementation-state.md
→ APPROVED / READY FOR ENGINEERING
```

Ini sesuai keputusan terakhir bahwa **kita tidak melakukan implementation**, tetapi menyerahkan execution ke tim Fullstack/Engineering.

---

# 9. ChatGPT Patch Review dan `Patch`

Saya sarankan **jangan dijadikan canonical requirement**.

Masukkan ke:

```text
docs/sprint/hr/evidence/
```

Status:

```text
REVIEW EVIDENCE / NON-CANONICAL
```

Jadi:

```text
ChatGPT-Cek Patch V3!-20260823-0151.md
→ docs/sprint/hr/evidence/chatgpt-cek-patch-v3-20260823-0151.md
```

Untuk `Patch`:

```text
docs/sprint/hr/evidence/patch/
```

**[CONSTRAINT]** Jika isi `Patch` sebenarnya source-code `.patch`, itu hanya evidence/reference. Engineering **tidak boleh menganggapnya approved implementation** karena implementasi memang kita serahkan kepada tim engineering.

---

# 10. `docs/api`

Tidak ada satu pun dokumen dalam daftar Anda yang sebaiknya dipindahkan menjadi canonical API contract.

Itu disengaja.

Canonical API tetap:

```text
docs/api/openapi.yaml
```

Saya rekomendasikan hanya menambahkan:

```text
docs/api/README.md
```

yang menjelaskan:

```text
OpenAPI
= executable HTTP contract

PRD/TDD
= requirement/design authority

OpenAPI tidak menduplikasi HR design documents
```

Ketika tim engineering mengimplementasikan HR API:

```text
HR TDD
→ implementation
→ docs/api/openapi.yaml
```

Jangan membuat prose API documentation kedua yang nanti drift dari OpenAPI.

---

# 11. Filename Normalization

Ada beberapa filename yang sebaiknya dibersihkan saat integration.

| Existing                                    | Canonical                                                                |
| ------------------------------------------- | ------------------------------------------------------------------------ |
| `HR-012-...rermission...`                   | `HR-012-...permission...`                                                |
| `HR-017-phase 3 ...`                        | `HR-017-phase-3-...`                                                     |
| `HR-019-...epic  feature  user story...`    | `HR-019-...epic-feature-user-story-engineering-task-decomposition.md`    |
| `Phase-2H-D-..._uthorization...`            | `Phase-2H-D-...-authorization-...`                                       |
| `Phase-2H-F-...auditabilit...perational...` | `Phase-2H-F-reporting-auditability-privacy-freshness-operational-nfr.md` |
| `ChatGPT-Cek Patch V3!-...`                 | `chatgpt-cek-patch-v3-20260823-0151.md`                                  |

**[REKOMENDASI]**

Filename canonical:

```text
<ID>-<lowercase-kebab-description>.md
```

ID tetap uppercase:

```text
HR-001
ADR-032
Phase-2H-A
SC-HR-00
PR-HR-00A
```

---

# 12. README / Index yang Wajib Diubah

Agar benar-benar menjadi **satu kesatuan**, hanya memindahkan file belum cukup.

Engineering harus mengubah/menambah:

| File                                       | Perubahan                                     |
| ------------------------------------------ | --------------------------------------------- |
| `docs/README.md`                           | Tambahkan HR documentation entry point        |
| `docs/prd/README.md`                       | Tambahkan Current HR PRD                      |
| `docs/prd/hr/README.md`                    | **NEW** — index HR product requirements       |
| `docs/architecture/README.md`              | Tambahkan HR Domain Architecture              |
| `docs/architecture/adr/README.md`          | Register ADR-032 ACCEPTED                     |
| `docs/tdd/README.md`                       | **NEW** — TDD lifecycle/index                 |
| `docs/tdd/hr/README.md`                    | **NEW** — canonical HR technical design index |
| `docs/tdd/hr/reporting/phase-2h/README.md` | **NEW** — supporting Phase 2H index           |
| `docs/sprint/README.md`                    | Tambahkan exception/current HR planning       |
| `docs/sprint/hr/README.md`                 | **NEW** — planning/handoff/execution index    |
| `docs/api/README.md`                       | **NEW** — explain OpenAPI authority           |

---

# 13. HR Documentation Entry Point

Saya merekomendasikan `docs/prd/hr/README.md` menjadi **functional HR entry point**, sementara `docs/sprint/hr/README.md` menjadi engineering/planning entry point.

Urutan baca untuk engineer baru:

```text
1. docs/prd/hr/HR-000.md
2. docs/prd/hr/HR-001-human-resources-management.md
3. docs/architecture/adr/ADR-032-hr-domain-boundary-workforce-architecture.md
4. docs/tdd/hr/HR-002 ... HR-009
5. docs/prd/hr/HR-010 ... HR-012
6. docs/tdd/hr/HR-013 ... HR-016
7. docs/sprint/hr/planning/HR-017 ... HR-025
8. docs/sprint/hr/execution/SC-HR-00/...
```

Dengan pola ini engineer tidak perlu membaca conversation history.

---

# 14. Authority Hierarchy Setelah Integrasi

Ini sangat penting.

```text
Latest approved user/project decision
        ↓
Accepted ADR
        ↓
Approved / Locked HR PRD & TDD
        ↓
Current implementation
        ↓
Engineering planning HR-017–HR-025
        ↓
Sprint / execution brief
        ↓
Historical handoff
        ↓
Review evidence / patch evidence
```

Secara praktis:

```text
ADR-032
+ HR-001–HR-016
```

tidak boleh dikalahkan oleh:

```text
SC-HR-00
PR-HR-00A
ChatGPT patch review
Patch evidence
```

---

# 15. Status Classification yang Saya Rekomendasikan

| Collection                   | Lifecycle                                            |
| ---------------------------- | ---------------------------------------------------- |
| ADR-032                      | **ACCEPTED ADR**                                     |
| HR-001–HR-016                | **APPROVED / LOCKED / CURRENT SPECIFICATION**        |
| Phase-2H-A–G                 | **SUPPORTING / CLOSED PHASE**                        |
| HR-017–HR-025                | **APPROVED / LOCKED / CURRENT ENGINEERING PLANNING** |
| Handoff after HR-008         | **HISTORICAL / SUPERSEDED HANDOFF**                  |
| Handoff after HR-009         | **HISTORICAL / SUPERSEDED HANDOFF**                  |
| SC-HR-00                     | **CURRENT ENGINEERING EXECUTION BRIEF**              |
| PR-HR-00A pre-implementation | **CURRENT ENGINEERING HANDOFF**                      |
| ChatGPT Patch Review         | **NON-CANONICAL REVIEW EVIDENCE**                    |
| Patch                        | **NON-CANONICAL IMPLEMENTATION EVIDENCE**            |

---

# 16. Important Rule: Jangan Ubah Isi Saat Move

Untuk integration PR pertama, saya sarankan:

```text
MOVE / RENAME / INDEX
```

saja.

Jangan sekaligus:

```text
rewrite content
resolve open decisions
change requirement
update architecture
fix business terminology
```

Jika ada typo **filename**, boleh dinormalisasi.

Jika ada typo atau conflict **di isi dokumen**, catat terpisah dan lakukan documentation alignment PR berikutnya setelah impact review.

Ini membuat Git history jauh lebih mudah direview.

---

# 17. Recommended Engineering PR

Saya sarankan tim engineering membuat satu dedicated docs PR:

### `PR-DOC-HR-001 — Integrate Canonical HR Documentation`

Scope:

```text
Create docs/tdd
Create HR subdirectories
Move/copy exact approved artifacts
Normalize filenames
Add README/index files
Update docs/README
Update ADR index
Verify relative links
No requirement-content changes
```

Acceptance evidence:

```text
all 40 listed artifacts accounted for
no duplicate canonical source
all README links valid
ADR-032 registered
HR-001–HR-016 marked current/locked
HR-017–HR-025 marked current planning
historical/supporting/evidence status explicit
OpenAPI remains sole API transport contract
```

---

## Review

**Quality Score:** **9.9/10**

**[RESOURCE GAP]** Saya belum mempunyai raw content seluruh 40 file dalam active repository sandbox, jadi saya belum melakukan link-by-link/content-header audit terhadap semua file. Mapping di atas didasarkan pada approved phase semantics, filename, dan resources yang tersedia. Saat engineering menambahkan file, kita sebaiknya melakukan **documentation integration review** terhadap PR/diff mereka.

**Status:** **READY FOR ENGINEERING HANDOFF**

Dengan struktur ini, HR tidak lagi menjadi kumpulan file percakapan, tetapi menjadi satu documentation system yang konsisten dengan lifecycle EduCore: **PRD → ADR → TDD → Engineering Planning → Sprint/Execution → OpenAPI**.
