# Architecture Decision Records

Folder ini berisi seluruh keputusan arsitektur yang telah diterima pada EduCore Platform.

ADR digunakan untuk menjelaskan **mengapa** suatu keputusan diambil, bukan **bagaimana** implementasinya.

---

# Status

| Status     | Arti                   |
| ---------- | ---------------------- |
| Proposed   | Sedang dipertimbangkan |
| Accepted   | Disetujui              |
| Superseded | Digantikan ADR lain    |
| Deprecated | Tidak lagi digunakan   |

---

# Daftar ADR

| ADR     | Judul                                          | Status   |
| ------- | ---------------------------------------------- | -------- |
| ADR-001 | Kernel Architecture Overview                   | Accepted |
| ADR-002 | Modular Monolith Architecture                  | Accepted |
| ADR-003 | Module Manifest Specification                  | Accepted |
| ADR-004 | Automatic Module Discovery                     | Accepted |
| ADR-005 | Module Registry as Metadata Source of Truth    | Accepted |
| ADR-006 | Runtime Module State Repository                | Accepted |
| ADR-007 | ModuleManager as Kernel Facade                 | Accepted |
| ADR-008 | Thin Command Pattern                           | Accepted |
| ADR-009 | Separation of Infrastructure and Kernel Domain | Accepted |

---

# Aturan

Setiap ADR harus memiliki:

- Context
- Decision
- Rationale
- Consequences
- Alternatives
- Current Implementation
- Future Evolution

ADR tidak boleh diubah tanpa alasan yang jelas.

Jika keputusan berubah secara fundamental, buat ADR baru dan tandai ADR lama sebagai **Superseded**.
