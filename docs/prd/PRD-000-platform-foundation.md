# PRD-000 — Platform Foundation (MVP Pondasi)

- **Versi:** 0.5 (2026-10-10: keputusan F2 — field Person, hapus akun, bahasa antarmuka, F2 dipecah F2a/F2b)
- **Status:** APPROVED UNTUK F0–F1 — OD-01, OD-02, OD-03 diputuskan owner (2026-10-09)
- **Tanggal:** 2026-10-09
- **Arsitektur:** `ADR-001 — Rebuild sebagai Simplified Modular Monolith`
- **Referensi domain lama:** ADR-013, 014, 016, 018, PRD-002, HR-013, HR-015, HR-016 (di `docs-legacy/`)

Label: **[LAMA]** = dari dokumen lama · **[OWNER]** = keputusan owner di sesi ini · **[ASUMSI]** = usulan baru, perlu konfirmasi.

---

## 1. Tujuan

Membangun pondasi yang aman dan stabil sebelum modul bisnis (HR, Academic):

1. Satu yayasan (Tenant) mengelola pohon lembaga dan unit dengan kedalaman fleksibel.
2. Orang (Person) login sekali dan bekerja sesuai haknya di lembaga/unit tempat ia ditugaskan.
3. Atasan melihat seluruh lembaga di bawahnya; bawahan dan lembaga yang tidak berelasi tidak bisa saling melihat.
4. Aturan bisa ditetapkan di tingkat yayasan, lembaga induk, atau unit, dengan aturan yang paling spesifik menang kecuali dikunci oleh induk.
5. Jejak audit, backup, dan CI tersedia sejak awal.

## 2. Ruang lingkup

**Masuk (F0–F4):** setup proyek, tenant & pohon lembaga, Person/User/Membership, login & pemilihan konteks, RBAC berbasis pohon, mekanisme aturan berjenjang (scoped settings), audit log, health check aman, backup.

**Tidak masuk:** modul HR & Academic (PRD terpisah), isi aturan bisnis spesifik (didefinisikan oleh modul), Dormitory, PPDB, Billing, API publik/mobile, self-signup tenant, SSO, beberapa tenant di beberapa tab sekaligus.

## 3. Pengguna (MVP)

| Peran                          | Kebutuhan                                                                          | Sumber         |
| ------------------------------ | ---------------------------------------------------------------------------------- | -------------- |
| Superadmin platform            | Membuat tenant, mengelola katalog role/permission; tanpa Membership                | [LAMA] PRD-002 |
| Pimpinan/Admin Yayasan         | Melihat & mengelola seluruh pohon lembaga                                          | [OWNER]        |
| Kepala/Admin lembaga atau unit | Mengelola node-nya **dan semua turunannya**; tidak melihat induk atau node sebelah | [OWNER]        |
| Pengguna biasa (guru, staf)    | Login, memilih lembaga, mengakses menu sesuai permission                           | [LAMA]         |

## 4. Pohon lembaga (OD-01 — DIPUTUSKAN)

### 4.1 Keputusan owner

