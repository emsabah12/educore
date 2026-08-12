# ADR-004 — Automatic Module Discovery

Version : 1.1
Status : Accepted (Amended)
Date : 2026-07-01
Updated : 2026-08-12
Sprint : CORE-001 Sprint-1


> ## Revalidation Amendment — 2026-08-12
> **Decision:** KEEP automatic discovery; **AMEND the intermediate contract**. `ModuleDiscovery` currently returns deterministic sorted `module.yaml` path strings. The historical `DiscoveredModule` Value Object described later in this ADR is no longer present in production source and is **not a current architecture requirement**. Current flow is `ModuleDiscovery → manifest path → ModuleManifestLoader → ModuleManifestParser → ModuleDefinitionFactory/Validator`. This amendment supersedes only the `DiscoveredModule` implementation detail; automatic convention-based discovery remains Accepted.

## Related ADR

- ADR-003 — Module Manifest Specification
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-010 — Module Identity Strategy

---

# Context

EduCore dirancang sebagai platform modular yang dapat berkembang melalui penambahan modul baru.

Platform Kernel memerlukan mekanisme untuk menemukan modul yang tersedia tanpa memerlukan registrasi manual setiap kali sebuah modul ditambahkan.

Registrasi manual akan meningkatkan biaya pemeliharaan, memperbesar kemungkinan kesalahan konfigurasi, dan menambah pekerjaan developer setiap kali membuat modul baru.

Selain menemukan modul, Discovery Pipeline juga memerlukan kontrak yang jelas antar komponen.

Mengembalikan path filesystem dalam bentuk string menyebabkan detail struktur direktori tersebar ke beberapa komponen berikutnya dan meningkatkan ketergantungan terhadap representasi filesystem.

Untuk menjaga tanggung jawab setiap komponen tetap terpisah, hasil discovery direpresentasikan sebagai Value Object `DiscoveredModule`.

---

# Decision

EduCore menggunakan **Automatic Module Discovery Pipeline**.

Platform Kernel secara otomatis memindai direktori:

```text
Modules/
```

Setiap subdirektori yang memiliki berkas:

```text
module.yaml
```

akan diperlakukan sebagai kandidat modul.

Proses discovery hanya bertanggung jawab menemukan modul yang tersedia pada filesystem.

Setiap modul yang berhasil ditemukan direpresentasikan sebagai sebuah **Value Object** bernama `DiscoveredModule`.

`DiscoveredModule` menjadi kontrak resmi antara proses discovery dan komponen berikutnya pada Discovery Pipeline.

Manifest kemudian diproses melalui pipeline berikut:

Discovery Pipeline terdiri atas Discovery Process dan tahapan pemrosesan manifest hingga `ModuleDefinition` diregistrasikan ke `ModuleRegistry`.

```text
Modules/
      │
      ▼
┌───────────────────┐
│ ModuleDiscovery   │
└───────────────────┘
      │
      ▼
┌───────────────────┐
│ DiscoveredModule  │
│ (Value Object)    │
└───────────────────┘
      │
      ▼
┌───────────────────────┐
│ ModuleManifestLoader  │
└───────────────────────┘
      │
      ▼
┌───────────────────────┐
│ ModuleManifestParser  │
└───────────────────────┘
      │
      ▼
┌──────────────────────────┐
│ ModuleManifestValidator  │
└──────────────────────────┘
      │
      ▼
┌──────────────────────────┐
│ ModuleDefinitionFactory  │
└──────────────────────────┘
      │
      ▼
┌───────────────────┐
│ ModuleRegistry    │
└───────────────────┘
```

`ModuleDiscovery` tidak mengekspos path filesystem dalam bentuk string kepada komponen lain.

Komponen setelah `ModuleDiscovery` hanya berinteraksi melalui `DiscoveredModule`.

Tidak diperlukan registrasi manual terhadap modul.

---

# Rationale

Automatic Module Discovery dipilih karena memberikan pengalaman pengembangan yang sederhana, konsisten, dan mudah dipelihara.

Keputusan ini juga mendukung penerapan prinsip-prinsip desain perangkat lunak, khususnya Single Responsibility Principle (SRP), Open/Closed Principle (OCP), dan mengurangi Primitive Obsession dengan mengganti representasi data primitif menjadi objek yang memiliki makna domain yang jelas.

