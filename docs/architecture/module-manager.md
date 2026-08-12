# ModuleManager & Module Query Boundary

- **Version**: 2.0
- **Status**: Current / Revalidated
- **Updated**: 2026-08-12

## Overview

Current Module Kernel menggunakan lightweight **Command Query Separation (CQS)**.

`ModuleManager` bukan lagi universal facade untuk seluruh read dan write operation.

```text
QUERY
ModuleRepository + ModuleStateRepository

COMMAND / MUTATION
ModuleManager
    ↓
ModuleRepository + ModuleStateRepository
```

---

# 1. ModuleManager Responsibility

Current operations:

```text
isEnabled(name)
enable(name)
disable(name)
getEnabledModules()
```

Manager memastikan target module dikenal sebelum lifecycle-state mutation/check dilakukan.

Unknown module:

```text
ModuleNotFoundException
```

---

# 2. Dependencies

```text
ModuleManager
   │
   ├── ModuleRepository
   │      └── ModuleRegistry
   │
   └── ModuleStateRepository
          └── modules.json
```

`ModuleManager` tidak melakukan:

```text
filesystem discovery
YAML loading
YAML parsing
manifest validation
ModuleDefinition creation
dependency graph construction
event discovery
provider registration
```

---

# 3. Query Boundary

Read commands saat ini menggunakan query dependencies secara langsung.

```text
module:list
   ↓
ModuleRepository
+
ModuleStateRepository
```

```text
module:status
   ↓
ModuleRepository
+
ModuleStateRepository
```

Ini intentional lightweight CQS dan bukan pelanggaran thin-command principle selama command tidak mengambil alih discovery/manifest/business mutation logic.

---

# 4. Mutation Boundary

```text
module:enable Foo
      ↓
ModuleEnableCommand
      ↓
ModuleManager.enable(Foo)
      ↓
ensure module exists
      ↓
ModuleStateRepository.enable(Foo)
```

```text
module:disable Foo
      ↓
ModuleDisableCommand
      ↓
ModuleManager.disable(Foo)
      ↓
ensure module exists
      ↓
ModuleStateRepository.disable(Foo)
```

Current mutation is idempotent at persistence level because state is overwritten to the requested boolean.

Tidak ada current `ModuleAlreadyEnabledException` atau `ModuleAlreadyDisabledException` contract.

---

# 5. Activation Semantics

Critical rule:

> `enable()` / `disable()` mengubah **persisted desired activation state**, bukan melakukan hot load/unload terhadap process yang sedang berjalan.

```text
command writes state
      ↓
next application bootstrap
      ↓
CoreServiceProvider evaluates state
      ↓
provider activation path
```

Karena itu jangan menulis UI/API yang menjanjikan immediate unload tanpa Module Kernel hardening tambahan.

---

# 6. Enabled Module Query

`getEnabledModules()`:

1. membaca seluruh ModuleDefinition melalui `ModuleRepository`;
2. mengecek state setiap module;
3. mengembalikan definitions dengan state enabled.

Return value tetap `ModuleDefinition`, bukan raw manifest array.

---

# 7. Thin Command Rules

Thin command berarti:

```text
input normalization
call application/kernel dependency
present output
return exit code
```

Command tidak boleh:

```text
scan Modules/
parse YAML
mutate modules.json manually
construct ModuleDefinition
resolve dependency graph
register provider
```

Read-only formatting dan basic existence handling tetap boleh berada di adapter CLI.

---

# 8. Current Commands

| Command | Type | Current dependency |
| --- | --- | --- |
| `module:list` | Query | `ModuleRepository` + `ModuleStateRepository` |
| `module:status` | Query | `ModuleRepository` + `ModuleStateRepository` |
| `module:enable` | Mutation | `ModuleManager` |
| `module:disable` | Mutation | `ModuleManager` |
| `kernel:test-loader` | Diagnostic query | `ModuleRepository` |

Ini menggantikan dokumentasi lama yang menyatakan semua command wajib melalui `ModuleManager`.

---

# 9. Current Non-Responsibilities

`ModuleManager` tidak menjadi tempat untuk:

```text
module install/publish
schema migration orchestration
organization/domain authorization
feature flags
hot process reconfiguration
remote plugin marketplace
```

Capability baru harus mempunyai concrete requirement sebelum ditambahkan.

---

# Related Documents

- [`kernel.md`](kernel.md)
- [`discovery-flow.md`](discovery-flow.md)
- [`module-lifecycle.md`](module-lifecycle.md)
- ADR-006 — Runtime Module State Repository
- ADR-007 — ModuleManager
- ADR-008 — Thin Command Pattern
