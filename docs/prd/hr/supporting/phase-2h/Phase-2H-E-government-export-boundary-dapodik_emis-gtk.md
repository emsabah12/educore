# Phase 2H-E — Government Export Boundary: Dapodik & EMIS GTK

## 1. Status Fase Sebelumnya

**2H-D — HR Dashboard, Authorization & Privacy Design: APPROVED / LOCKED.**

Phase 2H-E mengikuti boundary yang sudah ditetapkan pada handoff:

> Government integration adalah projection dari canonical EduCore data dan tidak boleh menjadikan schema pemerintah sebagai canonical internal model. Direct synchronization juga tidak boleh diasumsikan sebelum interface resminya diverifikasi.

---

# 2. Resource Audit Terkini — 22 Agustus 2026

Hasil verifikasi resource resmi menghasilkan satu perubahan penting terhadap terminology handoff.

## 2.1 Dapodik

**[FAKTA]** Dapodik masih aktif sebagai sistem pendataan Kemendikdasmen. Rilis resmi 2026 menunjukkan pola operasional berupa aplikasi satuan pendidikan, validasi, tarik data, dan **sinkronisasi** ke server Dapodik. Portal publik juga sudah menunjukkan data Semester Ganjil 2026/2027 yang tersinkronisasi. ([Dapo Kemendikdasmen][1])

**[RESOURCE GAP]** Dari resource resmi terkini yang berhasil diverifikasi, belum ditemukan public external **write API contract** yang dapat kita jadikan authority untuk EduCore mengirim data langsung ke Dapodik.

Karena itu:

```text
EduCore → Dapodik Direct API
```

**belum boleh menjadi baseline architecture.**

---

## 2.2 Simpatika → EMIS GTK

Ini menghasilkan delta paling signifikan.

Surat resmi Direktorat GTK Madrasah tanggal **10 Januari 2025** menetapkan EMIS 4.0 GTK Madrasah sebagai pengganti Simpatika untuk pendataan dan validasi GTK. ([Pendis][2])

Pada 2026, Kemenag terus melakukan implementasi platform **EMIS GTK baru**. Sumber Kemenag Juli–Agustus 2026 menunjukkan platform tersebut sedang dipakai untuk pengelolaan GTK dan sosialisasi implementasinya terus berjalan. ([Kementerian Agama Kota Depok][3])

Bahkan Kemenag menyebut transisi dari Simpatika menuju EMIS GTK sebagai arah implementasi 2026. ([Jabar Kemenag][4])

### [CONFLICT / SUPERSEDED TERMINOLOGY]

Handoff masih menyebut:

```text
Dapodik / EMIS / Simpatika
```

Untuk desain baru kita sebaiknya menggunakan:

```text
Dapodik
EMIS / EMIS GTK
Legacy Simpatika
```

**SIMPATIKA tidak lagi saya rekomendasikan sebagai target integrasi baru.**

Ia diperlakukan sebagai:

```text
LEGACY / TRANSITIONAL EXTERNAL SYSTEM
```

bukan canonical future integration target.

---

# 3. External-System Classification

| External System | Status EduCore                          | Recommendation                          |
| --------------- | --------------------------------------- | --------------------------------------- |
| Dapodik         | Active government system                | **EXPORT/ASSISTED INTEGRATION first**   |
| EMIS            | Active Kemenag education data ecosystem | **EXPORT/ASSISTED INTEGRATION first**   |
| EMIS GTK        | Active/evolving GTK platform            | **PRIMARY Kemenag HR external target**  |
| Simpatika       | Legacy/transitional                     | **DO NOT BUILD NEW DIRECT INTEGRATION** |

---

# 4. Architecture Decision

Saya rekomendasikan baseline berikut.

## GOV-ARCH-001 — Adapter-Based Government Export

