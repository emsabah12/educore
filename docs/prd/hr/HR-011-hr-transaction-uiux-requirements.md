# HR-011 — HR Transaction UI/UX Requirements

**Phase:** 3B — HR Transaction UI/UX
**Version:** 0.1
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-22
**Depends on:** HR-001–HR-010, ADR-032, FE-002, FE-005, FE-006, FE-007

1. Objective

HR-011 mendefinisikan pola interaksi transaksi HR agar seluruh workflow memiliki perilaku yang:

konsisten;
aman terhadap accidental mutation;
mempertahankan domain lifecycle;
mempertahankan tenant/workspace context;
tidak menyembunyikan business state penting;
tidak menggunakan frontend sebagai authorization authority.

Target umum:

Discover
→ Inspect
→ Create / Edit / Action
→ Validate
→ Confirm when necessary
→ Execute
→ Resolve resulting state
→ Show authoritative result

Tidak semua transaksi harus mengikuti seluruh tahapan. Confirmation hanya digunakan bila dampak operasinya memang membutuhkan confirmation.

2. Transaction UX Principles
   HR-011-BR-001 — State first

UI harus menampilkan business state aktual, bukan hanya menyediakan CRUD.

Contoh:

Employment
ACTIVE
ENDED
PLANNED

lebih penting daripada sekadar tombol:

Edit
Delete
HR-011-BR-002 — Domain action over generic edit

Perubahan lifecycle penting harus menggunakan explicit domain action.

Contoh:

End Employment
Approve Leave
Finalize Attendance
Complete Offboarding

bukan:

Edit Status

[REKOMENDASI] Generic status dropdown tidak digunakan untuk lifecycle yang mempunyai business rules.

HR-011-BR-003 — Mutation tidak auto-retry

Mengikuti FE-007:

POST / PATCH / PUT / DELETE
→ default NO automatic retry

Jika response mutation ambigu akibat network failure:

refresh authoritative state
→ baru tentukan apakah perlu retry
HR-011-BR-004 — Server remains authority

Frontend validation adalah usability layer.

Client validation
≠ canonical validation

Server-side validation dan domain conflict tetap authoritative.

HR-011-BR-005 — Context must remain explicit

Selama transaction, user harus tetap dapat mengetahui:

Tenant
Workspace
Employee / Candidate / Case context
Current lifecycle state

khususnya untuk mutation administratif.

3. Canonical Page Pattern

Untuk entity/workflow HR, pola default adalah:

List / Worklist
↓
Detail / Case Workspace
↓
Explicit Action
↓
Form / Action Panel
↓
Validation
↓
Mutation
↓
Updated Authoritative State

Bukan semua capability harus menyediakan:

List
Create
Edit
Delete

karena banyak HR record bersifat lifecycle, ledger, approval, atau immutable evidence.

4. List & Worklist Pattern
   HR-011-FR-001

List page harus mendukung minimum:

page title;
current context;
result count bila tersedia;
pagination untuk dataset besar;
filter yang relevan terhadap domain;
clearly differentiated empty/error/loading states;
primary action hanya jika user mempunyai capability.
HR-011-FR-002

Operational workflow menggunakan worklist, bukan memaksa user mencari record secara manual.

Contoh:

Leave
→ Pending Approvals

Attendance
→ Needs Reconciliation

Recruitment
→ Awaiting Decision

Offboarding
→ In Progress
HR-011-FR-003

Default filter/status tidak boleh menyebabkan record kritikal seolah hilang tanpa indikasi.

Jika filter aktif:

Filter state
→ visible
→ removable 5. Detail / Case Workspace Pattern
HR-011-FR-004

Entity dengan lifecycle kompleks menggunakan dedicated detail/workspace.

Header minimum:

Identity
Business identifier
Lifecycle state
Relevant context
Available actions

Contoh:

Ahmad Fauzi
NIP: EMP-001

Employment: ACTIVE
Organization: SMA A
HR-011-FR-005