Developer hanya perlu:

1. Membuat direktori modul.
2. Menambahkan `module.yaml`.
3. Menambahkan Service Provider.

Platform Kernel akan menemukan dan memproses modul tersebut secara otomatis pada saat bootstrap.

Pendekatan ini mengurangi konfigurasi manual serta menjaga konsistensi proses bootstrap.

Penggunaan `DiscoveredModule` juga menghindari penyebaran path filesystem dalam bentuk string ke berbagai komponen Kernel.

Pendekatan ini menjaga Single Responsibility Principle, mengurangi Primitive Obsession, memperjelas kontrak antar komponen, dan memudahkan evolusi Discovery Pipeline tanpa memengaruhi parser maupun registry.

Penggunaan `DiscoveredModule` sebagai hasil dari Discovery Process memberikan kontrak yang eksplisit antar komponen dalam Discovery Pipeline.

Pendekatan ini menghindari penyebaran path filesystem dalam bentuk string ke berbagai komponen Kernel, sehingga parser, validator, dan factory tidak perlu memahami representasi filesystem maupun melakukan manipulasi path secara langsung.

## Dengan memperkenalkan `DiscoveredModule` sebagai Value Object, setiap komponen memiliki tanggung jawab yang lebih terfokus, kontrak antar komponen menjadi lebih jelas, serta Discovery Pipeline lebih mudah dikembangkan tanpa memengaruhi tahapan berikutnya.

# Consequences

## Positive

- Tidak memerlukan registrasi manual.
- Mengurangi risiko human error.
- Mempermudah onboarding developer baru.
- Penambahan modul menjadi lebih sederhana.
- Discovery Pipeline memiliki tanggung jawab yang terpisah.
- Bootstrap Platform Kernel menjadi lebih konsisten.
- Discovery Process menjadi satu-satunya komponen yang memahami struktur filesystem.
- Kontrak antar komponen menjadi lebih eksplisit melalui `DiscoveredModule`.
- Parser, validator, dan factory tidak lagi bergantung pada manipulasi path filesystem.
- Discovery Pipeline lebih mudah diperluas untuk mendukung sumber modul lain tanpa mengubah tahapan pemrosesan berikutnya.

## Negative

- Membutuhkan proses scanning direktori saat bootstrap.
- Membutuhkan parser YAML.
- Manifest yang tidak valid harus ditangani sebelum registrasi.
- Menambah beberapa komponen pada Discovery Pipeline.
- Menambah satu Value Object (`DiscoveredModule`) pada Kernel.
- Dokumentasi dan testing menjadi sedikit lebih kompleks.

---

# DiscoveredModule

`DiscoveredModule` merupakan Value Object yang merepresentasikan hasil proses discovery.

Objek ini membawa informasi yang diperlukan oleh komponen berikutnya tanpa mengekspos detail implementasi filesystem.

Minimal informasi yang direpresentasikan adalah:

- modulePath
- manifestPath

Komponen selain `ModuleDiscovery` tidak membuat ataupun memodifikasi `DiscoveredModule`.

# Alternatives Considered

## Option A — Manual Registration

Setiap modul harus didaftarkan secara manual pada konfigurasi Platform Kernel.

**Rejected**, karena:

- Mudah terlupakan.
- Tidak skalabel.
- Menambah konfigurasi yang harus dipelihara.

---

## Option B — Composer Package Discovery

Menggunakan mekanisme package discovery milik Composer.

**Rejected**, karena modul EduCore bukan package Composer yang berdiri sendiri.

Pendekatan ini menambah kompleksitas tanpa memberikan manfaat yang sebanding.

---

## Option C — Automatic Discovery (**Accepted**)

Platform Kernel melakukan discovery terhadap modul secara otomatis berdasarkan konvensi struktur direktori.

Pendekatan ini memberikan keseimbangan antara fleksibilitas, maintainability, dan kemudahan pengembangan.

---

# Discovery Rules

Platform Kernel menerapkan aturan berikut:

- Root direktori modul adalah `Modules/`.
- Setiap modul berada pada satu subdirektori.
- Setiap modul wajib memiliki `module.yaml`.
- Manifest diproses melalui Discovery Pipeline.
- Discovery Process hanya menghasilkan `DiscoveredModule`.
- `DiscoveredModule` menjadi satu-satunya kontrak antara Discovery Process dan Discovery Pipeline.
- Manifest harus lolos validasi sebelum `ModuleDefinition` dibuat.
- `ModuleDefinition` hanya dapat dibuat melalui `ModuleDefinitionFactory`.
- Komponen setelah Discovery Process tidak boleh bergantung langsung pada representasi path filesystem.
- Parser, Validator, dan Factory hanya menerima `DiscoveredModule` atau objek hasil tahapan sebelumnya.
- Modul yang gagal diproses tidak diregistrasikan ke `ModuleRegistry`.

---

# DiscoveredModule

`DiscoveredModule` merupakan Value Object yang merepresentasikan hasil dari Discovery Process.

Objek ini menjadi kontrak resmi antara Discovery Process dan Discovery Pipeline, sehingga komponen berikutnya tidak bergantung langsung pada representasi path filesystem maupun struktur direktori modul.

`DiscoveredModule` hanya membawa informasi yang diperlukan untuk melanjutkan proses discovery tanpa mengandung logika bisnis maupun logika pemrosesan manifest.

Minimal informasi yang direpresentasikan meliputi:

- Module Path
- Manifest Path

Seluruh komponen setelah `ModuleDiscovery` hanya menerima `DiscoveredModule` sebagai input awal Discovery Pipeline.

# Current Implementation

Status implementasi pada akhir Sprint CORE-001:

- ✅ Module Discovery.
- ✅ Module Manifest Loader.
- ✅ Module Manifest Parser.
- ✅ Module Manifest Validator.
- ✅ Module Definition Factory.
- ✅ Immutable Module Definition.
- ✅ Module Registry.
- ✅ Module Loader.

---

# Future Evolution

Automatic Module Discovery dapat berkembang dengan kemampuan tambahan seperti:

- Recursive module discovery.
- Discovery cache.
- Module priority.
- Conditional discovery.
- Plugin directory.
- External module repository.

Seluruh evolusi tersebut tetap mempertahankan prinsip bahwa Platform Kernel bertanggung jawab menemukan modul secara otomatis berdasarkan konvensi.

---

# Impact

## Production Code

Perubahan pada ADR ini berdampak pada komponen Discovery Pipeline, khususnya:

- `ModuleDiscovery`
- `DiscoveredModule`
- `ModuleManifestLoader`
- `ModuleManifestParser`

Komponen lain seperti `ModuleRegistry`, `ModuleLoader`, dan `ModuleManager` tidak terdampak secara langsung karena tetap menerima `ModuleDefinition`.

---

## Testing

Perubahan kontrak Discovery Process memerlukan penyesuaian pada unit test yang berkaitan dengan:

- `ModuleDiscoveryTest`
- `DiscoveredModuleTest`
- `ModuleManifestLoaderTest`
- `ModuleManifestParserTest`

Testing harus memverifikasi bahwa seluruh tahapan Discovery Pipeline menggunakan `DiscoveredModule` sebagai kontrak antar komponen.

---

## Documentation

Perubahan keputusan ini memerlukan sinkronisasi dengan dokumen arsitektur berikut:

- ADR-003 — Module Manifest Specification
- ADR-006 — Module Loading Lifecycle
- ADR-001 — Kernel Architecture Overview

---

## Future Sprint

Keputusan ini menjadi fondasi untuk pengembangan kemampuan Discovery Pipeline pada sprint berikutnya, termasuk:

- Plugin Discovery
- External Module Repository
- Discovery Cache
- Recursive Discovery
- Marketplace Integration

## Seluruh pengembangan tersebut diharapkan tetap mempertahankan kontrak `DiscoveredModule` tanpa mengubah tahapan pemrosesan berikutnya.

# References

- PRD CORE-001
- Sprint CORE-001
- `docs/architecture/discovery-flow.md`
- `docs/architecture/module-lifecycle.md`
- ADR-003 — Module Manifest Specification
- ADR-010 — Module Identity Strategy