```text
             EduCore Canonical Domains
                      │
          ┌───────────┼───────────┐
          ▼           ▼           ▼
         Core         HR       Academic
          │           │           │
          └───────────┼───────────┘
                      ▼
             Government Dataset
                  Builder
                      │
                      ▼
               Validation Layer
                      │
             ┌────────┴────────┐
             ▼                 ▼
       Dapodik Adapter     EMIS GTK Adapter
             │                 │
             ▼                 ▼
      Export Package      Export Package
```

External system tidak boleh mengakses internal schema secara langsung.

---

# 5. Government Schema Tidak Menentukan EduCore Domain

## GOV-BR-001

Tidak boleh melakukan:

```text
employees
├── dapodik_status
├── simpatika_status
├── emis_gtk_category
├── dapodik_jabatan
└── emis_gtk_id_2
```

hanya karena external system membutuhkan field tersebut.

Preferred:

```text
Canonical HR Data
       ↓
Mapping
       ↓
External Representation
```

Contoh:

```text
Employment Type
      │
      ▼
Dapodik-specific employment code

Employment Type
      │
      ▼
EMIS-GTK-specific employment code
```

Mapping tersebut bukan canonical Employment Type.

---

# 6. Export ≠ Source of Truth

## GOV-BR-002

Hasil mapping pemerintah adalah:

```text
derived representation
```

bukan HR fact.

Contoh:

```text
EduCore Position:
Guru Matematika
```

dapat dipetakan menjadi external code tertentu.

Namun jika kode pemerintah berubah:

```text
old external code → new external code
```

EduCore Position tidak ikut berubah hanya karena perubahan schema eksternal.

---

# 7. Integration Maturity Levels

Untuk menghindari overengineering, saya rekomendasikan capability bertahap.

### Level 0 — Reporting Only

```text
EduCore
→ compliance preview
```

Tidak menghasilkan file.

---

### Level 1 — Export Package

```text
EduCore
→ validated dataset
→ CSV/XLSX/JSON/format resmi
→ operator government system
```

Ini baseline paling aman.

---

### Level 2 — Assisted Submission

Jika pemerintah menyediakan mekanisme import yang terdokumentasi:

```text
EduCore
→ official import format
→ operator uploads
```

---

### Level 3 — Direct Integration

Hanya jika tersedia kontrak resmi:

```text
EduCore
→ authenticated official API
→ government system
```

Dengan:

- authentication specification;
- authorization;
- rate limit;
- schema/version contract;
- acknowledgement;
- error codes;
- idempotency;
- support policy.

### Keputusan

**Phase 2H-E menetapkan Level 1 sebagai baseline.**

Level 2/3:

**[DEFERRED UNTIL OFFICIAL CONTRACT VERIFIED]**

---

# 8. Why Level 1 First?

Alasannya bukan hanya karena API belum terverifikasi.

Export package juga memberikan:

- human review;
- validation sebelum submission;
- reproducibility;
- lower coupling;
- version isolation;
- easier troubleshooting;
- kontrol terhadap sensitive PII.

Untuk government system yang frequently evolving, ini memberikan architecture yang lebih tahan perubahan.

---

# 9. Government Export Domain Model

Saya rekomendasikan konsep berikut.

```text
GovernmentExportDefinition
          │
          ▼
GovernmentExportRun
          │
          ├── Dataset
          ├── Validation Results
          ├── Mapping Version
          └── Artifact
```

Ini masih conceptual model, belum schema database final.

---

# 10. Export Definition

`GovernmentExportDefinition` menjelaskan:

```text
target_system
dataset_type
definition_version
mapping_version
effective_from
effective_until
required_fields
validation_profile
artifact_format
```

Contoh conceptual:

```text
target_system:
DAPODIK

dataset_type:
GTK

definition_version:
2026_2027_V1
```

---

# 11. Export Run

Setiap proses export menjadi immutable execution record.

Minimal conceptual metadata:

```text
export_run_id
tenant_id
organization_scope
target_system
dataset_type
definition_version
mapping_version
requested_by
requested_at
source_as_of
generated_at
status
artifact_reference
```

