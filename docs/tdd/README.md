# EduCore Test-Driven Development

- **Collection Status:** CURRENT ENGINEERING IMPLEMENTATION GUIDANCE
- **Purpose:** Test-Driven Development matrices and implementation verification
- **Specification Authority:** `../prd/`
- **Architecture Authority:** `../architecture/`
- **API Contract Authority:** `../api/openapi.yaml`
- **Engineering Planning:** `../sprint/`
- **Reference Baseline:** `TDD-001 — EduCore Frontend Foundation`

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
