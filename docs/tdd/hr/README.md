# EduCore HR — Test-Driven Development

- **Collection Status:** CURRENT IMPLEMENTATION TDD INDEX
- **Module:** HR
- **Specification Authority:** [`../../prd/hr/`](../../prd/hr/)
- **Architecture Authority:** [`ADR-032`](../../architecture/adr/ADR-032-hr-domain-boundary-workforce-architecture.md)
- **Engineering Planning:** [`../../sprint/hr/`](../../sprint/hr/)
- **Current Execution Entry Point:** [`SC-HR-00`](../../sprint/hr/execution/SC-HR-00/)
- **TDD Convention:** [`TDD-001 — EduCore Frontend Foundation`](../TDD-001-frontend-foundation.md)

---

## 1. Purpose

Folder ini merupakan canonical Test-Driven Development collection untuk
implementasi Human Resources Management EduCore.

HR TDD documents menerjemahkan:

- approved HR specification;
- Accepted ADR;
- engineering task;
- API contract;
- security requirement;
- data-integrity invariant;
- migration requirement;

menjadi implementation milestones yang dapat dibuktikan melalui automated
evidence.

Canonical flow:

```text
HR Specification
        ↓
Architecture / Contract
        ↓
Engineering Task
        ↓
TDD Milestone
        ↓
RED
        ↓
GREEN
        ↓
REFACTOR
        ↓
Architecture / Contract Gate
        ↓
LOCK
```