Status candidate:

```text
REQUESTED
BUILDING
VALIDATION_FAILED
READY
EXPORTED
FAILED
```

`SUBMITTED` belum otomatis dipakai karena EduCore belum mempunyai authority mengetahui apakah file benar-benar diterima pemerintah.

---

# 12. Frozen Dataset Principle

Kita lock prinsip 2H-C:

```text
Canonical Data at T
        ↓
Frozen Export Dataset
        ↓
External Artifact
```

## GOV-BR-003

Export yang sudah dibuat tidak boleh berubah ketika canonical data berubah kemudian.

Misalnya:

```text
22 Aug:
Nama = Ahmad

25 Aug:
Nama diperbaiki = Ahmad Fauzi
```

Export 22 Agustus tetap merepresentasikan data yang dipakai saat export 22 Agustus.

Jika diperlukan data terbaru:

```text
create new Export Run
```

---

# 13. Mapping Layer

Mapping harus explicit dan versioned.

Concept:

```text
Internal Value
     ↓
Mapping Rule
     ↓
External Code
```

Contoh:

```text
EduCore Employment Classification
           ↓
DAPODIK GTK Mapping v2026
           ↓
External Value
```

Tidak boleh tersebar sebagai kondisi:

```text
if target == DAPODIK ...
if target == EMIS ...
```

di seluruh HR domain.

---

# 14. Three Mapping Outcomes

Setiap field mapping harus menghasilkan:

### MAPPED

```text
internal value
→ valid external value
```

### UNMAPPED

Internal value ada tetapi tidak memiliki external mapping.

### NOT_APPLICABLE

Field eksternal tidak berlaku pada record tersebut.

Ini harus dibedakan.

---

# 15. Jangan Memetakan dengan Silent Fallback

Tidak boleh:

```text
Unknown position
→ "OTHER"
```

secara otomatis kecuali external specification memang mendefinisikan fallback tersebut.

## GOV-BR-004

Unmapped mandatory data harus menjadi validation error.

---

# 16. Validation Architecture

Saya rekomendasikan dua lapisan.

```text
Canonical Validation
       ↓
Export Mapping
       ↓
External Validation
```

### Canonical validation

Menjawab:

> Apakah data EduCore sendiri valid?

Contoh:

- Employment valid;
- Person valid;
- organizational assignment valid.

### External validation

Menjawab:

> Apakah data memenuhi requirement target tertentu?

Contoh:

```text
required external identifier missing
external enum mapping unavailable
format invalid
required government field incomplete
```

---

# 17. External Validation Tidak Membatalkan Canonical Validity

Misalnya:

```text
Employee valid di EduCore
```

tetapi belum mempunyai salah satu identifier wajib Dapodik.

Statusnya:

```text
EduCore Employee = VALID
Dapodik Export Readiness = NOT READY
```

Bukan:

```text
Employee = INVALID
```

## GOV-BR-005

Government compliance completeness adalah external-readiness concern.

---

# 18. Export Readiness

Kita dapat mempunyai:

```text
READY
READY_WITH_WARNING
BLOCKED
NOT_APPLICABLE
```

per target.

Contoh:

```text
Employee A

Dapodik:
BLOCKED

EMIS GTK:
READY
```

Hal tersebut legitimate karena kedua sistem mempunyai requirement berbeda.

---

# 19. Dapodik Boundary

Dapodik 2026 tetap menggunakan aplikasi pendataan satuan pendidikan dan workflow validasi/sinkronisasi. ([Dapo Kemendikdasmen][5])

Maka baseline EduCore:

```text
EduCore
   ↓
Dapodik Dataset Validation
   ↓
Dapodik-assisted Export
   ↓
Authorized Operator
   ↓
Official Dapodik Workflow
```

## Tidak kita lakukan sekarang

```text
EduCore
→ directly modifies Dapodik DB

EduCore
→ automates unofficial UI/browser

EduCore
→ reverse-engineers private endpoints

EduCore
→ writes to Dapodik without official contract
```

