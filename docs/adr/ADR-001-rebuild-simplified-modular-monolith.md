# ADR-001 — Rebuild EduCore sebagai Simplified Modular Monolith

- **Status:** PROPOSED (menunggu persetujuan owner)
- **Tanggal:** 2026-10-09
- **Pengganti untuk:** seluruh ADR lama (ADR-001 s.d. ADR-034) sebagai *implementation contract*. ADR lama tetap tersimpan di `docs-legacy/` sebagai **HISTORICAL — referensi domain**.
- **Dokumen terkait:** `PRD-000-platform-foundation.md`

---

## 1. Konteks

Repo EduCore lama sudah berupa modular monolith (Laravel 13 + PostgreSQL + React SPA terpisah). Domainnya matang, tetapi mekanismenya terlalu berat untuk tim 1–5 orang:

- Module Kernel buatan sendiri (manifest `module.yaml`, discovery, dependency resolver) — ADR-001 s.d. 010, ADR-017.
- Dua transport autentikasi (Bearer + BrowserSession/BFF) dengan fencing context per tab — ADR-022, 023, 026.
- SPA terpisah dengan OpenAPI codegen dan 12 milestone FEI — ADR-020 s.d. 031.
- Proses dokumen dan gate berlapis (HR saja 238 task) — HR-018 s.d. HR-025.
- Fondasi penting justru belum selesai: login masih wajib `tenant_uuid` (PRD-002 NOT STARTED), route HR tanpa permission (HR-013 §2), tidak ada backup/CI/runbook (HR-016 §2).

