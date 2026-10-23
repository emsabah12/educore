# PRD-000 — Platform Foundation (MVP Pondasi)

- **Versi:** 0.15 (2026-10-23: `person_identifiers` untuk NIK dibangun — HR-1g)
- **Versi sebelumnya:** 0.14 (F5b penugasan & role dibangun), 0.13 (F5a akun login selesai; aturan pengaman akun global), 0.12 (2026-10-14: kelola akses — akun login, penugasan, role; OD-20 s.d. OD-22)
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

### 4.6 Halaman admin pohon lembaga (OD-14)

Menu **Lembaga** (`/lembaga`) muncul bagi pemilik `core.organizations.view` di lembaga kerja aktif. [OWNER, 2026-10-10]

- **Melihat:** hanya node yang terlihat menurut §7.2, dibatasi lembaga kerja aktif. Node yang induknya di luar cakupan tampil sebagai akar dengan keterangan jalurnya (mis. "Pondok Pesantren › Unit 1").
- **Node nonaktif** disembunyikan secara default; pilihan "Tampilkan yang nonaktif" menampilkannya dengan label **Nonaktif**.
- **Aksi kelola** (butuh `core.organizations.manage` di node terkait):
    - tambah node anak (UNIT, BIRO, atau LEMBAGA dengan kategori & jenjang);
    - ubah **nama** — **kode tidak bisa diubah** agar rujukan di data lain tetap stabil;
    - pindah ke induk lain — induk tujuan juga wajib di dalam izin kelola;
    - nonaktifkan (dari bawah ke atas, §4.5) dan **aktifkan kembali** (dari atas ke bawah: induk wajib aktif).
- Menambah atau memindah node ke **tingkat Yayasan** (tanpa induk) hanya untuk pemilik izin kelola tingkat Yayasan.
- Lembaga kerja yang sedang dipakai tidak bisa dinonaktifkan dari dalamnya, agar pengelola tidak mengunci dirinya sendiri. [ASUMSI]
- Jawaban akses mengikuti OD-13: di luar cakupan 404, di dalam cakupan tanpa izin 403. Pelanggaran aturan pohon tampil sebagai pesan form berbahasa Indonesia.
- Dengan role bawaan saat ini, hanya **Admin Yayasan** yang punya izin kelola; Kepala Lembaga hanya melihat (OD-11).

### 4.7 Kelola akses (OD-20 s.d. OD-22) [OWNER, 2026-10-14]

Akun login, penugasan, dan role diatur dari halaman **detail pegawai** (PRD-001 §6), bagian **Akses login**. Logika & route milik Core; HR hanya menampilkannya (arah dependency HR → Core tetap).

**Siapa:** hanya **Admin Yayasan** (`core.memberships.manage` di tingkat Yayasan, lembaga kerja Seluruh Yayasan) [OWNER, OD-21]. Selain itu → 403.

**F5a — Akun login**

| Aksi                        | Aturan                                                                                                                                                                                                                                    |
| --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Buat akun login             | Hanya untuk pegawai yang belum punya akun [OWNER, OD-22]. Username wajib (format §5), email opsional [ASUMSI]. Sistem membuat **kata sandi sementara** acak (12 karakter) yang tampil **sekali** untuk diserahkan langsung [OWNER, OD-20] |
| Wajib ganti sandi           | Akun dengan sandi sementara diarahkan ke halaman ganti sandi setelah login dan tidak bisa membuka halaman lain sebelum menggantinya [ASUMSI]                                                                                              |
| Atur ulang sandi            | Membuat sandi sementara baru (tampil sekali); sesi login lain milik akun itu diakhiri [ASUMSI]                                                                                                                                            |
| Nonaktifkan / aktifkan akun | Akun nonaktif tidak bisa login (pesan login umum, §6); data tidak dihapus (OD-06). Admin tidak bisa menonaktifkan akunnya sendiri [ASUMSI]                                                                                                |

**F5b — Penugasan & role**

| Aksi                 | Aturan                                                                                                                                                    |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tambah penugasan     | Struktural (lembaga) atau fungsional (tingkat Yayasan + jenjang, §4.4), **wajib** dengan satu role (Pimpinan, Kepala Lembaga, Koordinator, Staf) [ASUMSI] |
| Cabut penugasan      | Status penugasan menjadi nonaktif; berlaku di request berikutnya (§6)                                                                                     |
| Role tingkat Yayasan | Pasang/cabut role tenant-wide (Admin Yayasan, Admin SDM, Pimpinan). Role Admin Yayasan terakhir di yayasan tidak bisa dicabut [ASUMSI]                    |

