# ADR-003 — Module Manifest Specification

Version : 1.0
Status : Accepted
Date : 2026-07-01
Updated : 2026-07-02
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

---

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
