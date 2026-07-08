# ADR-003 — Module Manifest Specification

Version : 1.0
Status : Accepted
Date : 2026-07-01
Updated : 2026-07-07
Sprint : CORE-001 Sprint-1

## Related ADR

- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository
- ADR-010 — Module Identity Strategy

---

# Context

Platform Kernel membutuhkan mekanisme yang konsisten untuk mengenali setiap modul yang tersedia di dalam platform.

Setiap modul harus menyediakan informasi dasar seperti identitas, versi, provider, dependency, dan metadata lainnya sebelum dapat diproses oleh Discovery Pipeline.

Beberapa alternatif dipertimbangkan, mulai dari menggunakan file PHP, JSON, XML, hingga YAML.

## Update Update 2026-07-07

Pada implementasi awal, `ModuleRegistry` dipicu secara _eager_ (langsung memindai direktori fisik `base_path('Modules')`) saat di-resolve di dalam tingkatan Service Provider.

Ketika perintah Artisan dijalankan, Laravel secara internal melakukan inspeksi instansi Command beserta graf dependensinya. Karena modul `Core` sendiri memiliki manifest fisik di dalam disk, pemindaian _eager_ ini memicu loop rekursif sirkular (_circular dependency loop_) yang menyebabkan kegagalan senyap (_silent hard exit/freeze_) atau kehabisan memori (_memory limit exhausted_) pada CLI.

## Selain itu, penggunaan interface `Contracts/ModuleRepository` dinilai sebagai abstraksi dini (_premature abstraction_) yang meningkatkan beban kognitif kode tanpa keuntungan ekstensibilitas yang nyata untuk saat ini.

# Decision

Setiap modul **wajib** memiliki sebuah berkas `module.yaml` yang berfungsi sebagai **Module Manifest**.

Manifest merupakan kontrak deklaratif antara modul dan Platform Kernel.

Manifest hanya berisi **metadata statis** yang mendeskripsikan modul dan tidak boleh digunakan untuk menyimpan konfigurasi runtime.

Minimal informasi yang tersedia meliputi:

- Schema Version (`schema`)
- Module Name (`name`)
- Display Name (`display_name`)
- Module Version (`version`)
- Description (`description`)
- Service Providers (`providers`)
- Dependencies (`dependencies`)
- Metadata (`metadata`)
- Extra Information (`extra`)

Pada proses discovery, manifest diproses melalui pipeline berikut:

```text
ModuleManifestLoader
        │
        ▼
ModuleManifestParser
        │
        ▼
ModuleManifestValidator
        │
        ▼
ModuleDefinitionFactory
        │
        ▼
ModuleDefinition
```

`ModuleDefinition` menjadi representasi immutable dari metadata modul selama aplikasi berjalan.

## Update Update 2026-07-07

1. **Penerapan Lazy Bootstrap Guard**: `ModuleRegistry` akan di-resolve murni sebagai objek in-memory kosong terlebih dahulu pada IoC Container.
2. **Just-In-Time (JIT) Discovery**: Proses pemindaian disk fisik (`ModuleBootstrapService->bootstrap()`) dialihkan sepenuhnya ke dalam penutupan (_closure_) resolve `ModuleRepository` (Query Model). Pemindaian hanya berjalan secara _lazy_ (tepat saat data modul pertama kali diminta oleh aplikasi) dan dilindungi oleh _guard condition_ (`$registry->count() === 0`).
3. **Pensiun Abstraksi Interface**: Menghapus `Modules/Core/Contracts/ModuleRepository.php`. Mengunci kelas konkrit `Modules/Core/Services/ModuleRepository.php` sebagai _Single Source of Truth_ untuk lapisan pembacaan metadata (_Query Model_).
4. **Thin Commands Pattern**: Semua Artisan Commands (`module:list`, `module:status`, `module:enable`, `module:disable`) wajib dijaga tetap tipis, tanpa logika manipulasi file (I/O) ataupun parsing manifest.

---

# Rationale