- Penugasan **terpisah** dari penempatan HR: jabatan tidak memberi hak akses (PRD-001 §4.1). Admin memasang penugasan secara sadar.
- Kata sandi sementara tidak pernah disimpan dalam bentuk asli, tidak dicatat di audit log, dan tidak dikirim ulang.
- Semua aksi tercatat di audit log (akun dibuat oleh admin, sandi diatur ulang, akun dinonaktifkan/diaktifkan, penugasan dibuat/dicabut, role dipasang/dicabut).
- [ASUMSI] Role bawaan **Kepala Lembaga** dilepas dari `core.memberships.manage` agar sesuai OD-21.

**Rincian F5a (SELESAI 2026-10-14, commit `0d26a2c`)**

- Kata sandi sementara: 12 karakter acak kriptografis, selalu memuat huruf besar, huruf kecil, angka, dan simbol; tanpa karakter yang mudah tertukar (0/O, 1/l/I). Dikirim ke browser admin sekali lewat flash, tidak masuk riwayat browser [ASUMSI].
- Wajib ganti sandi: halaman `/akun/ganti-sandi`; sandi baru tidak boleh sama dengan sandi sementara. Selain halaman itu, hanya "Keluar" yang bisa dibuka.
- Akun nonaktif yang masih punya sesi terbuka (termasuk "ingat saya") dikeluarkan di request berikutnya.
- Buat akun hanya untuk anggota yang keanggotaannya di yayasan masih aktif [ASUMSI].
- Akun bersifat global (§5). Karena itu akun **Superadmin** dan akun yang juga **terdaftar aktif di yayasan lain** tidak bisa diatur dari halaman yayasan; bagian Akses login menampilkan alasannya [ASUMSI, pengaman].
- Admin boleh mengatur ulang sandinya sendiri (lalu wajib menggantinya), tetapi tidak bisa menonaktifkan akunnya sendiri.
- Audit: `account.created` (dengan `oleh_admin`), `account.password_reset`, `account.deactivated`, `account.activated`, tercatat di log yayasan. Penggantian sandi oleh pemilik tetap `account.password_changed`.

**Rincian F5b (dibangun 2026-10-14, menunggu verifikasi owner)**

- Pilihan role ditentukan katalog di kode (`AccessCatalog::offerAsTenantRole/offerAsAssignmentRole`): tingkat Yayasan = Admin Yayasan, Pimpinan, Admin SDM (didaftarkan modul HR); penugasan = Pimpinan, Kepala Lembaga, Koordinator, Staf. Role lain ditolak.
- Penugasan struktural = satu lembaga/unit (beserta turunannya); fungsional = tingkat Yayasan + jenjang (§4.4). Penugasan fungsional di bawah node tertentu belum ditawarkan di halaman [ASUMSI].
- Penugasan ke target yang sama yang pernah dicabut **diaktifkan kembali** (baris lama, role baru), karena database melarang penugasan dobel; audit `access.assignment_created` dengan `diaktifkan_kembali` [ASUMSI, disetujui owner 2026-10-14].
- Cabut penugasan = semua role-nya dilepas + status nonaktif; audit `access.assignment_revoked` (berisi daftar role yang dilepas).
- Admin Yayasan tidak bisa mencabut role Admin Yayasan miliknya sendiri; Admin Yayasan terakhir (anggota aktif) tidak bisa dicabut [ASUMSI, disetujui owner 2026-10-14].
- Anggota dengan keanggotaan nonaktif tidak bisa diberi role/penugasan baru. Pengaman akun Superadmin/lintas yayasan (F5a) **tidak** berlaku untuk penugasan, karena penugasan milik yayasan ini [ASUMSI, disetujui owner 2026-10-14].

## 5. Model data pondasi

Semua ID memakai UUIDv7 [LAMA]. Semua tabel milik tenant punya `tenant_id` + index [LAMA].

