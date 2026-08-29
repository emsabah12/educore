# EduCore Documentation Index

- **Status**: Current Documentation Entry Point
- **Updated**: 2026-08-29

Dokumentasi EduCore menggunakan lifecycle eksplisit agar dokumen current tidak tercampur dengan planning atau keputusan lama.

## 1. Current Architecture

Mulai dari:

1. [`architecture/README.md`](architecture/README.md) — current architecture entry point.
2. [`architecture/current-architecture.md`](architecture/current-architecture.md) — locked implementation baseline.
3. [`architecture/folder-structure.md`](architecture/folder-structure.md) — repository/ownership map.
4. [`architecture/architecture-principles.md`](architecture/architecture-principles.md) — current guardrails.
5. [`architecture/adr/README.md`](architecture/adr/README.md) — ADR lifecycle dan index.

## 1A. Current Frontend Foundation Authority

Frontend Foundation telah melewati PRD → ADR → TDD → implementation → final gate dan sekarang merupakan bagian dari current locked foundation.

Canonical reading set:

1. [`prd/PRD-001-frontend-foundation.md`](prd/PRD-001-frontend-foundation.md) — locked product semantics dan implementation-resolution record.
2. [`architecture/adr/README.md`](architecture/adr/README.md) — accepted ADR-020 sampai ADR-031.
3. [`tdd/TDD-001-frontend-foundation.md`](tdd/TDD-001-frontend-foundation.md) — implemented/locked FEI-1 sampai FEI-12 verification contract.
4. [`api/openapi.yaml`](api/openapi.yaml) — executable HTTP transport contract.
5. [`architecture/current-architecture.md`](architecture/current-architecture.md) — current implemented architecture baseline.

Final Frontend Foundation implementation checkpoint:

```text
1094dad05ec4589a9e83a40fae249eef01591b94
```

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

## 4. Current Foundation Coverage

Beberapa capability yang sebelumnya didokumentasikan sebagai future direction telah melewati audit dan architectural lock dan sekarang menjadi bagian dari current foundation baseline.

Current foundation coverage mencakup:

- organizational topology dan hierarchy;
- membership organizational assignment;
- authenticated organizational context;
- scoped authorization persistence dan evaluation;
- Dormitory integration boundary;
- OpenAPI-backed HTTP API contract dan discoverability;
- Frontend Foundation FEI-1 sampai FEI-12;
- first-party BrowserSession/BFF authentication with server-side bearer custody;
- tab-local Membership/Tenant context and verified Workspace context;
- capability-aware frontend authorization UX with backend authority;
- canonical `frontend/` SPA source boundary, static routing, code splitting, security/build gates, observability, dan browser E2E coverage.

Detail schema, ownership boundary, authorization semantics, dan integration contract tidak diduplikasi di documentation index ini. Gunakan current architecture documentation dan Accepted ADR sebagai canonical reference.

Future capability yang belum melewati audit dan architectural lock tetap harus diklasifikasikan sebagai `FUTURE / NOT LOCKED` dan tidak boleh dianggap sebagai implementation contract.

## 5. Documentation Alignment

Current-state documentation harus direvalidasi ketika locked implementation atau architectural contract berubah.

Status `CURRENT` pada dokumen berarti dokumen tersebut merepresentasikan baseline yang berlaku pada revision saat ini; status tersebut bukan jaminan bahwa dokumentasi tidak akan membutuhkan alignment pada foundation phase berikutnya.

Future documentation must extend this baseline through the same lifecycle (`CURRENT`, `ACCEPTED ADR`, `SUPERSEDED`, `HISTORICAL`, or `FUTURE / NOT LOCKED`) rather than silently rewriting historical decisions.