YAML dipilih karena memiliki sintaks yang ringkas, mudah dibaca manusia, dan sesuai untuk menyimpan metadata.

Dengan menggunakan manifest terpisah:

- Metadata modul independen dari implementasi PHP.
- Informasi modul dapat dibaca tanpa melakukan bootstrap Laravel.
- Struktur manifest dapat divalidasi sebelum modul digunakan.
- Evolusi format dapat dilakukan melalui versioning (`schema`).
- Metadata dapat diproses secara bertahap melalui Discovery Pipeline.

Manifest diperlakukan sebagai **kontrak deklaratif**, bukan sebagai sumber konfigurasi runtime.

---

# Consequences

## Positive

- Setiap modul memiliki format metadata yang konsisten.
- Validasi dapat dilakukan sebelum runtime.
- Metadata dapat dibaca tanpa mengeksekusi kode PHP.
- Discovery Pipeline menjadi lebih sederhana dan mudah diuji.
- Metadata menjadi immutable setelah diproses.

## Negative

- Membutuhkan parser YAML.
- Membutuhkan proses validasi manifest.
- Setiap modul wajib menyediakan `module.yaml`.
- Perubahan format harus tetap menjaga kompatibilitas melalui versioning.

## Update 2026-07-07

- **Positif**: Perintah Artisan kini 100% aman dari resiko crash akibat loop bootstrap sirkular.
- **Positif**: Arsitektur sasis lebih bersih, mematuhi prinsip YAGNI, KISS, dan memangkas _dead code_.
- **Positif**: Exception / Error syntax pada manifest modul di disk riil kini dapat ditangkap oleh Laravel dan ditampilkan secara transparan di terminal, bukan mati senyap.
- **Netral**: Pengujian unit pada repositori wajib diselaraskan untuk mengarah langsung pada kelas layanan konkrit (`Services/ModuleRepository`).

---

# Alternatives Considered

## Option A — PHP Configuration File

Metadata disimpan dalam file PHP yang mengembalikan array.

**Rejected**, karena membutuhkan eksekusi kode PHP hanya untuk membaca metadata.

---

## Option B — JSON

Metadata disimpan dalam format JSON.

**Rejected**, karena kurang nyaman dibaca dan dipelihara dibandingkan YAML.

---

## Option C — XML

Metadata disimpan dalam XML.

**Rejected**, karena lebih verbose tanpa memberikan keuntungan yang signifikan.

---

## Option D — YAML (**Accepted**)

Metadata disimpan dalam `module.yaml`.

Pendekatan ini memberikan keseimbangan antara keterbacaan, fleksibilitas, kemudahan validasi, dan evolusi format.

---

# Constraints

Manifest hanya boleh berisi metadata.

Manifest **tidak boleh** berisi:

- Runtime Module State.
- Status enable atau disable.
- Runtime configuration.
- Dynamic values.
- Environment-specific configuration.

Status runtime dikelola secara terpisah oleh `ModuleStateRepository`.

Identity modul berasal dari field `name`, sesuai keputusan pada ADR-010.

---

# Current Implementation

Status implementasi pada akhir Sprint CORE-001:

- ✅ `module.yaml` pada setiap modul.
- ✅ Module Manifest Loader.
- ✅ Module Manifest Parser.
- ✅ Module Manifest Validator.
- ✅ Module Definition Factory.
- ✅ Immutable Module Definition.
- ✅ Discovery Pipeline berbasis tahapan yang terpisah.

---

# Future Evolution

Format manifest dapat berkembang melalui peningkatan versi `schema`.

Contoh evolusi di masa depan:

- Module Category
- License
- Author
- Homepage
- Minimum Platform Kernel Version
- Compatibility Matrix
- Required PHP Version

Perubahan format harus tetap menjaga kompatibilitas melalui mekanisme versioning.

---

# References

- PRD CORE-001
- Sprint CORE-001
- `docs/architecture/discovery-flow.md`
- `docs/architecture/module-lifecycle.md`
- ADR-004 — Automatic Module Discovery
- ADR-010 — Module Identity Strategy