| Entitas                                   | Field minimal                                                                                                                                          | Aturan                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tenants`                                 | name, code, status                                                                                                                                     | `ACTIVE`/`SUSPENDED`; tenant non-aktif tidak bisa diakses [LAMA]                                                                                                                                                                                                                                                                                                                                                                         |
| `organizations`                           | tenant_id, parent_id?, type, category?, jenjang?, code, name, status                                                                                   | `UNIQUE(tenant_id, code)`; parent wajib di tenant sama; tanpa siklus; kedalaman ≤ 6 [OWNER]                                                                                                                                                                                                                                                                                                                                              |
| `organization_closure`                    | tenant_id, ancestor_id, descendant_id, depth                                                                                                           | Closure table untuk query "semua turunan" / "semua induk" secepat satu join [ASUMSI]; diperbarui dalam transaksi saat node dibuat/dipindah                                                                                                                                                                                                                                                                                               |
| `persons`                                 | name, gender? (`L`/`P`), birth_place?, birth_date?, phone?, address?                                                                                   | Nama manusia hanya di Person [LAMA ADR-013]. Tempat/tanggal lahir, HP, dan alamat ditambahkan untuk HR (PRD-001 §4.5, OD-HR-01); yang tampil ke pengguna dibatasi permission modul [OWNER, 2026-10-13]                                                                                                                                                                                                                                   |
| `person_identifiers`                      | person_id, type (mis. NIK), encrypted_value, value_fingerprint                                                                                         | NIK terenkripsi + fingerprint untuk pencarian [LAMA]. **Dibangun 2026-10-23 (HR-1g)** [OWNER]: `type` saat ini hanya `NIK`; unik `(person_id, type)` dan `(type, value_fingerprint)` — satu NIK satu orang di seluruh EduCore. Enkripsi `Crypt` (APP_KEY); fingerprint HMAC-SHA256 dengan kunci turunan APP_KEY. Layanan Core `PersonNik`; nilai tidak pernah masuk audit/log. Pesan "NIK terpakai" tidak menyebut orang di yayasan lain |
| `users`                                   | person_id, email?, username?, password, status, is_superadmin, must_change_password, kolom 2FA                                                         | `User → Person`, tanpa `tenant_id` [LAMA]; satu Person maksimal satu User; email & username disimpan huruf kecil; username 3–50 karakter (`a-z 0-9 . _ -`) [ASUMSI]. Email boleh kosong untuk akun yang dibuat admin, tetapi akun wajib punya email **atau** username (§4.7) [ASUMSI, 2026-10-14]                                                                                                                                        |
| `memberships`                             | person_id, tenant_id, status                                                                                                                           | `UNIQUE(person_id, tenant_id)` [LAMA]                                                                                                                                                                                                                                                                                                                                                                                                    |
| `organizational_assignments`              | tenant_id, membership_id, organization_id?, jenjang_filter?, status                                                                                    | Satu orang boleh punya banyak penugasan [LAMA]; `jenjang_filter` terisi = penugasan fungsional (§4.4). `organization_id` kosong hanya boleh untuk penugasan fungsional di tingkat Yayasan (mis. Koordinator MDA: semua MDA di seluruh Yayasan). Penugasan yang sama tidak boleh dobel [ASUMSI, 2026-10-10]                                                                                                                               |
| `roles`, `permissions`, `role_permission` | key, name                                                                                                                                              | Katalog global; permission `modul.resource.aksi`, mis. `hr.employees.view` [LAMA]. Didefinisikan di kode tiap modul lalu disalin ke database dengan `php artisan educore:sync-access` (juga saat deploy). Permission yang hilang dari kode ikut terhapus; role yang hilang dari kode dikosongkan [OWNER, OD-11]                                                                                                                          |
| `membership_roles`                        | membership_id, role_id                                                                                                                                 | Role tenant-wide (berlaku di seluruh pohon) [LAMA]                                                                                                                                                                                                                                                                                                                                                                                       |
| `organizational_assignment_roles`         | organizational_assignment_id, role_id                                                                                                                  | Role di node penugasan + seluruh turunannya [OWNER]                                                                                                                                                                                                                                                                                                                                                                                      |
| `scoped_settings`                         | tenant_id, organization_id?, jenjang?, key, value (jsonb), is_enforced                                                                                 | Aturan berjenjang, lihat §8 [OWNER + ASUMSI]                                                                                                                                                                                                                                                                                                                                                                                             |
| `audit_logs`                              | tenant_id?, actor_user_id?, actor_membership_id?, organization_id?, action, subject_type?, subject_id?, changes (jsonb)?, ip?, user_agent?, created_at | Append-only dijaga trigger database; hanya `educore:prune-audit` boleh menghapus baris yang melewati masa simpan (OD-16). `actor_user_id` kosong = sistem/tamu; `tenant_id` kosong = kejadian akun di luar yayasan. Aksi dari konsol dicatat tanpa IP/user agent. Tanpa foreign key ke data yang dirujuk agar log tetap utuh [OWNER + ASUMSI]                                                                                            |

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
Pilih workspace: salah satu node penugasan (atau "Seluruh Yayasan" bila punya role tenant-wide / tanpa penugasan)
   ↓
Dashboard — data yang tampil = node workspace + semua turunannya
```

