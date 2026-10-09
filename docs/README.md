# Dokumentasi EduCore

| Folder            | Isi                                                 | Status                                 |
| ----------------- | --------------------------------------------------- | -------------------------------------- |
| `adr/`            | Keputusan arsitektur (Architecture Decision Record) | **CURRENT**                            |
| `prd/`            | Kebutuhan produk per tahap/modul                    | **CURRENT**                            |
| `../docs-legacy/` | Seluruh dokumentasi repo lama (isi `docs.zip`)      | **HISTORICAL — referensi domain saja** |

## Urutan baca

1. `adr/ADR-001-rebuild-simplified-modular-monolith.md` — kenapa dan bagaimana repo ini dibangun ulang.
2. `prd/PRD-000-platform-foundation.md` — pondasi: tenant, pohon lembaga, login, RBAC, aturan berjenjang.

## Aturan dokumen

- Dokumen di `adr/` dan `prd/` adalah sumber kebenaran. Jika kode berbeda dengan dokumen, salah satunya harus diperbarui dalam PR yang sama.
- Dokumen lama di `docs-legacy/` **tidak** boleh dijadikan kontrak implementasi langsung; ambil keputusan domainnya, lalu tuliskan ulang di PRD baru.
- Setiap keputusan baru diberi label: **[OWNER]** (keputusan pemilik produk), **[LAMA]** (diambil dari dokumen lama), **[ASUMSI]** (usulan, perlu konfirmasi).