Available action harus bergantung pada:

Permission
AND Context
AND Current Domain State

Bukan permission saja.

User yang mempunyai permission approve tidak otomatis dapat approve sesuatu yang statusnya sudah finalized.

6. Create Transaction Pattern
   HR-011-FR-006

Create flow harus memisahkan informasi berdasarkan domain ownership.

Contoh Employee provisioning tidak boleh mempunyai satu form besar yang mencampurkan:

Person identity
Employment
organizational placement
authorization role
payroll

seolah seluruhnya satu entity.

Recommended initial flow
Create Employee
↓
Resolve / Create Person
↓
Resolve Membership
↓
Create Employee HR Profile

Employment dan placement mengikuti lifecycle masing-masing.

HR-011-FR-007

User harus mengetahui bila operation akan menggunakan existing Person/Membership versus membuat yang baru.

Identity resolution tidak boleh melakukan silent merge.

7. Edit Pattern
   HR-011-FR-008

Editable master data dapat menggunakan conventional edit form.

Tetapi immutable/finalized data tidak boleh muncul seolah freely editable.

Pattern:

DRAFT
→ editable

FINALIZED
→ read-only
→ correction/revision action if domain allows
HR-011-FR-009

Edit form harus melindungi unsaved changes ketika:

pindah route;
pindah workspace;
pindah tenant;
reload/close browser.

Sesuai FE-007:

Discard changes and continue? 8. Confirmation Policy

Confirmation tidak digunakan pada setiap Save.

Confirmation REQUIRED

[REKOMENDASI]

Gunakan explicit confirmation untuk high-impact domain action seperti:

final approval;
rejection yang menutup workflow;
Employment termination/end;
finalized agreement action;
disciplinary finalization;
offboarding completion;
export data sensitif;
operation irreversible atau sulit dikoreksi.

Confirmation harus menjelaskan dampak bisnis, bukan sekadar:

Are you sure?

Contoh conceptual:

End this Employment?

Effective date: 31 Aug 2026

This ends the current Employment record.
It does not automatically deactivate Membership.

Ini mempertahankan locked lifecycle boundary.

9. Success State
   HR-011-FR-010

Setelah successful mutation:

Server response
→ authoritative cache/state update
→ success feedback
→ resulting lifecycle state visible

Success tidak hanya berupa toast.

Contoh setelah approval:

Status: APPROVED
Approved by: ...
Approved at: ...

bila data tersebut memang disediakan backend.

10. Validation & Conflict
    HR-011-FR-011

422 VALIDATION_FAILED ditampilkan:

field-level error

- form-level summary where appropriate
  HR-011-FR-012

409 atau equivalent domain conflict harus diperlakukan sebagai state conflict, bukan validation field.

Contoh:

Employment has changed since this page was loaded.

Recovery:

preserve safe form input
→ refresh current state
→ explain conflict 11. Employee / Workforce UX
HR-011-FR-013

Workforce directory menjadi gateway ke Employee HR profile.

Recommended detail:

Employee
├── Overview
├── Employment
├── Placement
├── Leave
├── Attendance
├── Compensation
├── Performance
├── Documents
└── Discipline

Tab visibility mengikuti HR-010 authorization rules.

HR-011-FR-014

Existing field:

jabatan

tidak menjadi target UX Position/placement.

[REKOMENDASI]

Target UX memisahkan:

Employee Profile
Employment
Organizational Assignment
Position

sesuai ownership masing-masing.

12. Employment Lifecycle UX
    HR-011-FR-015

Employment history harus terlihat sebagai collection/history:

Employee
├── Employment #1 — ENDED
├── Employment #2 — ENDED
└── Employment #3 — ACTIVE

bukan overwritten employment fields.

HR-011-FR-016

Rehire menggunakan explicit flow:

Create New Employment

dan bukan mengaktifkan kembali historical Employment.

HR-011-FR-017

End Employment action harus membedakan konsekuensinya dari:

Membership deactivation
User deactivation
Offboarding completion

Tidak boleh ada UI wording yang menganggap semuanya terjadi otomatis.

13. Recruitment & Hiring UX

Canonical journey:

Candidate
→ Application
→ Selection
→ Hiring Approval
→ Onboarding
→ Identity Resolution
→ Employee
→ Employment PLANNED
→ Activation
HR-011-FR-018

Recruitment UI harus selalu membedakan:

Candidate
≠ Employee
HR-011-FR-019

Identity resolution menjadi explicit checkpoint sebelum conversion.

Kemungkinan result:

Existing Person found
Potential match
No match

Weak match tidak boleh menghasilkan silent merge.

HR-011-FR-020

Conversion action harus memberikan clear result apabila operation sudah pernah dilakukan sehingga idempotency tidak menghasilkan duplicate identity.

14. Leave & Permit UX
    HR-011-FR-021

Employee flow:

Request Leave
→ Review Request
→ Submit
→ Pending
→ Approved / Rejected
HR-011-FR-022

Approver menggunakan worklist berdasarkan effective authorization dan scope.

HR-011-FR-023

Entitlement presentation harus berasal dari ledger-derived balance.

UI tidak menyediakan generic:

Edit balance

sebagai normal operation.

Adjustment, jika nanti diperbolehkan, harus menjadi explicit ledger transaction dengan reason.

[OPEN DECISION] Exact adjustment policy belum locked.

15. Attendance UX
    HR-011-FR-024

Attendance UI harus memisahkan:

Raw Event
Reconciliation
Final Attendance Record
HR-011-FR-025

Raw event bersifat evidence/input dan tidak diberi action:

Mark as final attendance

tanpa reconciliation business process.

HR-011-FR-026

Anomaly/worklist harus memungkinkan operator memahami minimum:

expected state
observed event
approved leave/permit context
resulting reconciliation status 16. Compensation & Payroll Input UX
HR-011-FR-027

Compensation transaction harus tetap berada dalam HR ownership:

Compensation Facts
Benefits
Payroll Input Facts
HR-011-FR-028

UX tidak menyediakan Finance-owned action seperti:

Calculate Payroll
Post Payroll
Pay Employee
Generate Accounting Entry

di dalam HR.

HR-011-FR-029

Ketika future final settlement digunakan, UI harus membedakan purpose:

REGULAR_PAYROLL
FINAL_SETTLEMENT

sesuai pending additive requirement dari HR-008.

17. Performance / PKG / Competency / PKB UX
    HR-011-FR-030

UI harus mempertahankan separation:

Performance
Competency
Development
Certification

dan tidak menyimpulkan bahwa satu automatically mengubah yang lain.

HR-011-FR-031

Finalized performance assessment tidak boleh menyediakan arbitrary score editing.

Correction/reopening semantics:

[OPEN DECISION] bergantung business rule yang belum tersedia.

18. Documents & Employment Agreement UX
    HR-011-FR-032

Document upload harus menampilkan lifecycle yang relevan seperti:

Uploading
Stored
Finalized
Signed

hanya jika state tersebut didukung canonical model.

HR-011-FR-033

Finalized/signed version bersifat read-only.

Update dilakukan sebagai:

new version

bukan overwrite file final.

HR-011-FR-034

Document dan Employment Agreement tetap memiliki detail/action semantics terpisah.

Agreement expiry tidak boleh menampilkan otomatis:

Employment ended 19. Discipline UX
HR-011-FR-035

Discipline menggunakan case/action flow.

Case
→ Evidence / Review
→ Action
→ Finalization

Exact workflow tetap mengikuti tenant policy.

HR-011-FR-036

UI tidak boleh hardcode:

SP1 → SP2 → SP3

sebagai universal progression.

HR-011-FR-037

Setelah disciplinary action, UI tidak boleh secara otomatis mengubah:

Employment;
Position;
Compensation;
authorization Role.

Perubahan domain lain membutuhkan operation masing-masing.