- Login global tanpa input tenant [LAMA PRD-002].
- Membership & workspace aktif disimpan di **session server** dan divalidasi ulang setiap request (status Membership, Tenant, penugasan) [ADR-001].
- Workspace bisa diganti kapan saja lewat menu; selalu dicek ulang di server.
- Login dibatasi rate limit; pesan gagal tidak membedakan "akun tidak ada" vs "password salah" [ASUMSI].
- Penugasan aktif hanya satu → lembaga kerja langsung dipilih; lebih dari satu → wajib memilih [OWNER, OD-08].
- Membership tanpa penugasan → konteks **Seluruh Yayasan** (belum melihat data apa pun sampai role tenant-wide ada di F3) [OWNER, OD-09].
- Pemegang role tenant-wide yang juga punya penugasan mendapat pilihan tambahan **Seluruh Yayasan** di urutan pertama. Bila role tenant-wide dicabut saat sesi berjalan, request berikutnya pindah ke penugasannya [F3].
- Satu lembaga kerja aktif pada satu waktu. Di lembaga kerja penugasan, role tenant-wide hanya berlaku di dalam cakupan lembaga kerja itu; halaman **tingkat Yayasan** (Katalog SDM, Log audit, tambah/kelola pegawai, kelola akses) hanya terbuka dari **Seluruh Yayasan**, dan menunya disembunyikan di lembaga kerja lain [F5b, 2026-10-14].
- Membership/yayasan/penugasan/lembaga yang dinonaktifkan saat sesi berjalan langsung berlaku di request berikutnya: pengguna diarahkan memilih ulang atau ke halaman "Belum terdaftar".
- Superadmin tanpa membership diarahkan ke **Panel platform**.
- Model milik tenant (lembaga, penugasan) otomatis tersaring ke yayasan aktif bila konteks kerja sudah terbentuk; perintah konsol/seeder tetap wajib memakai filter `tenant_id` eksplisit [ASUMSI].

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
- Data yang dicek wajib menyebut node pemiliknya (kontrak `OwnedByOrganization`). Tanpa data atau dengan nama class saja (mis. tombol "Tambah"), Gate hanya menjawab "punya izin di setidaknya satu node lembaga kerja"; aksi terhadap data tertentu **wajib** mengirim datanya [F3].
- Untuk permission yang ada di katalog, Gate::before selalu memberi jawaban akhir, sehingga policy modul dengan nama ability yang sama tidak dijalankan. Aturan tambahan (mis. "hanya data milik sendiri") ditulis sebagai ability/policy dengan nama lain [F3].
- Jawaban akses (OD-13): node di luar cakupan pengguna atau milik yayasan lain → **404**; node di dalam cakupan tetapi tanpa permission aksi itu → **403** [OWNER].
- Superadmin **tidak** otomatis menembus data yayasan; untuk melihatnya ia perlu membership dan role seperti orang lain (OD-12) [OWNER].
- Node nonaktif tetap termasuk cakupan agar riwayat datanya tetap terlihat oleh atasannya [ASUMSI].

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

Disediakan helper Core `AuthorizationService::visibleNodeIds($permission)`, yang dipakai semua modul. Hasilnya di-cache per request saja; cache lintas request ditunda sampai load test [LAMA HR-015].

### 7.3 Contoh uji wajib

