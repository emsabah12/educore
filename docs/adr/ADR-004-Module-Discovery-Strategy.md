ADR-004 — Module Discovery Strategy

Status: ✅ Accepted (Sprint 1)

Keputusan

EduCore hanya akan melakukan discovery pada level pertama di dalam folder Modules/.

Struktur resmi:

Modules/
├── Core/
│ └── module.yaml
├── PPDB/
│ └── module.yaml
├── Academic/
│ └── module.yaml
└── Finance/
└── module.yaml

Discovery hanya mencari:

Modules/\*/module.yaml

Bukan:

Modules/\*\*/module.yaml
Alasan
Sesuai PRD Sprint 1.
Discovery menjadi sederhana.
Performa lebih baik.
Mudah dipahami developer baru.
Menghindari kompleksitas yang belum diperlukan.
Konsekuensi

Jika suatu hari nanti ingin:

Modules/
└── Education/
└── PPDB/