20. Offboarding UX

Offboarding detail menggunakan case workspace:

Overview
Approval
Checklist
Handover
Access Review
Exit Interview
Settlement Facts
HR-011-FR-038

Offboarding progress harus memperlihatkan incomplete requirements sebelum completion.

HR-011-FR-039

Action:

Complete Offboarding

harus berbeda dari:

End Employment
HR-011-FR-040

Access Review tidak boleh otomatis mencabut seluruh Membership/Role karena grant provenance dan membership deactivation policy masih [OPEN DECISION].

21. Reporting / Export Transactions

Reporting mayoritas read-oriented, tetapi export merupakan transaction karena:

dapat menghasilkan sensitive artifact;
membutuhkan authorization berbeda;
dapat membutuhkan asynchronous processing.
HR-011-FR-041

Export action harus explicit:

Generate Export

dan tidak diasumsikan sama dengan View.

HR-011-FR-042

Untuk sensitive export, confirmation dapat menjelaskan:

scope;
filter/date period;
target export;
sensitivity notice.
HR-011-FR-043

Async export UX harus merepresentasikan state seperti:

QUEUED
PROCESSING
READY
FAILED

jika backend contract menyediakan state tersebut.

Tidak boleh menyimpan raw dataset pada client hanya untuk menunggu export selesai.

22. Long-Running Transaction Pattern

Untuk operasi yang memang async:

Submit
→ server accepts
→ job/run identifier
→ processing status
→ result

Page refresh atau browser close tidak boleh menjadi requirement agar process dapat selesai.

[CONSTRAINT] Exact operations yang membutuhkan async processing ditentukan per domain, bukan di-generalize untuk seluruh HR.

23. Sensitive Data Presentation
    HR-011-FR-044

Sensitive data menggunakan principle:

least disclosure
HR-011-FR-045

User yang dapat menjalankan operational transaction tidak otomatis dapat melihat seluruh sensitive fields pada Employee.

HR-011-FR-046

Mask/reveal behavior:

[DEFERRED ke Phase 3E]

karena exact masking dan sensitivity policy masih open decision.

24. Empty, Loading & Permission Behavior

HR-011 mengadopsi FE-007 tanpa membuat semantics baru.

State UX
Initial read skeleton/loading
Empty result domain-specific empty state
Mutation pending action disabled + progress
Capability unresolved protected action unavailable
403 permission state; backend wins
404 resource unavailable/not found
409 stale/domain conflict
422 inline validation
Network ambiguity after mutation verify current state first
5xx read retry/recovery where safe 25. Destructive/Delete Policy

[REKOMENDASI]

HR domain tidak menggunakan hard delete sebagai default business UX.

Gunakan canonical lifecycle/action seperti:

End
Cancel
Withdraw
Void
Deactivate
Archive

hanya jika domain specification mendukung action tersebut.

Physical deletion tidak boleh menjadi generic button hanya karena database mempunyai deleted_at.

26. Cross-Domain Actions

Satu page boleh menampilkan aggregated information untuk usability.

Tetapi:

one screen
≠ one transaction boundary

Contoh Employee detail dapat menampilkan HR + Attendance-derived data, tetapi mutation masing-masing harus tetap memanggil owner contract.

Frontend tidak boleh menyusun distributed transaction seperti:

update HR
→ update Finance
→ update Academic

dari browser.

27. Acceptance Criteria
    HR-011-AC-001 — Explicit Lifecycle Action

Given record mempunyai lifecycle transition
When user melakukan perubahan lifecycle
Then UI menggunakan named business action
And tidak hanya menyediakan generic status editor.

HR-011-AC-002 — Server Validation

Given client validation lolos
When backend mengembalikan validation error
Then backend result tetap authoritative
And error ditampilkan pada field/form yang sesuai.

HR-011-AC-003 — Domain Conflict

Given state record berubah sejak page dimuat
When mutation ditolak karena conflict
Then system menjelaskan stale/conflicting state
And tidak mengubahnya menjadi generic field validation.