| Skenario                                                                              | Hasil                                                        |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| Pimpinan Ponpes membuka data pegawai MDA Unit 2                                       | Boleh                                                        |
| Kepala Unit 1 membuka data Unit 2                                                     | 404                                                          |
| Kepala MDA Unit 1 membuka data tingkat Ponpes                                         | 404                                                          |
| Kepala SMK (Unit 3) membuka data Ponpes, Unit 3, atau lembaga lain                    | 404                                                          |
| Kepala Unit 1 membuka data lembaga formal yang berada di Unit 1 (MTs/MA, sesuai §4.3) | Boleh                                                        |
| Koordinator MDA (fungsional, filter MDA) membuka data MDA Unit 3                      | Boleh                                                        |
| Koordinator MDA membuka data Bahasa Unit 1 atau SMP                                   | 404                                                          |
| MDA baru dibuat di Unit 2                                                             | Langsung masuk cakupan Koordinator MDA tanpa penugasan ulang |
| Admin tenant A membuka data tenant B                                                  | 404                                                          |
| Penugasan dicabut saat sesi masih aktif                                               | Request berikutnya ditolak                                   |

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

- Setiap perubahan aturan dicatat di audit log (disambungkan di F4 saat tabel `audit_logs` dibuat).
- Membaca aturan tidak dibatasi hak akses (aturan berlaku bagi siapa pun di node itu); yang dibatasi adalah mengubahnya [ASUMSI].

### 8.3 Aturan per jenis lembaga

Kolom opsional `jenjang` di `scoped_settings` memungkinkan aturan seperti "untuk semua MDA" ditetapkan sekali di tingkat Yayasan, misalnya oleh Koordinator MDA, lalu tiap unit tetap bisa menimpa aturan yang tidak dikunci. Saat resolusi, di setiap tingkat, aturan khusus jenjang node diperiksa lebih dulu daripada aturan umum. [ASUMSI]

Siapa yang boleh mengubah aturan di (node X, jenjang J): pemilik permission pengelolaan aturan yang cakupan penugasannya memuat X untuk jenjang J.

Rincian yang diterapkan di F3 [ASUMSI]:

- Aturan khusus jenjang J di node X berlaku untuk setiap lembaga berjenjang J di bawah X **beserta turunannya**, sama dengan cakupan penugasan fungsional (§7.1).
- Di tingkat yang sama, aturan khusus jenjang lebih spesifik daripada aturan umum, baik saat mencari nilai terkunci maupun nilai terdekat. Antar aturan jenjang di tingkat yang sama, yang jenjang-nya lebih dekat ke node tujuan yang menang (mis. MDA lebih spesifik daripada PONPES untuk MDA Unit 2).
- Menulis aturan ditolak dengan pesan jelas bila target sudah dikunci oleh aturan terkunci di tingkat yang **lebih atas**.
- Penugasan fungsional (A, F) boleh mengubah: node di dalam cakupannya, atau aturan khusus jenjang F di A / di bawah A (A kosong = tingkat Yayasan). Contoh: Koordinator MDA boleh menetapkan "untuk semua MDA" di tingkat Yayasan atau di Unit 2, tetapi tidak aturan umum Unit 2.

## 9. Kebutuhan non-fungsional

| Area                | Target                                                                                                                                                          | Label                              |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Skala               | ±50.000 user total lintas tenant; tenant pertama 1 yayasan                                                                                                      | [OWNER]                            |
| Respons halaman     | p95 ≤ 500 ms di server untuk halaman normal                                                                                                                     | [ASUMSI] — divalidasi di load test |
| Pagination          | Maks 100 baris per halaman                                                                                                                                      | [LAMA HR-015]                      |
| N+1                 | `Model::preventLazyLoading()` aktif di dev/test                                                                                                                 | [ASUMSI]                           |
| Session/cache/queue | Driver `database` dulu; Redis setelah load test                                                                                                                 | [LAMA]                             |
| Backup              | `pg_dump` harian (simpan 14 hari di server + salinan mingguan di luar server) + uji restore bulanan di DB terpisah; script Linux & Windows di `scripts/backup/` | [ASUMSI]                           |
| Health              | `/up` hanya OK/gagal, tanpa detail dependency                                                                                                                   | [LAMA HR-016 §3]                   |
| Keamanan            | Secret hanya di `.env`; cookie HttpOnly + Secure; CSRF bawaan; validasi semua input                                                                             | [LAMA ADR-030]                     |
| Error               | Pesan ramah dalam Bahasa Indonesia; detail teknis hanya di log                                                                                                  | [LAMA]                             |
| UI                  | Responsif, Bahasa Indonesia, state kosong/loading/error                                                                                                         | Instruksi proyek                   |