Target bisnis: **±50.000 user total lintas tenant**, dimulai dari **1 tenant (yayasan)** dengan lembaga formal (SMP, MTs, SMK, MA), Pondok Pesantren dengan Unit 1–3, dan lembaga nonformal di bawah Ponpes (MDA, Bahasa, Al-Qur'an) yang berjalan per unit. Owner menetapkan: induk melihat semua turunannya, turunan tidak melihat induk, node tak berelasi tidak saling melihat; tiap unit bisa punya aturan sendiri di samping aturan global yayasan/lembaga utama.

## 2. Keputusan

Bangun ulang repo dari nol sebagai **satu aplikasi Laravel (Inertia + React)** dengan modul berbatas jelas, mempertahankan **keputusan domain** lama dan membuang **mesin** yang tidak sepadan.

### 2.1 Stack

| Lapisan | Pilihan | Alasan |
|---|---|---|
| Backend | Laravel 13, PHP ≥ 8.3 | Sama dengan repo lama, tim sudah familier |
| Database | PostgreSQL (major version dev = produksi) | Partial index, jsonb, locking yang andal |
| Frontend | Inertia 3 + React 19 + TypeScript + Tailwind 4 (Laravel React starter kit resmi, termasuk shadcn/ui & Wayfinder) | Satu aplikasi, routing & auth di Laravel, tanpa BFF/OpenAPI codegen; Wayfinder memberi tipe TypeScript untuk route tanpa codegen manual |
| Auth | Laravel Fortify (bawaan starter kit) | Login, reset password, 2FA sudah teruji; tinggal disesuaikan ke login global + pilih Membership (PRD-000 §6) |
| Test | Pest (+ arch test) di folder `tests/`, DB test PostgreSQL `educore_testing`; Larastan level 7 & Pint dari starter kit | Menangkap pelanggaran batas modul & perilaku PostgreSQL sungguhan |
| Dev env | Windows + Laragon | Lingkungan tim saat ini |
| Produksi | Linux (asumsi) | Laragon hanya untuk development |

### 2.2 Struktur modul

```text
educore/
├── app/                    # shell Laravel saja (tanpa logika bisnis)
├── modules/
│   ├── Core/               # Tenancy, Organization, Person, Identity(User), Authorization, Audit
│   ├── HR/
│   └── Academic/
├── resources/js/
│   ├── core/               # layout, komponen UI, halaman Core
│   └── modules/{hr,academic}/
├── tests/Architecture/     # aturan batas modul (gagal otomatis bila dilanggar)
└── docs/
```

- Autoload: PSR-4 `"Modules\\": "modules/"` di `composer.json`.
- Registrasi: satu `ServiceProvider` per modul, didaftarkan manual di `bootstrap/providers.php`. **Tidak ada** manifest, discovery, atau enable/disable runtime.
- Arah dependency (tetap dari repo lama): `Core → []`, `HR → Core`, `Academic → Core, HR`.
- Antar modul hanya boleh lewat kelas publik (`Contracts/`, `DTO/`, event). Dijaga oleh arch test Pest.

### 2.3 Yang dipertahankan dari dokumen lama

| Keputusan | Sumber lama |
|---|---|
| `Person` identitas manusia global; `User → Person`; `Membership = Person × Tenant`, `UNIQUE(person_id, tenant_id)` | ADR-013, ADR-014 |
| Tenant sebagai batas keamanan teratas; partisipasi di lembaga lewat `OrganizationalAssignment` (bentuk topologi diubah, lihat §2.4) | ADR-018 |
| Single database, shared schema, `tenant_id` eksplisit + global scope, cross-tenant fail closed | current-architecture §4 |
| RBAC di database; role scoped ke lembaga; pewarisan hanya ke bawah, tidak naik/menyamping | ADR-016, ADR-018 |
| Jabatan/Position **bukan** sumber otorisasi; Teacher adalah capability, bukan entitas | ADR-032, current-architecture §11 |
| Login global (email/username + password) → pilih Membership; Superadmin tanpa Membership | PRD-002, ADR-033 |
| UUIDv7 untuk ID kanonik | current-architecture §12 |
| Transaksi atomik untuk provisioning multi-record | current-architecture §14 |
| Pesan error aman, tanpa SQL/stack trace ke user | current-architecture §8B |
| Redis/cache/read model ditunda sampai ada data profiling | HR-009, Phase-2H-F, HR-015 |
| Spesifikasi domain HR-001 s.d. HR-016 sebagai referensi PRD modul HR | prd/hr |

### 2.4 Yang diubah / disederhanakan

| Lama | Baru | Trade-off yang diterima |
|---|---|---|
| Module Kernel custom (ADR-017) | ServiceProvider biasa + arch test | Tidak ada laporan `module:status`; cukup |
| SPA + API-first + OpenAPI (ADR-020, 025) | Inertia + React | API JSON publik ditambah belakangan bila ada aplikasi mobile |
| Bearer + BrowserSession/BFF (ADR-022, 033) | Session Laravel standar (cookie HttpOnly, CSRF bawaan) | Belum ada akses API stateless |
| Membership aktif per tab (ADR-023) | Membership & workspace aktif disimpan di **session server** | Satu browser = satu tenant aktif dalam satu waktu |
| Laravel Gate terpisah dari RBAC (ADR-016) | `Gate::before` mendelegasikan ke `AuthorizationService` | `can()`/`authorize()`/policy bisa dipakai langsung |
| Gate & dokumen berlapis (HR-018–025) | 1 PRD ringkas per modul + ADR pendek + checklist PR | Lebih sedikit formalitas, tetap ada jejak keputusan |
| `Organization → OrganizationUnit` tetap 2 tingkat; role unit tidak diwariskan (ADR-018) | **Pohon lembaga fleksibel**: satu tabel `organizations` (`type` = LEMBAGA/UNIT, `parent_id`) + closure table; penugasan berlaku di node itu dan seluruh turunannya, tidak ke induk/sebelah (PRD-000 §4, §7) | Pemindahan node harus memperbarui closure table dalam transaksi; kedalaman dibatasi |
| Tidak ada aturan berjenjang | `scoped_settings`: aturan di Yayasan/induk/unit, terdekat menang kecuali induk mengunci (PRD-000 §8) | Modul wajib mendefinisikan key aturan beserta default & validasinya |

## 3. Konsekuensi

**Positif:** satu deployable, satu repo, satu jalur auth; onboarding developer jauh lebih cepat; pondasi keamanan (permission, tenant scope) dibangun sejak hari pertama.

**Negatif / risiko:**
- Pekerjaan lama (Frontend Foundation, Dormitory Check-In) tidak dibawa sebagai kode. Mitigasi: domain dan test case lama dipakai sebagai referensi.
- Bila nanti butuh aplikasi mobile, perlu menambah API + token (Sanctum). Mitigasi: logika bisnis ditaruh di `Application/` (service/action), bukan di controller, agar bisa dipakai ulang oleh API.

## 4. Alternatif yang ditolak

1. **Melanjutkan repo lama** — mekanisme terlalu berat untuk tim kecil (lihat §1).
2. **SPA + API** — hanya unggul bila ada mobile native dalam 6–12 bulan; owner memilih Inertia.
3. **Package modul pihak ketiga (mis. nwidart/laravel-modules)** — menambah dependensi & konvensi loading sendiri; PSR-4 + provider biasa sudah cukup.
4. **Database per tenant** — terlalu mahal secara operasional untuk target 50k user.
