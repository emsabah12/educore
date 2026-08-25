# HR Reporting — Phase 2H Supporting Documentation

- **Collection Status:** SUPPORTING / CLOSED PHASE
- **Phase:** 2H — HR Reporting, Dashboard & Government Export
- **Module:** EduCore HR
- **Canonical Consolidated Specification:** [`HR-009`](../../HR-009-hr-reporting-dashboard-government-export-specification.md)
- **Architecture Authority:** [`ADR-032`](../../../../architecture/adr/ADR-032-hr-domain-boundary-workforce-architecture.md)
- **Relationship to Canonical Specification:** Supporting analysis and approved phase decisions consolidated into HR-009
- **Historical Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`

---

## 1. Purpose

Folder ini menyimpan supporting documentation yang digunakan selama Phase 2H
untuk membentuk canonical HR Reporting, Dashboard, dan Government Export
Specification EduCore.

Phase 2H terdiri dari rangkaian analisis dan keputusan bertahap mengenai:

- HR Reporting domain boundary dan scope;
- reporting requirements dan KPI catalog;
- read-model architecture;
- dashboard authorization dan privacy;
- government export boundary untuk Dapodik dan EMIS/EMIS GTK;
- auditability, privacy, freshness, dan operational NFR;
- final integration review dan phase closure.

Hasil konsolidasi final dari seluruh rangkaian tersebut adalah:

[`HR-009 — HR Reporting, Dashboard & Government Export Specification`](../../HR-009-hr-reporting-dashboard-government-export-specification.md)

---

## 2. Authority

Dokumen Phase 2H-A sampai Phase 2H-G adalah supporting approved phase
artifacts.

Untuk implementation dan current HR reporting design, canonical authority
adalah:

```text
Latest approved project decision
        ↓
Accepted ADR
        ↓
HR-001 → HR-008
        ↓
HR-009
        ↓
Current Repository Implementation
```