## 10. Milestone & kriteria selesai

| Milestone                       | Selesai bila                                                                                                                                                                                                                                                                    |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **F0 Setup**                    | Laravel + Inertia React jalan di Laragon; PostgreSQL `educore` & `educore_testing`; Pest + arch test lulus; `.gitattributes` LF; CI menjalankan lint + test di PostgreSQL                                                                                                       |
| **F1 Tenant & pohon lembaga**   | Migrasi + service buat/pindah/nonaktifkan node (closure ikut diperbarui dalam transaksi); validasi siklus & kedalaman; seeder tenant pertama sesuai §4.3; test isolasi tenant. Halaman admin pohon lembaga dipindah ke setelah F3 karena butuh login & RBAC [OWNER, 2026-10-10] |
| **F2a Identitas**               | Tabel `persons` & `users` baru (User di modul Core); login dengan email **atau** username; user nonaktif tidak bisa login; profil mengubah nama Person; fitur hapus akun dihilangkan; halaman auth & pengaturan berbahasa Indonesia [OWNER, 2026-10-10]                         |
| **F2b Membership & konteks**    | Membership, penugasan; alur 0/1/>1 Membership; pilih workspace; validasi ulang konteks tiap request; `TenantContext`; seeder akun uji                                                                                                                                           |
| **F3 RBAC & aturan berjenjang** | Katalog role/permission; role tenant-wide & per node; `visibleNodeIds`; semua skenario §7.3 lulus; resolusi `scoped_settings` sesuai §8.2 teruji                                                                                                                                |
| **Admin pohon lembaga**         | Halaman §4.6: lihat sesuai cakupan, tambah, ubah nama, pindah, nonaktifkan & aktifkan kembali, dengan 404/403 teruji                                                                                                                                                            |
| **F4 Operasional**              | Audit log aksi penting (OD-15/16) + halaman log audit (OD-17); `/up` ikut memeriksa database tanpa membocorkan detail; script backup + uji restore + catatan uji restore; README instal/jalan/deploy VPS Linux (OD-18)                                                          |
| **F5 Kelola akses**             | §4.7: F5a akun login (buat, sandi sementara, wajib ganti, atur ulang, nonaktif/aktif) dan F5b penugasan & role, dengan 403/404 dan audit teruji. **F5a SELESAI 2026-10-14** (commit `0d26a2c`); **F5b dibangun 2026-10-14** (menunggu verifikasi owner)                         |

## 11. Open decisions

