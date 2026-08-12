# EduCore Documentation Index

- **Status**: Current Documentation Entry Point
- **Updated**: 2026-08-12

Dokumentasi EduCore menggunakan lifecycle eksplisit agar dokumen current tidak tercampur dengan planning atau keputusan lama.

## 1. Current Architecture

Mulai dari:

1. [`architecture/README.md`](architecture/README.md) — current architecture entry point.
2. [`architecture/current-architecture.md`](architecture/current-architecture.md) — locked implementation baseline.
3. [`architecture/folder-structure.md`](architecture/folder-structure.md) — repository/ownership map.
4. [`architecture/architecture-principles.md`](architecture/architecture-principles.md) — current guardrails.
5. [`architecture/adr/README.md`](architecture/adr/README.md) — ADR lifecycle dan index.

## 2. Documentation Lifecycle

| Classification | Meaning | Usage |
| --- | --- | --- |
| **CURRENT** | Current implementation/documentation baseline | Boleh dipakai sebagai implementation reference |
| **ACCEPTED ADR** | Architectural decision yang masih berlaku | Wajib dihormati sampai disupersede |
| **SUPERSEDED** | Historical decision yang sudah diganti | Hanya untuk memahami evolution/history |
| **HISTORICAL** | PRD, sprint, review, atau planning lama | Bukan current implementation contract |
| **FUTURE / NOT LOCKED** | Arah atau candidate design yang belum diterima | Tidak boleh diimplementasikan sebagai contract tanpa audit/lock |

## 3. Historical Collections

Dokumen planning awal dipertahankan di lokasi aslinya untuk menjaga link dan history:

- [`prd/README.md`](prd/README.md)
- [`sprint/README.md`](sprint/README.md)

Current historical documents:

- [`prd/CORE-001.md`](prd/CORE-001.md) — original Platform Kernel Engineering PRD / proposal.
- [`sprint/sprint-001.md`](sprint/sprint-001.md) — original Sprint 001 planning/review.

Historical documents tidak boleh mengalahkan current architecture atau Accepted ADR.

## 4. Future Architecture

Visi multi-lembaga, multi-cabang, dan manajemen asrama sudah menjadi product direction, tetapi topology berikut belum menjadi current contract:

```text
Tenant
  └── Organization / Lembaga
        └── Organization Unit / Branch
```

Organization, branch/unit, organizational assignment, organizational context, scoped authorization, dan dormitory topology harus melalui audit dan architectural lock terlebih dahulu.

## 5. Documentation Alignment Status

```text
DOC STEP 1 — Current architecture index              COMPLETE
DOC STEP 2 — Canonical architecture ADRs             COMPLETE
DOC STEP 3 — Current repository structure            COMPLETE
DOC STEP 4 — Architecture principles refresh         COMPLETE
DOC STEP 5 — Module Kernel revalidation              COMPLETE
DOC STEP 6 — Historical PRD/Sprint classification    COMPLETE
DOC STEP 7 — Documentation consistency closure       COMPLETE
```

**EduCore Documentation Alignment: LOCKED / COMPLETE.**

Future documentation must extend this baseline through the same lifecycle (`CURRENT`, `ACCEPTED ADR`, `SUPERSEDED`, `HISTORICAL`, or `FUTURE / NOT LOCKED`) rather than silently rewriting historical decisions.
