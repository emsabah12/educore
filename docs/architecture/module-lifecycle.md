# Module Lifecycle

- **Version**: 2.0
- **Status**: Current / Revalidated
- **Updated**: 2026-08-12

## Overview

Current lifecycle dibagi menjadi tiga concern:

```text
1. Metadata Bootstrap
2. Activation-State Persistence
3. Application Bootstrap Activation
```

Ini **bukan hot plugin lifecycle**.

---

# 1. High-Level Flow

```text
Filesystem: Modules/
      │
      ▼
Metadata Bootstrap
      │
      ├── discover manifests
      ├── parse + validate
      ├── build ModuleDefinition
      ├── validate dependency graph
      ├── discover events/listeners
      └── populate ModuleRegistry
      │
      ▼
Activation State
modules.json
      │
      ▼
CoreServiceProvider startup
      │
      ▼
register enabled module providers
      │
      ▼
Laravel Application Runtime
```

---

# 2. Phase A — Module Exists on Filesystem

A direct child directory under:

```text
Modules/
```

menjadi discovery candidate hanya jika memiliki:

```text
module.yaml
```

Directory tanpa manifest diabaikan oleh current `ModuleDiscovery`.

---

# 3. Phase B — Metadata Bootstrap

Metadata bootstrap biasanya dipicu melalui lazy `ModuleRepository` resolution ketika singleton registry masih kosong.

```text
ModuleRepository
      ↓
registry empty
      ↓
ModuleBootstrapService
```

Pipeline:

```text
ModuleDiscovery
      ↓
ModuleManifestLoader
      ↓
ModuleManifestParser
      ↓
ModuleDefinitionFactory
      ↓
ModuleManifestValidator
      ↓
ModuleDefinition
```

Semua valid definitions kemudian diperiksa oleh `DependencyResolver`.

---

# 4. Phase C — Dependency Validation

```text
ModuleDefinition[]
       ↓
DependencyResolver
       ↓
resolved topological order
```

Fail-fast:

```text
missing dependency
circular dependency
```

Current resolver memberikan dependency order, tetapi runtime provider registration belum dikunci sebagai selalu mengikuti order tersebut.

Jadi bedakan:

```text
dependency graph validity ✅
provider boot ordering     ⚠ current hardening item
```

---

# 5. Phase D — Event Discovery

Current bootstrap menjalankan listener discovery terhadap definitions hasil resolution.

```text
Modules/<Name>/Listeners
      ↓
EventDiscoveryService
      ↓
ModuleEventRegistry
```

Laravel Event bindings dipasang pada `CoreServiceProvider::boot()`.

Current source belum menjadikan `ModuleStateRepository` sebagai explicit filter pada event discovery phase. Disabled module therefore tidak boleh diasumsikan sebagai total event-code isolation.

---

# 6. Phase E — Metadata Registration

`ModuleLoader` memasukkan `ModuleDefinition` ke:

```text
ModuleRegistry
```

Setelah registry tersedia:

```text
ModuleRepository
```

menjadi read facade untuk metadata application-facing operations.

Metadata registration dan enabled-state adalah dua concern berbeda.

---

# 7. Phase F — Activation State

Current mutable state:

```text
enabled = true
enabled = false
```

Persistence:

```text
storage/framework/modules.json
```

Default ketika key tidak ada:

```text
disabled / false
```

State tidak disimpan dalam `module.yaml`.

---

# 8. Phase G — Provider Activation at Bootstrap

Pada application bootstrap, `CoreServiceProvider` membaca module metadata dan activation state.

Untuk module yang dianggap enabled, current source memiliki dynamic provider-registration path berdasarkan naming convention.

Critical semantic:

```text
enabled state
→ observed during bootstrap
```

bukan:

```text
enabled state
→ hot provider registration/unregistration at arbitrary runtime
```

---

# 9. Enable Lifecycle

```text
module:enable Academic
       ↓
ModuleManager
       ↓
ModuleStateRepository
       ↓
enabled=true persisted
       ↓
NEXT application bootstrap observes state
```

Command sukses berarti desired activation state tersimpan, bukan jaminan provider baru di-hot-load ke process yang sudah berjalan.

---

# 10. Disable Lifecycle

```text
module:disable Academic
       ↓
ModuleManager
       ↓
ModuleStateRepository
       ↓
enabled=false persisted
       ↓
NEXT application bootstrap observes state
```

Current process tidak melakukan provider/listener unload.

---

# 11. Transitional Provider-Wiring Caveat

Current repository masih memiliki lebih dari satu provider wiring mechanism:

```text
bootstrap/providers.php
+
CoreServiceProvider dynamic active-module registration
```

Selain itu, dynamic path menggunakan provider naming convention sedangkan manifest `providers` tetap divalidasi sebagai metadata.

Karena itu current activation lifecycle belum layak dianggap final public contract untuk module enable/disable isolation.

Required future hardening questions:

```text
What is the single provider activation source?
Should module.yaml.providers drive activation?
Should static downstream provider registration be removed?
Must event discovery respect enabled state?
Must provider registration follow dependency topological order?
```

Pertanyaan ini **belum dijawab oleh DOC STEP 5**; hanya dicatat agar docs jujur terhadap source.

---

# 12. State Transition Model

Current persisted state model tetap sederhana:

```text
       enable
false ───────► true
  ▲             │
  │             │
  └─────────────┘
      disable
```

Tidak ada current states seperti:

```text
installing
starting
stopping
failed
uninstalled
```

Jangan menambahkan transition model baru tanpa concrete requirement.

---

# 13. Metadata Immutability

Selama application process:

```text
ModuleDefinition
→ immutable metadata object
```

Mutable activation state berada terpisah di `ModuleStateRepository`.

Ini tetap merupakan architecture principle yang valid.

---

# 14. Relationship to Business Module Lifecycle

Module lifecycle tidak menentukan lifecycle business records.

Contoh:

```text
Academic module enabled/disabled
≠ Student status

HR module enabled/disabled
≠ Employee status
```

Business lifecycle tetap dimiliki domain masing-masing.

---

# Related Documents

- [`kernel.md`](kernel.md)
- [`discovery-flow.md`](discovery-flow.md)
- [`module-manager.md`](module-manager.md)
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry
- ADR-006 — Runtime Module State Repository
- ADR-007 — ModuleManager
