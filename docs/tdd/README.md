# EduCore Test-Driven Development

- **Collection Status:** CURRENT ENGINEERING IMPLEMENTATION GUIDANCE
- **Updated:** 2026-08-29
- **Purpose:** Test-Driven Development matrices and implementation verification
- **Specification Authority:** `../prd/`
- **Architecture Authority:** `../architecture/`
- **API Contract Authority:** `../api/openapi.yaml`
- **Engineering Planning:** `../sprint/`
- **Reference Baseline:** `TDD-001 — EduCore Frontend Foundation` — ✅ COMPLETE / LOCKED at `1094dad05ec4589a9e83a40fae249eef01591b94`

---

## 1. Purpose

Folder `docs/tdd/` merupakan canonical collection untuk Test-Driven Development
(TDD) dan implementation verification matrices EduCore.

TDD documents menerjemahkan approved requirement, architecture, API contract,
dan engineering task menjadi urutan implementation yang dapat diverifikasi.

Canonical lifecycle:

```text
Approved Specification / ADR / Contract
        ↓
Engineering Task
        ↓
RED
        ↓
Minimal GREEN Implementation
        ↓
REFACTOR
        ↓
Architecture / Contract Gate
        ↓
LOCK Milestone
```

---

## 2. Current Frontend Foundation Verification

[`TDD-001-frontend-foundation.md`](TDD-001-frontend-foundation.md) adalah implemented/locked verification contract untuk Frontend Foundation.

```text
FEI-1 → FEI-12
✅ COMPLETE / LOCKED

Final implementation checkpoint
1094dad05ec4589a9e83a40fae249eef01591b94
```

TDD-001 tetap menjadi regression contract untuk source boundary, OpenAPI generation, BrowserSession/BFF transport, Membership/Tenant dan Workspace isolation, authorization UX, routing/module isolation, error/recovery, security/build gates, observability, serta critical browser E2E behavior.
