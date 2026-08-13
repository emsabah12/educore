# Module Query Boundary — ModuleManager Retired

- **Version**: 3.0
- **Status**: Historical Compatibility Note
- **Updated**: 2026-08-13
- **Superseded by**: ADR-017 — Module Runtime & Bootstrap Contract

## Current Contract

`ModuleManager`, `ModuleStateRepository`, `module:enable`, dan `module:disable` telah dihapus dari production Module Kernel.

Current module console boundary bersifat read-only:

```text
module:list
module:status {name}
       ↓
ModuleRepository
       ↓
ModuleRegistry
```

`ModuleRepository` adalah metadata query facade.

Discovery, manifest validation, dependency resolution, module loading, dan provider registration tetap dimiliki Kernel services.

Current command responsibilities:

```text
input / option handling
      ↓
ModuleRepository query
      ↓
format metadata output
      ↓
exit code
```

Command tidak boleh:

```text
scan Modules/
parse YAML
construct ModuleDefinition
resolve dependency graph
register provider
mutate module bootstrap state
```

## Why This File Still Exists

Path `docs/architecture/module-manager.md` dipertahankan agar link dokumentasi lama tidak menjadi broken link.

Dokumen ini tidak lagi mendefinisikan current `ModuleManager` API karena object tersebut sudah tidak ada pada production contract.

Historical decisions tetap tersedia melalui:

- ADR-006 — Runtime Module State Repository (**Superseded**)
- ADR-007 — ModuleManager as Kernel Facade (**Superseded**)

Canonical current runtime contract:

- ADR-017 — Module Runtime & Bootstrap Contract
- [`kernel.md`](kernel.md)
- [`module-lifecycle.md`](module-lifecycle.md)
- [`discovery-flow.md`](discovery-flow.md)

---

# Historical Pre-Phase-4A Design

Sebelum Phase 4A hardening, EduCore pernah memiliki:

```text
ModuleManager
ModuleStateRepository
storage/framework/modules.json
module:enable
module:disable
```

Model tersebut mencoba memisahkan metadata module dari persisted desired activation state.

Phase 4A menghapus model tersebut karena application sebenarnya adalah modular monolith dengan deployment/bootstrap composition, bukan runtime plugin engine.

Historical semantics tersebut **tidak boleh digunakan sebagai current implementation contract**.

---

# Current Architectural Boundary

```text
READ-ONLY MODULE METADATA
module:list / module:status
        ↓
ModuleRepository
        ↓
ModuleRegistry

APPLICATION BOOTSTRAP
physical modules
        ↓
manifest validation
        ↓
dependency resolution
        ↓
module loading
        ↓
manifest-driven provider registration
```

Tidak ada mutable Module Kernel activation state di antara kedua flow tersebut.

Tenant feature/entitlement availability dan authorization adalah concern terpisah.

---

# Related Documents

- [`kernel.md`](kernel.md)
- [`discovery-flow.md`](discovery-flow.md)
- [`module-lifecycle.md`](module-lifecycle.md)
- ADR-006 — Runtime Module State Repository (**Superseded**)
- ADR-007 — ModuleManager (**Superseded**)
- ADR-008 — Thin Command Pattern
- ADR-017 — Module Runtime & Bootstrap Contract
