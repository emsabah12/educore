# ADR-003 — Module Manifest Specification

**Status** : Accepted

**Date** : 2026-07-01

**Sprint** : CORE-001

---

# Context

Kernel EduCore membutuhkan mekanisme yang konsisten untuk mengenali setiap modul yang tersedia di dalam platform.

Setiap modul harus menyediakan informasi dasar seperti identitas, versi, provider, dan metadata lainnya sebelum dapat dimuat oleh Kernel.

Pertanyaan yang muncul pada tahap perancangan adalah bagaimana informasi tersebut sebaiknya disimpan.

Beberapa alternatif dipertimbangkan, mulai dari menggunakan file PHP, JSON, XML, hingga YAML.

---

# Decision

Setiap modul wajib memiliki sebuah file `module.yaml` yang berfungsi sebagai **Module Manifest**.

Manifest merupakan kontrak antara modul dan Kernel.

File ini hanya berisi **metadata statis** yang mendeskripsikan modul dan tidak boleh digunakan untuk menyimpan konfigurasi runtime.

Minimal informasi yang disediakan meliputi:

- Schema Version
- Module ID
- Module Name
- Module Version
- Description
- Service Providers
- Dependencies
- Metadata
- Extra Information

Kernel membaca manifest pada saat proses discovery dan mengubahnya menjadi objek `ModuleDefinition`.

---

# Rationale

YAML dipilih karena memiliki sintaks yang ringkas, mudah dibaca oleh manusia, dan cocok untuk menyimpan metadata.

Dengan menggunakan manifest terpisah:

- Metadata modul menjadi independen dari implementasi PHP.
- Informasi modul dapat dibaca tanpa melakukan bootstrap Laravel.
- Struktur manifest dapat divalidasi sebelum modul dimuat.
- Evolusi format dapat dilakukan melalui versioning (`schema`).

Manifest diperlakukan sebagai **kontrak deklaratif**, bukan sebagai sumber konfigurasi runtime.

---

# Consequences

## Positive

- Setiap modul memiliki format metadata yang konsisten.
- Validasi manifest dapat dilakukan lebih awal.
- Metadata dapat dibaca tanpa mengeksekusi kode modul.
- Format mudah dipahami oleh developer.

## Negative

- Membutuhkan parser YAML.
- Membutuhkan proses validasi manifest.
- Menambah satu file wajib pada setiap modul.

---

# Alternatives Considered

## Option A — PHP Configuration File

Metadata disimpan dalam file PHP yang mengembalikan array.

**Ditolak** karena membutuhkan eksekusi kode PHP hanya untuk membaca metadata.

---

## Option B — JSON

Metadata disimpan dalam format JSON.

**Ditolak** karena kurang nyaman dibaca dan dipelihara oleh manusia dibandingkan YAML.

---

## Option C — XML

Metadata disimpan dalam XML.

**Ditolak** karena lebih verbose dan tidak memberikan keuntungan yang signifikan.

---

## Option D — YAML (**Dipilih**)

Metadata disimpan dalam `module.yaml`.

Pendekatan ini memberikan keseimbangan antara keterbacaan, fleksibilitas, dan kemudahan validasi.

---

# Constraints

Manifest hanya boleh berisi metadata.

Manifest **tidak boleh** berisi:

- Status enable/disable.
- Runtime configuration.
- Dynamic values.
- Environment-specific configuration.

Status runtime dikelola secara terpisah oleh `ModuleStateRepository`.

---

# Current Implementation

Status implementasi pada Sprint CORE-001:

- ✅ `module.yaml` pada setiap modul.
- ✅ Module Manifest Parser.
- ✅ Manifest Validator.
- ✅ Module Definition Factory.
- ✅ Module Definition.

---

# Future Evolution

Format manifest dapat berkembang melalui peningkatan versi `schema`.

Contoh evolusi di masa depan:

- Module Category
- License
- Author
- Homepage
- Minimum Kernel Version
- Compatibility Matrix
- Required PHP Version

Perubahan format harus tetap menjaga kompatibilitas melalui mekanisme versioning.

---

# References

- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Metadata Source of Truth
- ADR-006 — Runtime Module State Repository
- PRD CORE-001
- Sprint 001
