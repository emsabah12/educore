# PRD Documentation

- **Collection Status**: Current + historical documents; use each document header as lifecycle authority
- **Updated**: 2026-08-18

Folder ini menyimpan Product/Engineering Requirement Documents EduCore. Dokumen lama tidak otomatis menjadi current implementation contract setelah arsitektur berevolusi.

## Current Frontend Foundation

- [`PRD-001-frontend-foundation.md`](PRD-001-frontend-foundation.md) — canonical consolidated Frontend Foundation PRD; **Accepted / FE-0 sampai FE-9 locked**.
- [`FE-000-frontend-contract-baseline_Scope-verification.md`](FE-000-frontend-contract-baseline_Scope-verification.md) sampai [`FE-008-frontend-non-functional-requirements.md`](FE-008-frontend-non-functional-requirements.md) — detailed phase records supporting the consolidated Frontend Foundation PRD.

Frontend architectural decisions yang menjadi authority untuk implementation tersedia di [`../architecture/adr/README.md`](../architecture/adr/README.md), khususnya ADR-020 sampai ADR-031.

## Historical Documents

- [`CORE-001.md`](CORE-001.md) — original Platform Kernel Engineering PRD/proposal; **HISTORICAL**.

Untuk implemented backend architecture baseline saat ini gunakan [`../architecture/README.md`](../architecture/README.md).

Jika PRD baru dibuat, status dan target baseline harus ditulis eksplisit pada header dokumen.