HR-011-AC-004 — Employment End

Given user mengakhiri Employment
When operation berhasil
Then Employment menjadi ENDED sesuai canonical result
And UI tidak menganggap Membership otomatis inactive
And UI tidak menganggap Offboarding otomatis complete.

HR-011-AC-005 — Recruitment Identity

Given Candidate akan dikonversi
When potential Person match ditemukan
Then identity resolution menjadi explicit step
And weak match tidak auto-merge.

HR-011-AC-006 — Leave Balance

Given user melihat leave entitlement
When balance ditampilkan
Then value merupakan result ledger
And bukan arbitrary editable balance field.

HR-011-AC-007 — Attendance Evidence

Given raw attendance event tersedia
When operator membuka attendance
Then event tidak ditampilkan sebagai final attendance fact tanpa reconciliation.

HR-011-AC-008 — Document Finalization

Given document version finalized/signed
When user membuka version tersebut
Then content bersifat immutable
And perubahan membutuhkan version baru jika business rule mengizinkan.

HR-011-AC-009 — Unsaved Changes

Given transaction form mempunyai perubahan belum tersimpan
When user berpindah Tenant/Workspace/route
Then system meminta explicit discard decision.

HR-011-AC-010 — Mutation Network Ambiguity

Given response mutation tidak diterima karena network failure
When hasil server tidak diketahui
Then frontend tidak otomatis mengulang mutation
And authoritative state diverifikasi terlebih dahulu.

28. Scope
    IN SCOPE
    common HR transaction pattern;
    create/edit/action UX;
    list/worklist/detail pattern;
    approval/finalization pattern;
    domain lifecycle actions;
    validation/conflict behavior;
    unsaved form protection;
    HR-002–HR-009 transaction UX boundaries;
    synchronous vs asynchronous operation behavior.
    OUT OF SCOPE
    React implementation;
    visual component specification detail;
    exact field-by-field forms;
    canonical permission identifiers;
    database/API schema;
    approval-role matrix;
    exact government export fields;
    exact retention/masking policy;
    Finance payroll processing.
    FUTURE SCOPE
    bulk HR operations;
    advanced saved filters;
    configurable workflows where business requirement later justifies them;
    offline mutation support.
    DEFERRED
    HR permission catalog → Phase 3D;
    exact error/loading component presentation → Phase 3C;
    masking/privacy implementation → Phase 3E;
    field-level form specifications until associated API/data contract is stabilized.
29. Change Impact Against Existing Implementation
    Existing implementation Treatment
    GET /v1/hr/employees KEEP + EXTEND
    POST /v1/hr/employees REFACTOR/EXTEND toward canonical HR lifecycle
    Employee → Membership KEEP
    Person creation during provisioning KEEP, subject to future identity-resolution requirements
    employees.jabatan DEPRECATE GRADUALLY
    StoreEmployeeRequest::authorize() = true REFACTOR — P0 security gap
    Tenant-only route middleware EXTEND with canonical permission/org-scope enforcement
    Soft delete existence DO NOT infer generic Delete UX

[RISK] Implementing UI immediately against current Employee POST contract would encode legacy jabatan assumptions into the frontend and increase later migration cost.

30. Traceability
    HR-002 Workforce
    ├── Employee / Employment lifecycle
    └── HR-011 transaction patterns

HR-003 Recruitment
└── Candidate → Hiring → Identity Resolution

HR-004 Leave
└── Request / Approval / Ledger semantics

HR-005 Attendance
└── Reconciliation workflow

HR-006 Compensation
└── HR facts; Finance boundary

HR-007 Performance
└── Assessment / Competency / Development

HR-008 Documents / Discipline / Offboarding
└── Finalization / Case lifecycle

HR-009 Reporting
└── Authorized Export transaction

HR-010 IA
↓
HR-011 Transaction UX
↓
HR-012 State UX
↓
HR Authorization / API / Tests
