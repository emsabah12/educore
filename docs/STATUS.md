# Status Proyek EduCore

- **Diperbarui:** 2026-10-09
- **Tahap aktif:** F0 — Setup (PRD-000 §10)

## Keputusan penting

| Tanggal | Keputusan | Dokumen |
|---|---|---|
| 2026-10-09 | Rebuild dari nol sebagai simplified modular monolith; dokumen lama jadi referensi domain | ADR-001 |
| 2026-10-09 | Frontend Inertia 3 + React (starter kit resmi), auth Fortify, satu jalur session | ADR-001 §2.1 |
| 2026-10-09 | Pohon lembaga fleksibel (maks 6 tingkat); induk melihat turunan, tidak sebaliknya | PRD-000 §4, §7 |
| 2026-10-09 | Lembaga formal di dalam unit = anak unit; koordinator di bawah Biro pusat dengan penugasan fungsional (filter jenjang) | PRD-000 §4.4 |
| 2026-10-09 | Aturan berjenjang: terdekat menang, kecuali induk mengunci | PRD-000 §8 |
| 2026-10-09 | Modul awal: Core, HR, Academic | ADR-001 §2.2 |

## Fitur yang sudah ada

- F0 (menunggu verifikasi di mesin developer): kerangka modul `Core`, `HR`, `Academic`; provider modul; arch test batas modul; test PostgreSQL; CI dengan PostgreSQL.

## Tugas berikutnya

1. Verifikasi F0 di Laragon (`php artisan test`, `composer test`) dan commit pertama.
2. F1 — Tenant & pohon lembaga: migrasi `tenants`, `organizations`, `organization_closure`; validasi siklus & kedalaman; seeder tenant pertama.
3. F2 — Identitas & login global (Person, User, Membership, pilih workspace).
4. F3 — RBAC berbasis pohon + penugasan fungsional + scoped settings.
5. F4 — Audit, health, backup, README deploy.
6. Susun PRD HR baru (dari HR-001 s.d. HR-016) dan PRD Academic (belum ada sumber lama).
