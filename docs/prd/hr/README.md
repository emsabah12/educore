# EduCore Human Resources Documentation

- **Collection Status:** CURRENT — APPROVED / LOCKED HR SPECIFICATION
- **Module:** HR
- **Architecture Authority:** `ADR-032 — HR Domain Boundary & Workforce Architecture`
- **Primary Product Authority:** `HR-001 — Human Resources Management PRD`
- **Current Specification Range:** `HR-001` through `HR-016`
- **Engineering Planning:** `../../sprint/hr/`
- **Updated:** 2026-08-25

---

## 1. Purpose

Folder ini merupakan canonical specification collection untuk Human Resources
Management EduCore.

Dokumen di dalam collection ini mendefinisikan:

- business dan product requirements;
- HR domain boundaries;
- workforce architecture;
- recruitment dan onboarding;
- leave dan permit;
- attendance boundary;
- compensation, benefit, dan payroll input;
- performance, competency, dan development;
- document, contract, discipline, dan offboarding;
- HR reporting dan government export;
- HR information architecture dan transaction UX;
- authorization;
- security dan privacy;
- performance, backup, dan recovery;
- logging, monitoring, deployment, dan rollback readiness.

Dokumen ini harus dibaca bersama:

- [`ADR-032`](../../architecture/adr/ADR-032-hr-domain-boundary-workforce-architecture.md)
- current EduCore architecture documentation;
- Accepted ADR yang menjadi dependency masing-masing HR specification.

---

## 2. Documentation Authority

Urutan authority untuk implementasi HR:

```text
Latest approved project decision
        ↓
Accepted ADR
        ↓
HR-001 → HR-016 Approved / Locked Specification
        ↓
Current Repository Implementation
        ↓
HR Engineering Planning
        ↓
Sprint / Execution Brief
        ↓
Historical Handoff / Supporting Evidence
```
