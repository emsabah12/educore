# HR-017 — Workspace Employee Listing & Creation Specification

**Version:** 1.0
**Phase:** 3E — RM-HR-02 Continuation (Scoped Workforce)
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-09-04
**Depends On:** HR-002, HR-013 (§4, §6, §11, §28–§32), ADR-016, ADR-032
**Resolves:** HR-013 §33 ("Future Workspace Employee Listing" — [DEFERRED]),
HR-013 §35 ("Workspace Employee Creation" — [DEFERRED])

---

## 0. Catatan Status

Dokumen ini **APPROVED/LOCKED** per 2026-09-04, menutup dua item yang
sebelumnya ditandai `[DEFERRED]` di `HR-013` (§33, §35). Bersama
`HR-001`–`HR-016`, dokumen ini menjadi rujukan otoritatif untuk
implementasi Workspace Employee Listing & Creation.

---

# 1. Ringkasan Masalah

`HR-013` mengunci **mekanisme** otorisasi organisasi (`organizational.permission`,
`InjectOrganizationalContext`, resource-scope check) tapi sengaja menunda
**dua endpoint spesifik** karena keduanya butuh keputusan desain tambahan:

| Item                            | Kenapa ditunda (HR-013)                                                                                                                                                                                     |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| §33 Workspace Employee Listing  | _"HR-013 tidak memaksakan duplicate route structure sebelum API design."_                                                                                                                                   |
| §35 Workspace Employee Creation | _"Scoped organizational user belum boleh menggunakan current Employee POST hanya dengan organizational permission karena operation menghasilkan tenant-wide Employee tanpa guaranteed placement contract."_ |

Sejak `HR-013` ditulis, **RM-HR-01** dan **RM-HR-02 (Step 1–3)** sudah
selesai diimplementasikan dan teruji (120 automated test). Draft ini
memanfaatkan infrastruktur yang sudah ada — `HrWorkforceScopeService`,
`organizational.permission` middleware, `EmploymentPlacementService`, serta
`EmployeeProvisioningService` dan `OrganizationalAssignmentService` milik
Core — supaya kedua endpoint ini bisa dibangun tanpa primitif baru yang
signifikan.

---

# 2. Bagian A — Workspace Employee Listing

## 2.1 Endpoint

**[LOCKED]**

```text
GET /v1/hr/workspace/employees
```

Ditempatkan di grup route workspace yang **sudah ada** (`prefix('v1/hr/workspace')`,
sudah dipakai `EmploymentManagementController@store`, dst. sejak RM-HR-02
Step 3) — bukan struktur baru, sesuai semangat §33 _"tidak memaksakan
duplicate route structure"_.

```text
InjectTenantContext
  → InjectOrganizationalContext
  → organizational.permission:hr.employees.view
  → EmployeeManagementController@indexScopedToWorkspace   (aksi baru)
```

`hr.employees.view` dipakai ulang — **bukan** permission baru — konsisten
dengan pola yang sudah terbukti di RM-HR-02 (satu nama permission, dua
sumber grant: `membership_roles` untuk tenant-wide, `organizational_assignment_roles`
untuk scoped).

## 2.2 Aturan Query — Menegakkan §31 Collection Query Rule

Ini bagian **paling kritis** dari seluruh dokumen ini. §32 secara eksplisit
memperingatkan risiko yang harus dihindari:

> _"Jika [scoped role + tenant-wide repository query] dilakukan, maka user
> organization-level dapat memperoleh seluruh employee tenant."_

**[LOCKED]** Query untuk listing ini **wajib** memfilter di level SQL
sebelum pagination — bukan mengambil semua lalu memfilter di PHP/frontend.
Secara konkret, query-nya adalah **generalisasi collection dari logika yang
sudah dibangun** di `HrWorkforceScopeService::isEmployeeVisibleInCurrentContext()`
(RM-HR-02 Step 2), diubah dari "apakah employee X visible?" menjadi "daftar
semua employee yang visible":

```sql
SELECT DISTINCT employees.*
FROM employees
JOIN employment_placements
  ON employment_placements.employment_id IN (
      SELECT id FROM employments WHERE employments.employee_id = employees.id
  )
JOIN organizational_assignments
  ON organizational_assignments.id = employment_placements.organizational_assignment_id
WHERE employees.tenant_id = :tenantId
  AND employment_placements.tenant_id = :tenantId
  AND employment_placements.effective_to IS NULL          -- placement TERBUKA
  AND organizational_assignments.status = 'ACTIVE'
  AND organizational_assignments.organization_id = :contextOrganizationId
  AND (
        :contextOrganizationUnitId IS NULL                 -- org-level workspace
        OR organizational_assignments.organization_unit_id = :contextOrganizationUnitId
      )
ORDER BY employees.created_at DESC
```