---

# 20. Dapodik Ownership Boundary

Dapodik mencakup jauh lebih banyak daripada HR.

Contoh:

```text
Institution
Students
GTK
Learning groups
Curriculum
Facilities
etc.
```

Phase HR hanya bertanggung jawab atas **HR-owned contribution**.

```text
HR
→ Employee/Employment/Position facts

Academic
→ Teaching assignments / learning facts

Core
→ Organization/person identity context
```

### Important

HR Government Export tidak boleh menjadi owner seluruh Dapodik integration.

---

# 21. Future Dapodik Integration Ownership

Jika EduCore nanti mempunyai integrasi Dapodik menyeluruh, lebih masuk akal membuat:

```text
Government Integration
or
Education Data Exchange
```

sebagai platform capability lintas domain.

Bukan:

```text
Modules/HR/Dapodik
```

untuk seluruh Dapodik.

### Classification

Untuk sekarang:

**HR Reporting → contributes HR dataset**

Future consolidated Dapodik integration:

**FUTURE CROSS-DOMAIN CAPABILITY**

---

# 22. EMIS / EMIS GTK Boundary

EMIS adalah data ecosystem Pendidikan Islam dan Kemenag sendiri menyatakan penggunaan data lintas aplikasi dilakukan melalui mekanisme komunikasi data yang disepakati. ([Kemenag][6])

Tetapi resource publik yang kita audit belum memberikan external partner API contract yang cukup untuk membangun direct EduCore integration.

Maka:

```text
EduCore HR
    ↓
EMIS GTK Mapping
    ↓
Validation
    ↓
Export/Assisted Submission
```

menjadi baseline.

---

# 23. Simpatika Boundary

Karena EMIS GTK telah menggantikan fungsi pendataan GTK Simpatika sejak 2025 dan transisi terus berlangsung di 2026, kita klasifikasikan:

```text
SIMPATIKA
=
LEGACY_EXTERNAL_SYSTEM
```

([Pendis][2])

## GOV-ARCH-002

Jangan membangun:

```text
SimpatikaApiClient
SimpatikaSyncService
```

untuk development baru kecuali ada kebutuhan transitional yang eksplisit dan masih didukung secara resmi.

---

# 24. Legacy Simpatika Data

Jika nanti institusi memerlukan data lama Simpatika:

itu menjadi:

```text
migration/import concern
```

bukan ongoing synchronization concern.

Classification:

**DEFERRED — legacy migration only if required.**

---

# 25. Identity Mapping

Government export sangat sensitif terhadap identity.

Namun:

```text
Person
Membership
Employee
```

tetap canonical EduCore.

Identifier eksternal harus diperlakukan sebagai **external identifier**, bukan identity utama.

Concept:

```text
Employee
   │
   ├── External Identifier: DAPODIK / ...
   └── External Identifier: EMIS_GTK / ...
```

Tetapi detail schema external identifier perlu kita desain kemudian bersama broader identity/data integration strategy.

---

# 26. [OPEN DECISION] External Identifier Registry

Ada dua pilihan.

### Option A — HR-specific

```text
HR External Identifier
```

Lebih sederhana sekarang.

### Option B — Core generic external identity registry

```text
Person/Entity
→ ExternalIdentifier
```

Lebih reusable lintas domain.

### Recommendation

**Jangan putuskan di 2H-E.**

Karena Dapodik/EMIS tidak hanya mempunyai employee identifier; peserta didik dan organization juga akan memerlukan identifier eksternal.

Kemungkinan besar ini harus menjadi **Core/platform integration concern**, bukan HR-specific table.

---

# 27. Multi-Institution Reality

Ini relevan dengan model EduCore sebelumnya.

Satu Employee dapat secara bisnis terkait dengan organizational placement tertentu, tetapi external government reporting dapat mengikuti unit/satuan pendidikan tertentu.

Maka mapping harus:

```text
Person
   ↓
Employee
   ↓
Employment
   ↓
Placement at T
   ↓
External Institution Mapping
```

Bukan:

```text
Employee
→ one permanent Dapodik school
```

---

# 28. Historical Effective Mapping

Seperti historical reporting:

```text
External representation at T
```

harus memakai organizational/employment condition pada `T`.

Jika guru berpindah:

```text
2025 School A
2026 School B
```

export semester 2025 tidak boleh memakai current School B.

---

# 29. Organizational External Identifier

Dapodik dan EMIS mempunyai identity institusi masing-masing.

EduCore Organization tetap canonical.

Concept:

```text
EduCore Organization
        │
        ├── DAPODIK identifier
        └── EMIS identifier
```

Jangan mengubah:

```text
organizations.id
```

menjadi NPSN/NSM/external ID.

---

# 30. Export Scope

Government export harus selalu scoped:

```text
Tenant
+
Organization/Institution
+
Reporting Period
+
Target System
```

Tidak ada:

```text
export all tenant data
```

secara implisit.

---

# 31. Authorization

Mengikuti 2H-D:

```text
hr.reporting.government-export.view
hr.reporting.government-export.export
```

Namun saya merekomendasikan target-specific permission bila kebutuhan berkembang:

```text
hr.reporting.dapodik.export
hr.reporting.emis-gtk.export
```

karena operator dan responsibility dapat berbeda.

---

# 32. Export Does Not Imply Submission Authority

User yang boleh:

```text
Generate Dapodik export
```

belum tentu boleh:

```text
submit/synchronize Dapodik
```

Ke depannya jika submission integration tersedia:

```text
VIEW
EXPORT
SUBMIT
```

adalah permission berbeda.

---

# 33. Privacy

Dataset pemerintah kemungkinan mengandung PII yang tinggi.

Maka government export:

**Sensitivity S4 — Highly Restricted.**

Aturan:

- private storage;
- explicit permission;
- scoped artifact;
- encrypted transport jika integration tersedia;
- no public URL;
- expiration/retention policy;
- audit generation/download;
- minimize data sesuai target definition.

---

# 34. Export Artifact Tidak Boleh Berisi “Semua HR Data”

Target mapper harus whitelist-based.

Preferred:

```text
allowed target fields
→ populate
```

bukan:

```text
Employee JSON
→ remove a few private fields
```

## GOV-PRIV-001

External export menggunakan explicit field allow-list.

---

# 35. Download Audit

Minimal event:

```text
GOV_EXPORT_GENERATED
GOV_EXPORT_DOWNLOADED
```

Jika suatu hari ada direct submission:

```text
GOV_EXPORT_SUBMITTED
GOV_EXPORT_ACCEPTED
GOV_EXPORT_REJECTED
```

Tetapi `ACCEPTED` hanya dapat digunakan jika government endpoint memberikan authoritative acknowledgement.

---

# 36. Reconciliation Boundary

Pada Level 1/manual submission, EduCore tidak boleh menyimpulkan:

```text
Export generated
=
government data synchronized
```

Status harus dipisahkan:

```text
READY
EXPORTED
```

dari:

```text
SUBMITTED
ACCEPTED
```

Jika tidak ada proof resmi:

status eksternal tetap:

```text
UNKNOWN / NOT_TRACKED
```

---

# 37. Error Taxonomy

Saya rekomendasikan baseline:

```text
SOURCE_DATA_INVALID
REQUIRED_DATA_MISSING
MAPPING_MISSING
EXTERNAL_FORMAT_INVALID
EXPORT_GENERATION_FAILED
```

Future direct integration dapat menambahkan:

```text
AUTHENTICATION_FAILED
RATE_LIMITED
EXTERNAL_REJECTED
EXTERNAL_UNAVAILABLE
```

---

# 38. No Automatic Canonical Mutation on External Rejection

Jika pemerintah menolak:

```text
External system:
Invalid XYZ
```

EduCore tidak boleh otomatis mengubah canonical data.