| ID    | Topik                                                  | Status                                                                                                                                                                                                                                                                                                                                                                        |
| ----- | ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OD-01 | Posisi lembaga nonformal & model topologi              | **DIPUTUSKAN** — pohon fleksibel, lihat §4                                                                                                                                                                                                                                                                                                                                    |
| OD-02 | Lembaga formal di dalam unit & koordinator lintas unit | **DIPUTUSKAN** — formal di unit = anak unit; koordinator di bawah Biro pusat + penugasan fungsional (§4.4)                                                                                                                                                                                                                                                                    |
| OD-03 | Kedalaman maksimum pohon                               | **DIPUTUSKAN** — 6 tingkat                                                                                                                                                                                                                                                                                                                                                    |
| OD-04 | Fitur auth bawaan starter kit                          | **DIPUTUSKAN** — registrasi & verifikasi email OFF, 2FA & konfirmasi password ON, passkey OFF                                                                                                                                                                                                                                                                                 |
| OD-05 | Field Person di F2                                     | **DIPUTUSKAN** — `name` + `gender`; lainnya menunggu PRD HR/Academic                                                                                                                                                                                                                                                                                                          |
| OD-06 | Hapus akun mandiri                                     | **DIPUTUSKAN** — dihilangkan; akun dinonaktifkan admin agar riwayat data utuh                                                                                                                                                                                                                                                                                                 |
| OD-07 | Bahasa halaman bawaan starter kit                      | **DIPUTUSKAN** — diterjemahkan ke Bahasa Indonesia di F2a, termasuk pesan validasi & error login                                                                                                                                                                                                                                                                              |
| OD-08 | Penugasan tunggal                                      | **DIPUTUSKAN** — otomatis dipilih tanpa halaman pilihan                                                                                                                                                                                                                                                                                                                       |
| OD-09 | Membership tanpa penugasan                             | **DIPUTUSKAN** — masuk dengan konteks "Seluruh Yayasan"                                                                                                                                                                                                                                                                                                                       |
| OD-10 | Akun uji development                                   | **DIPUTUSKAN** — seeder akun uji hanya berjalan di lingkungan `local`/`testing`                                                                                                                                                                                                                                                                                               |
| OD-11 | Sumber katalog role & permission                       | **DIPUTUSKAN** — didefinisikan di kode per modul, disinkronkan lewat `educore:sync-access`; halaman kelola katalog di Panel platform menyusul. Role bawaan: Admin Yayasan, Pimpinan, Kepala Lembaga, Koordinator, Staf                                                                                                                                                        |
| OD-12 | Superadmin & data yayasan                              | **DIPUTUSKAN** — tidak ada jalan pintas; perlu membership + role                                                                                                                                                                                                                                                                                                              |
| OD-13 | Jawaban saat akses ditolak                             | **DIPUTUSKAN** — di luar cakupan 404, di dalam cakupan tanpa izin aksi 403                                                                                                                                                                                                                                                                                                    |
| OD-14 | Isi halaman admin pohon lembaga                        | **DIPUTUSKAN** — tambah, ubah nama (kode tetap), pindah, nonaktifkan, aktifkan kembali; node nonaktif disembunyikan kecuali diminta (§4.6)                                                                                                                                                                                                                                    |
| OD-15 | Aksi yang dicatat di audit log                         | **DIPUTUSKAN** — login berhasil/gagal/keluar; ganti kata sandi, email, 2FA; tambah/ubah nama/pindah/nonaktif/aktif lembaga; anggota, penugasan, pasang/cabut role, sinkronisasi katalog; perubahan aturan berjenjang (nilai lama & baru). IP & user agent disimpan. Kata sandi/rahasia tidak pernah dicatat; isian login gagal hanya disimpan bila cocok dengan akun yang ada |
| OD-16 | Masa simpan audit log                                  | **DIPUTUSKAN** — 1 tahun (`AUDIT_RETENTION_DAYS=365`, minimal 30), dihapus otomatis setiap hari                                                                                                                                                                                                                                                                               |
| OD-17 | Halaman log audit                                      | **DIPUTUSKAN** — baca-saja untuk Admin Yayasan (permission `core.audit.view`, lembaga kerja Seluruh Yayasan); filter tanggal, pelaku, jenis aksi; 50 baris per halaman. Menampilkan log yayasan itu + kejadian akun milik anggotanya                                                                                                                                          |
| OD-18 | Target server production                               | **BELUM PASTI** — README mengasumsikan VPS Linux (Ubuntu 24.04, Nginx, PHP-FPM 8.3, PostgreSQL 16); script backup tersedia untuk Linux & Windows                                                                                                                                                                                                                              |
| OD-19 | Akun pertama di production                             | **DIPUTUSKAN** — perintah interaktif `educore:create-admin <kode>` (akun + keanggotaan + role Admin Yayasan dalam satu transaksi) dan `educore:create-superadmin`; kata sandi hanya lewat input tersembunyi; akun yang sudah ada bisa dinaikkan setelah konfirmasi; semuanya tercatat di audit log [OWNER, 2026-10-13]                                                        |

| OD-20 | Kata sandi pertama akun pegawai | **DIPUTUSKAN** — sandi sementara acak tampil sekali, wajib diganti saat login pertama; tanpa email/SMTP [OWNER, 2026-10-14] |
| OD-21 | Siapa mengelola akun, penugasan, role | **DIPUTUSKAN** — hanya Admin Yayasan [OWNER, 2026-10-14] |
| OD-22 | Untuk siapa akun dibuat | **DIPUTUSKAN** — dari data pegawai (detail pegawai); orang yang belum menjadi pegawai belum bisa diberi akun lewat aplikasi [OWNER, 2026-10-14] |

## 12. Saran tambahan (di luar ruang lingkup)

- Dormitory/asrama untuk Ponpes Unit 1–3 sudah punya desain matang di dokumen lama (`architecture/dormitory.md`, ADR-019); kandidat modul setelah HR & Academic.
- Satu Person bisa menjadi siswa SMP sekaligus santri MDA; ini perlu dikunci di PRD Academic (enrolment per lembaga, bukan satu profil Student per Membership).