Ini **query yang sama persis secara semantik** dengan yang sudah divalidasi
lewat 11 test di `HrWorkforceScopeServiceTest` — hanya berubah dari `EXISTS`
(cek satu Employee) menjadi `SELECT` (daftar semua Employee). Implementasi
disarankan mengekstrak logika bersama ini ke method baru pada
`HrWorkforceScopeService`, misalnya `visibleEmployeeIdsQuery(): Builder`,
supaya method deteksi tunggal (`isEmployeeVisibleInCurrentContext`) dan
method listing berbagi satu sumber kebenaran — mencegah keduanya
"menyimpang" seiring waktu.

## 2.3 Kontrak Respons

**[LOCKED]** Sama persis dengan `GET /v1/hr/employees` (tenant-wide,
sudah ada) — `{status, data, meta: {current_page, last_page, per_page, total}}`
— supaya frontend bisa memakai komponen yang sama untuk kedua mode.

## 2.4 Invariant Baru

### INV-HR-011 — Workspace Listing tidak pernah membocorkan Employee di luar scope

Query collection untuk `GET /v1/hr/workspace/employees` **wajib** memakai
kondisi `WHERE` yang identik dengan `HrWorkforceScopeService`. Test
regresi wajib membuktikan: Employee di unit tetangga (sibling unit) atau
organisasi lain **tidak pernah muncul** di hasil, sekalipun `per_page`
diperbesar melampaui jumlah total data tenant.

## 2.5 Kasus Tepi

| Kasus                                                                                                                              | Perilaku                                            |
| ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| Workspace org-level, tidak ada Employee sama sekali di organisasi itu                                                              | `200 OK`, `data: []`, `meta.total: 0` — bukan error |
| Employee treatment: dua Placement terbuka ke unit berbeda dalam org yang sama (skenario ganjil, seharusnya dicegah invariant lain) | `DISTINCT` di query mencegah baris duplikat         |

---

# 3. Bagian B — Workspace Employee Creation

## 3.1 Kenapa Ini Lebih Rumit dari Sekadar Menambah Middleware

§35 sudah mengidentifikasi masalah intinya: endpoint `POST /v1/hr/employees`
yang ada sekarang menghasilkan Employee **tanpa Placement** (`EmploymentPlacement`
belum tentu ada). Kalau endpoint itu dibuka untuk actor yang HANYA punya
`organizational.permission` (bukan `tenant.permission`), hasilnya adalah
Employee yang **tidak visible di workspace manapun** — termasuk workspace
milik actor yang baru saja membuatnya! Ini pelanggaran langsung terhadap
prinsip _"no safe organizational ownership proof"_ dari HR-002 §12.2 yang
sudah kita tegakkan ketat di seluruh RM-HR-02.

**Kesimpulan**: Workspace Employee Creation **wajib** menghasilkan Employee

- Employment + Placement dalam **satu transaksi atomik** — bukan Employee
  saja seperti endpoint tenant-wide yang sudah ada.

## 3.2 Transaksi yang Diusulkan

**[LOCKED]** Seluruh langkah di bawah **sudah bisa dibangun dari
service yang sudah ada dan sudah teruji** — tidak ada primitif baru yang
signifikan:

```text
1. Verify workspace (InjectOrganizationalContext) sudah resolve
   organization_id [+ organization_unit_id].
2. organizational.permission:hr.employees.create
   (permission DIPAKAI ULANG dari katalog existing, digrant lewat
   organizational_assignment_roles — bukan permission baru).
3. EmployeeProvisioningService::provision(tenantId, data)
   → membuat Person + Membership + Employee (SUDAH ADA, RM-HR-01 lama).
4. OrganizationalAssignmentService::assignToOrganization(membershipId, organizationId)
   [atau ::assignToUnit(...) kalau workspace di-scope ke unit]
   → membuat/reuse Core OrganizationalAssignment untuk Membership baru ini
   (SUDAH ADA & idempotent, terbukti lewat OrganizationalAssignmentServiceTest).
5. EmploymentLifecycleService::createPlanned(tenantId, employeeId, data)
   → Employment berstatus PLANNED (SUDAH ADA, RM-HR-01 Step 4).
6. EmploymentPlacementService::createPlacement(tenantId, employmentId, data)
   → Placement TERBUKA menunjuk ke OrganizationalAssignment dari langkah 4
   (SUDAH ADA, RM-HR-01 Step 7 — termasuk penegakan INV-HR-004/005).
7. Commit satu transaksi. Employment TETAP PLANNED (lihat 3.4 poin
   [TERBUKA] — bukan otomatis ACTIVE).
```

**Catatan integrasi penting**: `OrganizationalAssignmentService::assignToOrganization()`
mengambil `tenant_id` dari `TenantContextInterface` **ambient** (bukan
parameter eksplisit) — beda konvensi dari service-service HR yang selalu
menerima `$tenantId` eksplisit. Service orchestrator baru untuk alur ini
harus memastikan `TenantContextInterface` sudah benar (otomatis terjamin
karena `InjectTenantContext` sudah berjalan lebih dulu di middleware chain),
tapi ini perlu dicatat eksplisit di komentar kode supaya tidak
membingungkan implementer berikutnya.