- Setiap lembaga dan unit punya kepala/admin sendiri. **[OWNER]**
- Induk bisa melihat semua turunannya. Turunan **tidak** bisa melihat induk. Node yang tidak berelasi tidak bisa saling melihat. Pola ini berlaku di semua tingkat. **[OWNER]**
- Lembaga nonformal (MDA, Bahasa, Al-Qur'an) berjalan per unit Ponpes. **[OWNER]**
- Tiap unit bisa punya aturan spesifik, di samping aturan global dari yayasan atau lembaga utama. **[OWNER]**
- Lembaga formal ada yang berada di dalam unit Ponpes, ada yang tidak. **[OWNER]**

### 4.2 Perubahan dari model lama

Model lama mengunci 2 tingkat tetap: `Organization → OrganizationUnit`, dengan role unit yang tidak diwariskan ke mana pun (ADR-018). Kebutuhan owner adalah **pohon dengan kedalaman bebas** dan pewarisan ke bawah di semua tingkat. Karena itu:

- `Organization` dan `OrganizationUnit` digabung menjadi satu tabel `organizations` dengan kolom `type`. **[ASUMSI]**
- Setiap node boleh punya `parent_id` (di tenant yang sama). Kedalaman maksimum 6 tingkat sebagai pengaman. **[ASUMSI]**

### 4.3 Pohon tenant pertama

Susunan berikut ditetapkan owner pada 2026-10-10 dan dipakai oleh seeder F1. **[OWNER]**

```text
Tenant: Yayasan                                   ← role tenant-wide = lihat semua
├── Biro Pendidikan dan Koordinator Antar Lembaga (BIRO)  ← tempat koordinator bernaung
├── Biro SDM dan Keuangan                         (BIRO)
├── Biro Humas                                    (BIRO)
├── Biro IT dan Sistem                            (BIRO)
└── Pondok Pesantren                              (LEMBAGA, PESANTREN)
    ├── Unit 1  (UNIT)
    │   ├── MTs Unit 1        (LEMBAGA, FORMAL)
    │   ├── MA Unit 1         (LEMBAGA, FORMAL)
    │   ├── MDA Unit 1        (LEMBAGA, NONFORMAL)
    │   ├── Bahasa Unit 1     (LEMBAGA, NONFORMAL)
    │   └── Al-Qur'an Unit 1  (LEMBAGA, NONFORMAL)
    ├── Unit 2  (UNIT)
    │   ├── MTs Unit 2, MA Unit 2               (FORMAL)
    │   └── MDA, Bahasa, Al-Qur'an Unit 2       (NONFORMAL)
    └── Unit 3  (UNIT)
        ├── SMP Unit 3, SMK Unit 3              (FORMAL)
        └── MDA, Bahasa, Al-Qur'an Unit 3       (NONFORMAL)
```

Catatan:

- Saat ini semua lembaga formal berada di dalam unit. Lembaga formal yang langsung di bawah Yayasan tetap didukung model bila nanti dibutuhkan.
- MDA, Bahasa, dan Al-Qur'an diasumsikan ada di ketiga unit, sesuai jawaban "tiap unit" pada OD-01. **[ASUMSI]**
- Nama asli Yayasan dan Pondok Pesantren belum diberikan; seeder memakai nama sementara yang bisa diganti. **[ASUMSI]**

Yang dihasilkan pohon ini:

- Pimpinan Ponpes melihat Unit 1–3 dan semua lembaga di dalamnya, termasuk lembaga formal. **[OWNER]**
- Kepala Unit 1 melihat MTs, MA, MDA, Bahasa, Al-Qur'an Unit 1, tetapi tidak melihat Unit 2 maupun data tingkat Ponpes.
- Kepala MA Unit 1 tidak melihat MA Unit 2 (tidak berelasi), kecuali lewat penugasan fungsional.

### 4.4 Keputusan OD-02 — lembaga formal di dalam unit & koordinator

1. Lembaga formal yang berada di dalam unit menjadi **anak dari unit tersebut**, sehingga Kepala Unit dan Pimpinan Ponpes boleh melihat datanya. **[OWNER]**
2. Koordinator (mis. Koordinator MDA) berada **langsung di bawah Biro/Asisten di Yayasan/pusat**, bukan di bawah Ponpes. **[OWNER]**

Konsekuensi desain untuk poin 2: koordinator membina lembaga yang secara struktur berada di cabang lain (MDA Unit 1–3 ada di bawah Ponpes). Hubungan ini tidak bisa diwakili oleh pohon saja, sehingga dipakai **dua jenis penugasan** [ASUMSI]:

| Jenis penugasan                                    | Contoh                                                  | Cakupan                                                                                                         |
| -------------------------------------------------- | ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| **Struktural** (default)                           | Koordinator MDA sebagai staf Biro Pendidikan            | Node Biro + turunannya (aturan pohon biasa)                                                                     |
| **Fungsional** (penugasan dengan filter `jenjang`) | Koordinator MDA: node = Yayasan, filter jenjang = `MDA` | Semua node ber-jenjang MDA di bawah Yayasan (MDA Unit 1, 2, 3, dan MDA yang dibuat kemudian) beserta turunannya |

Yang boleh dilakukan koordinator pada lembaga binaannya (hanya melihat, atau juga mengelola) ditentukan oleh **role** yang dipasang di penugasan fungsional itu, sama seperti penugasan lain.

### 4.5 Atribut node [ASUMSI]

| Field            | Isi                                                                                 |
| ---------------- | ----------------------------------------------------------------------------------- |
| `type`           | `LEMBAGA` \| `UNIT` \| `BIRO` (struktur pusat seperti Biro/Asisten)                 |
| `category`       | `FORMAL` \| `NONFORMAL` \| `PESANTREN` (wajib untuk `LEMBAGA`)                      |
| `jenjang`        | Kode jenis lembaga: `SMP`, `MTS`, `SMK`, `MA`, `MDA`, `BAHASA`, `ALQURAN`, `PONPES` |
| `code`           | Unik per tenant                                                                     |
| `name`, `status` | `ACTIVE` \| `INACTIVE`                                                              |
| `parent_id`      | Node induk (nullable = langsung di bawah Yayasan)                                   |

Aturan validasi node [ASUMSI, diterapkan di F1]:

- `code`: huruf besar, angka, dan tanda `-`; 2–50 karakter; otomatis diubah ke huruf besar; unik per tenant.
- `LEMBAGA` wajib punya `category` dan `jenjang`; `UNIT` dan `BIRO` tidak memakai keduanya.
- Node baru tidak boleh dibuat di bawah induk yang `INACTIVE`.
- Node tidak boleh dinonaktifkan selama masih punya anak yang `ACTIVE` (nonaktifkan dari bawah ke atas).
- Node tidak dihapus permanen; cukup dinonaktifkan agar riwayat data tetap utuh.

## 5. Model data pondasi

Semua ID memakai UUIDv7 [LAMA]. Semua tabel milik tenant punya `tenant_id` + index [LAMA].

| Entitas                                   | Field minimal                                                                                                                                    | Aturan                                                                                                                                                              |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tenants`                                 | name, code, status                                                                                                                               | `ACTIVE`/`SUSPENDED`; tenant non-aktif tidak bisa diakses [LAMA]                                                                                                    |
| `organizations`                           | tenant_id, parent_id?, type, category?, jenjang?, code, name, status                                                                             | `UNIQUE(tenant_id, code)`; parent wajib di tenant sama; tanpa siklus; kedalaman ≤ 6 [OWNER]                                                                         |
| `organization_closure`                    | tenant_id, ancestor_id, descendant_id, depth                                                                                                     | Closure table untuk query "semua turunan" / "semua induk" secepat satu join [ASUMSI]; diperbarui dalam transaksi saat node dibuat/dipindah                          |
| `persons`                                 | name, gender? (`L`/`P`)                                                                                                                          | Nama manusia hanya di Person [LAMA ADR-013]. Tanggal lahir, tempat lahir, dan identitas lain ditambahkan saat PRD HR/Academic membutuhkannya [OWNER, 2026-10-10]    |
| `person_identifiers`                      | person_id, type (mis. NIK), encrypted_value, value_fingerprint                                                                                   | NIK terenkripsi + fingerprint untuk pencarian [LAMA]. **Ditunda** ke PRD HR/Academic [OWNER, 2026-10-10]                                                            |
| `users`                                   | person_id, email, username?, password, status, is_superadmin, kolom 2FA                                                                          | `User → Person`, tanpa `tenant_id` [LAMA]; satu Person maksimal satu User; email & username disimpan huruf kecil; username 3–50 karakter (`a-z 0-9 . _ -`) [ASUMSI] |
| `memberships`                             | person_id, tenant_id, status                                                                                                                     | `UNIQUE(person_id, tenant_id)` [LAMA]                                                                                                                               |
| `organizational_assignments`              | tenant_id, membership_id, organization_id, jenjang_filter?, status                                                                               | Satu orang boleh punya banyak penugasan [LAMA]; `jenjang_filter` terisi = penugasan fungsional (§4.4) [ASUMSI]                                                      |
| `roles`, `permissions`, `role_permission` | key, name                                                                                                                                        | Katalog global; permission `modul.resource.aksi`, mis. `hr.employees.view` [LAMA]                                                                                   |
| `membership_roles`                        | membership_id, role_id                                                                                                                           | Role tenant-wide (berlaku di seluruh pohon) [LAMA]                                                                                                                  |
| `organizational_assignment_roles`         | organizational_assignment_id, role_id                                                                                                            | Role di node penugasan + seluruh turunannya [OWNER]                                                                                                                 |
| `scoped_settings`                         | tenant_id, organization_id?, jenjang?, key, value (jsonb), is_enforced                                                                           | Aturan berjenjang, lihat §8 [OWNER + ASUMSI]                                                                                                                        |
| `audit_logs`                              | tenant_id?, actor_user_id, actor_membership_id?, organization_id?, action, subject_type, subject_id, changes (jsonb), ip, user_agent, created_at | Append-only [ASUMSI untuk daftar field]                                                                                                                             |

Setiap data bisnis di modul (pegawai, siswa, kelas, dst.) wajib menyimpan `organization_id` pemiliknya. Ini yang dipakai untuk aturan lihat-ke-bawah. **[ASUMSI]**

Pembuatan multi-record (Person + User + Membership + role, atau node + closure) wajib dalam satu transaksi [LAMA §14].

## 6. Alur login & konteks

```text
Login (email/username + password)
   ↓
Superadmin? ──ya──► Panel platform (tanpa tenant)
   ↓ tidak
Hitung Membership ACTIVE di Tenant ACTIVE
   ├── 0  → halaman "Belum terdaftar di yayasan mana pun"
   ├── 1  → otomatis dipilih
   └── >1 → wajib memilih (setiap login baru)
   ↓
Pilih workspace: salah satu node penugasan (atau "Seluruh Yayasan" bila punya role tenant-wide)
   ↓
Dashboard — data yang tampil = node workspace + semua turunannya
```

- Login global tanpa input tenant [LAMA PRD-002].
- Membership & workspace aktif disimpan di **session server** dan divalidasi ulang setiap request (status Membership, Tenant, penugasan) [ADR-001].
- Workspace bisa diganti kapan saja lewat menu; selalu dicek ulang di server.
- Login dibatasi rate limit; pesan gagal tidak membedakan "akun tidak ada" vs "password salah" [ASUMSI].

## 7. Otorisasi berbasis pohon

### 7.1 Aturan

```text
Cakupan penugasan P (node A, filter F):
  F kosong → A + semua turunan A
  F terisi → setiap node M di bawah A (termasuk A) yang jenjang-nya = F, + semua turunan M

Permission efektif di node N
= TenantRoles
∪ role dari setiap penugasan yang cakupannya memuat N
```

- Penugasan di sebuah node berlaku untuk node itu dan **semua turunannya**. [OWNER]
- Penugasan fungsional hanya mencakup node dengan jenjang yang sesuai filter (§4.4). [ASUMSI]
- Penugasan **tidak** berlaku ke induk atau ke node sebelah. [OWNER, sejalan LAMA ADR-018]
- Setiap aksi backend dicek ulang dari database; menu di UI hanya petunjuk [LAMA ADR-027].
- `Gate::before` mendelegasikan ke `AuthorizationService` agar `can()`, policy, dan `authorize()` bisa dipakai [ADR-001].

### 7.2 Penyaringan data (wajib di query)

Daftar data selalu disaring di database, bukan hanya disembunyikan di UI [LAMA HR-013, HR-020 "Gate 3"]:

```text
node_terlihat(user, permission)
= turunan (termasuk diri sendiri) dari semua node penugasan yang memberi permission tsb
  (atau seluruh pohon bila permission datang dari role tenant-wide)

SELECT … WHERE tenant_id = :tenant
          AND organization_id IN node_terlihat(user, 'hr.employees.view')
          AND organization_id IN turunan(workspace aktif)
```

Disediakan helper Core, mis. `OrganizationScope::visibleNodeIds($permission)`, yang dipakai semua modul. Hasilnya di-cache per request saja; cache lintas request ditunda sampai load test [LAMA HR-015].

### 7.3 Contoh uji wajib

| Skenario                                                         | Hasil                                                        |
| ---------------------------------------------------------------- | ------------------------------------------------------------ |
| Pimpinan Ponpes membuka data pegawai MDA Unit 2                  | Boleh                                                        |
| Kepala Unit 1 membuka data Unit 2                                | 404                                                          |
| Kepala MDA Unit 1 membuka data tingkat Ponpes                    | 404                                                          |
| Kepala SMK membuka data apa pun di Ponpes                        | 404                                                          |
| Kepala Unit 1 membuka data SMP yang berada di Unit 1             | Boleh                                                        |
| Koordinator MDA (fungsional, filter MDA) membuka data MDA Unit 3 | Boleh                                                        |
| Koordinator MDA membuka data Bahasa Unit 1 atau SMP              | 404                                                          |
| MDA baru dibuat di Unit 2                                        | Langsung masuk cakupan Koordinator MDA tanpa penugasan ulang |
| Admin tenant A membuka data tenant B                             | 404                                                          |
| Penugasan dicabut saat sesi masih aktif                          | Request berikutnya ditolak                                   |

## 8. Aturan berjenjang (scoped settings)

### 8.1 Kebutuhan [OWNER]

Ada aturan global dari yayasan atau lembaga utama, dan tiap unit bisa punya aturan spesifik yang berbeda.

### 8.2 Mekanisme [ASUMSI]

- Core hanya menyediakan **mekanisme**. **Isi** aturannya (mis. jam masuk, alur persetujuan cuti, KKM) didefinisikan oleh modul HR/Academic beserta tipe, nilai default, dan validasinya.
- Nilai bisa diisi di tingkat Yayasan (`organization_id = NULL`) atau di node mana pun.
- Urutan resolusi untuk node N:

```text
1. Cari dari atas (Yayasan) ke bawah: induk TERATAS yang menandai is_enforced = true → nilai itu dipakai (tidak bisa ditimpa)
2. Jika tidak ada yang dikunci: nilai dari node TERDEKAT (N, lalu induknya, … , Yayasan)
3. Jika tidak ada sama sekali: default dari modul
```

- Setiap perubahan aturan dicatat di audit log.

### 8.3 Aturan per jenis lembaga

Kolom opsional `jenjang` di `scoped_settings` memungkinkan aturan seperti "untuk semua MDA" ditetapkan sekali di tingkat Yayasan, misalnya oleh Koordinator MDA, lalu tiap unit tetap bisa menimpa aturan yang tidak dikunci. Saat resolusi, di setiap tingkat, aturan khusus jenjang node diperiksa lebih dulu daripada aturan umum. [ASUMSI]

Siapa yang boleh mengubah aturan di (node X, jenjang J): pemilik permission pengelolaan aturan yang cakupan penugasannya memuat X untuk jenjang J.

## 9. Kebutuhan non-fungsional

| Area                | Target                                                                              | Label                              |
| ------------------- | ----------------------------------------------------------------------------------- | ---------------------------------- |
| Skala               | ±50.000 user total lintas tenant; tenant pertama 1 yayasan                          | [OWNER]                            |
| Respons halaman     | p95 ≤ 500 ms di server untuk halaman normal                                         | [ASUMSI] — divalidasi di load test |
| Pagination          | Maks 100 baris per halaman                                                          | [LAMA HR-015]                      |
| N+1                 | `Model::preventLazyLoading()` aktif di dev/test                                     | [ASUMSI]                           |
| Session/cache/queue | Driver `database` dulu; Redis setelah load test                                     | [LAMA]                             |
| Backup              | `pg_dump` harian + uji restore bulanan di DB terpisah                               | [ASUMSI]                           |
| Health              | `/up` hanya OK/gagal, tanpa detail dependency                                       | [LAMA HR-016 §3]                   |
| Keamanan            | Secret hanya di `.env`; cookie HttpOnly + Secure; CSRF bawaan; validasi semua input | [LAMA ADR-030]                     |
| Error               | Pesan ramah dalam Bahasa Indonesia; detail teknis hanya di log                      | [LAMA]                             |
| UI                  | Responsif, Bahasa Indonesia, state kosong/loading/error                             | Instruksi proyek                   |

## 10. Milestone & kriteria selesai

| Milestone                       | Selesai bila                                                                                                                                                                                                                                                                    |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **F0 Setup**                    | Laravel + Inertia React jalan di Laragon; PostgreSQL `educore` & `educore_testing`; Pest + arch test lulus; `.gitattributes` LF; CI menjalankan lint + test di PostgreSQL                                                                                                       |
| **F1 Tenant & pohon lembaga**   | Migrasi + service buat/pindah/nonaktifkan node (closure ikut diperbarui dalam transaksi); validasi siklus & kedalaman; seeder tenant pertama sesuai §4.3; test isolasi tenant. Halaman admin pohon lembaga dipindah ke setelah F3 karena butuh login & RBAC [OWNER, 2026-10-10] |
| **F2a Identitas**               | Tabel `persons` & `users` baru (User di modul Core); login dengan email **atau** username; user nonaktif tidak bisa login; profil mengubah nama Person; fitur hapus akun dihilangkan; halaman auth & pengaturan berbahasa Indonesia [OWNER, 2026-10-10]                         |
| **F2b Membership & konteks**    | Membership, penugasan; alur 0/1/>1 Membership; pilih workspace; validasi ulang konteks tiap request; `TenantContext`; seeder akun uji                                                                                                                                           |
| **F3 RBAC & aturan berjenjang** | Katalog role/permission; role tenant-wide & per node; `visibleNodeIds`; semua skenario §7.3 lulus; resolusi `scoped_settings` sesuai §8.2 teruji                                                                                                                                |
| **F4 Operasional**              | Audit log aksi penting; `/up` aman; script backup + catatan uji restore; README instal/jalan                                                                                                                                                                                    |

## 11. Open decisions

| ID    | Topik                                                  | Status                                                                                                     |
| ----- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| OD-01 | Posisi lembaga nonformal & model topologi              | **DIPUTUSKAN** — pohon fleksibel, lihat §4                                                                 |
| OD-02 | Lembaga formal di dalam unit & koordinator lintas unit | **DIPUTUSKAN** — formal di unit = anak unit; koordinator di bawah Biro pusat + penugasan fungsional (§4.4) |
| OD-03 | Kedalaman maksimum pohon                               | **DIPUTUSKAN** — 6 tingkat                                                                                 |
| OD-04 | Fitur auth bawaan starter kit                          | **DIPUTUSKAN** — registrasi & verifikasi email OFF, 2FA & konfirmasi password ON, passkey OFF              |
| OD-05 | Field Person di F2                                     | **DIPUTUSKAN** — `name` + `gender`; lainnya menunggu PRD HR/Academic                                       |
| OD-06 | Hapus akun mandiri                                     | **DIPUTUSKAN** — dihilangkan; akun dinonaktifkan admin agar riwayat data utuh                              |
| OD-07 | Bahasa halaman bawaan starter kit                      | **DIPUTUSKAN** — diterjemahkan ke Bahasa Indonesia di F2a, termasuk pesan validasi & error login           |

## 12. Saran tambahan (di luar ruang lingkup)

- Dormitory/asrama untuk Ponpes Unit 1–3 sudah punya desain matang di dokumen lama (`architecture/dormitory.md`, ADR-019); kandidat modul setelah HR & Academic.
- Satu Person bisa menjadi siswa SMP sekaligus santri MDA; ini perlu dikunci di PRD Academic (enrolment per lembaga, bukan satu profil Student per Membership).