Flow:

```text
Government rejection
       ↓
Integration Issue
       ↓
Human review
       ↓
Owning Domain
       ↓
Authorized correction
```

---

# 39. Mapping Conflict

Jika EduCore dan government system berbeda:

```text
EduCore value = A
Government = B
```

jangan otomatis memilih pemerintah sebagai truth.

Harus diketahui data mana yang authoritative untuk business concern tersebut.

Status:

```text
MAPPING/DATA CONFLICT
```

dan diarahkan ke domain owner.

---

# 40. External Versioning

## GOV-ARCH-003

Setiap adapter mempunyai external specification version.

Contoh conceptual:

```text
DAPODIK_GTK_2026_2027_V1
EMIS_GTK_2026_V1
```

Jika aturan berubah:

```text
V1
→ V2
```

Export run lama tetap menunjuk V1.

---

# 41. Change Impact Saat Pemerintah Mengubah Format

Adapter design memungkinkan:

```text
Canonical Domain
      │
      ├── old mapper
      │
      └── new mapper
```

tanpa migration canonical HR besar.

Ini adalah alasan utama tidak memasukkan external schema ke internal entities.

---

# 42. Proposed Responsibility Map

Belum folder implementation final, tetapi conceptual:

```text
Modules/
└── HR/
    └── Reporting/
        └── Exports/
            └── Government/
                ├── Dataset
                ├── Validation
                └── Contracts
```

Target adapter yang benar-benar HR-specific dapat berada di sana.

Tetapi jika nanti integrasi melibatkan:

```text
Student
Academic
Institution
Facilities
```

adapter harus diekstrak menjadi cross-domain integration capability.

---

# 43. IN SCOPE Phase 2H-E

- government export boundary;
- Dapodik HR contribution;
- EMIS/EMIS GTK HR contribution;
- Simpatika legacy classification;
- mapping/version rules;
- external validation;
- export lifecycle;
- authorization/privacy;
- auditability;
- frozen dataset;
- error/reconciliation model.

---

# 44. OUT OF SCOPE

- browser automation;
- scraping government applications;
- unofficial API reverse engineering;
- direct database writes;
- automatic Dapodik synchronization;
- automatic EMIS synchronization;
- student government export detail;
- curriculum/rombel export;
- facilities export;
- government credential storage implementation.

---

# 45. FUTURE SCOPE

Jika official interfaces tersedia:

```text
Government Integration Gateway
        │
        ├── Dapodik
        ├── EMIS
        └── other government systems
```

dapat menjadi cross-domain platform capability.

---

# 46. DEFERRED

**[OPEN DECISION]**

- exact Dapodik HR field mapping;
- exact EMIS GTK field mapping;
- official import file formats;
- external identifier registry design;
- artifact retention;
- government credential model;
- submission workflow;
- acknowledgement/reconciliation;
- direct API integration.

Kita tidak boleh mengarang field mapping sampai authoritative specification tersedia.

---

# 47. Change terhadap Handoff

Ada satu formal delta yang saya rekomendasikan.

### Sebelumnya

```text
Dapodik / EMIS / Simpatika export boundary
```

### Menjadi

```text
Dapodik / EMIS–EMIS GTK export boundary
+
Simpatika legacy compatibility only
```

### Reason

EMIS 4.0 GTK secara resmi diumumkan sebagai pengganti Simpatika sejak Januari 2025, dan implementasi EMIS GTK baru berlanjut pada 2026. ([Pendis][2])

Klasifikasi:

**SIMPATIKA TARGET → DEPRECATE**

**EMIS GTK TARGET → REPLACE / PRIMARY**

Ini bukan perubahan terhadap HR domain architecture; hanya perubahan external target lifecycle.

---

# 48. Target Architecture