## 3.3 Endpoint

```text
POST /v1/hr/workspace/employees
```

```text
InjectTenantContext
  → InjectOrganizationalContext
  → organizational.permission:hr.employees.create
  → WorkspaceEmployeeProvisioningController@store   (controller baru,
    memanggil service orchestrator baru — mis. WorkspaceEmployeeProvisioningService)
```

## 3.4 Kasus Tepi — Keputusan Terkunci

**[LOCKED — disetujui 2026-09-04]**

1. **Employment tetap `PLANNED`, bukan otomatis `ACTIVE`.**
   Konsisten dengan `POST /v1/hr/employees` yang sudah ada. Actor
   memanggil endpoint `activate` yang sudah ada (tenant-wide atau
   workspace-scoped, RM-HR-02 Step 3) sebagai langkah terpisah kalau
   Employment perlu segera aktif.

2. **`organization_unit_id` OPSIONAL di payload** — mengikuti persis
   semantik context resolusi yang sudah dikunci di RM-HR-02 (HR-002
   §12.2): kalau workspace org-level (context tanpa unit), Employee baru
   otomatis org-level (visible dari semua unit di organisasi itu). Kalau
   workspace di-scope ke unit tertentu, `organization_unit_id` di payload
   HARUS kosong/diabaikan dan diisi otomatis dari context aktif (actor
   tidak boleh memilih unit lain di luar workspace-nya sendiri — itu
   akan jadi celah privilege escalation).

3. **Rollback penuh, tanpa mekanisme idempotency-key tambahan.**
   Constraint `UNIQUE(tenant_id, nip)` yang sudah ada di tabel `employees`
   sudah cukup mencegah duplikasi diam-diam pada retry — retry dengan
   `nip` yang sama akan gagal eksplisit di langkah 3, bukan membuat
   Employee kedua. Header `Idempotency-Key` TIDAK diperlukan untuk versi
   awal ini.

## 3.5 Invariant Baru

### INV-HR-012 — Workspace Employee Creation tidak pernah menghasilkan Employee tanpa Placement

Endpoint `POST /v1/hr/workspace/employees` **wajib** membungkus langkah
3–6 di §3.2 dalam **satu** `DB::transaction()`. Kalau langkah manapun
gagal (termasuk langkah 6, pembuatan Placement), **seluruh** langkah
sebelumnya (Person, Membership, Employee, Employment) **wajib** ikut
di-rollback — tidak boleh ada Employee "yatim" yang tercipta tanpa
Placement, bahkan sebagai efek samping kegagalan parsial.

## 3.6 Otorisasi — Tidak Ada Perubahan Katalog

**[LOCKED]** `hr.employees.create` (permission yang **sudah ada**
sejak RM-HR-01) dipakai ulang, digrant lewat `organizational_assignment_roles`
untuk actor scoped. **Tidak perlu entri katalog baru** — persis pola yang
sudah terbukti untuk `hr.employments.manage` di RM-HR-02 Step 3.

---

# 4. Ringkasan Perubahan Kode yang Diperkirakan (kalau draft ini disetujui)

| File                                                    | Perubahan                                                                                                      |
| ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| `HrWorkforceScopeService.php`                           | Tambah method `visibleEmployeeIdsQuery()` (generalisasi collection dari method exists yang sudah ada)          |
| `EmployeeManagementController.php`                      | Tambah aksi `indexScopedToWorkspace` (atau controller baru khusus workspace)                                   |
| **Baru**: `WorkspaceEmployeeProvisioningService.php`    | Orkestrasi §3.2 langkah 3–7, memanggil 4 service yang sudah ada                                                |
| **Baru**: `WorkspaceEmployeeProvisioningController.php` | HTTP layer untuk `POST /v1/hr/workspace/employees`                                                             |
| `HR/Routes/api.php`                                     | Tambah 2 route baru di grup workspace yang **sudah ada**                                                       |
| Test baru                                               | Minimal: scope-leak regression untuk listing (INV-HR-011), rollback-atomicity test untuk creation (INV-HR-012) |

**Tidak ada** migration baru, **tidak ada** permission catalog baru,
**tidak ada** middleware baru — seluruhnya memakai infrastruktur RM-HR-01/02
yang sudah ada dan teruji.

---

# 5. Status Persetujuan

Seluruh keputusan di dokumen ini — termasuk tiga poin yang sebelumnya
`[TERBUKA]` di §3.4 — telah disetujui pada 2026-09-04. Dokumen ini siap
diimplementasikan mengikuti alur MODE 1 (step-by-step, gated progression)
seperti RM-HR-01/02/03 sebelumnya.