```text
                     EduCore
                        │
          ┌─────────────┼─────────────┐
          │             │             │
         Core           HR         Academic
          │             │             │
          └─────────────┼─────────────┘
                        ▼
              Government Dataset
                    Builder
                        │
                        ▼
               Frozen Dataset
                        │
                        ▼
              External Validation
                        │
           ┌────────────┴────────────┐
           ▼                         ▼
    Dapodik Mapper              EMIS GTK Mapper
           │                         │
           ▼                         ▼
      Export Artifact           Export Artifact
           │                         │
           ▼                         ▼
    Authorized Operator        Authorized Operator
           │                         │
           ▼                         ▼
 Official Government       Official Government
      Workflow                  Workflow


Legacy SIMPATIKA
      ↓
migration/compatibility only
```

---

# 49. Reviewer Mode — Phase 2H-E

**Quality Score: 9.6/10**

### Gaps

**[RESOURCE GAP]** Belum ditemukan authoritative public external API/write contract untuk EduCore melakukan direct Dapodik atau EMIS GTK synchronization.

**[RESOURCE GAP]** Exact field-level government export/import specifications belum cukup terverifikasi untuk kita lock.

### Risks

**[RISK — HIGH]** Membuat direct integration berdasarkan undocumented/private API akan menghasilkan brittle coupling dan berpotensi melanggar workflow resmi.

**[RISK — HIGH]** Government schema berubah dan mencemari canonical HR schema bila mapping tidak dipisahkan.

**[RISK]** Simpatika dapat keliru diperlakukan sebagai target baru padahal arah resmi sudah menuju EMIS GTK.

**[RISK]** Export mengandung PII tinggi dan membutuhkan kontrol akses/audit yang ketat.

### Recommendations

Lock architecture pada:

> **canonical data → versioned mapping → validation → frozen export → authorized official government workflow.**

Direct synchronization baru boleh dibuka melalui ADR/change request ketika kontrak resmi benar-benar tersedia.

### Status

**READY FOR APPROVAL — EXTERNAL FIELD SPECIFICATION DEFERRED**

Dengan baseline ini, **2H-E dapat dikunci tanpa menunggu API pemerintah**. Tahap berikutnya adalah **Phase 2H-F — Reporting Auditability, Privacy, Freshness & Operational NFR**, yang akan menyatukan audit evidence, retention, observability, freshness SLA framework, failure/recovery, performance, dan security operational sebelum final integration review 2H-G.

[1]: https://dapo.kemendikdasmen.go.id/berita/rilis-aplikasi-dapodik-versi-2026-c?utm_source=chatgpt.com "Rilis Aplikasi Dapodik versi 2026.c - Pauddikdasmen"
[2]: https://pendis.kemenag.go.id/storage/archives/01JHHYZJDMA4P0EANWJWSY6RF2.pdf?utm_source=chatgpt.com "-
KEMENTERIAN AGAMA REPUBLIK INDONESIA
DIREKTORAT"
[3]: https://depok.kemenag.go.id/kakankemenag-depok-buka-implementasi-emis-gtk-dorong-penguatan-validitas-data-madrasah?utm_source=chatgpt.com "Kakankemenag Depok Buka Implementasi EMIS GTK, Dorong Penguatan Validitas Data Madrasah"
[4]: https://jabar.kemenag.go.id/kanwil/akhiri-rakor-emis-gtk-2026-penanganan-residu-hingga-validasi-data-ditegaskan-qfz3xy?utm_source=chatgpt.com "Akhiri Rakor EMIS-GTK 2026, Penanganan Residu hingga Validasi Data Ditegaskan"
[5]: https://dapo.kemendikdasmen.go.id/berita/rilis-aplikasi-dapodik-versi-2026-b?utm_source=chatgpt.com "Rilis Aplikasi Dapodik versi 2026.b - Pauddikdasmen"
[6]: https://kemenag.go.id/kolom/emis-4-0-transformasi-digital-data-pendidikan-islam-eOEUC?utm_source=chatgpt.com "EMIS 4.0: Transformasi Digital Data Pendidikan Islam | Kolom | Kementerian Agama RI"
