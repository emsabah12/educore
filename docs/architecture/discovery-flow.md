# Module Discovery & Bootstrap Flow

- **Version**: 2.0
- **Status**: Current / Revalidated
- **Updated**: 2026-08-12

## Overview

Module discovery menemukan `module.yaml` yang tersedia dan mengubahnya menjadi `ModuleDefinition` yang tervalidasi.

Current implementation tidak menggunakan `DiscoveredModule` Value Object. `ModuleDiscovery` mengembalikan **sorted manifest path strings** langsung ke bootstrap pipeline.

Automatic discovery tetap menjadi current architecture decision; hanya kontrak intermediate lama `DiscoveredModule` yang sudah tidak berlaku.

---

# 1. Trigger

Metadata bootstrap terjadi saat `ModuleRepository` di-resolve dan singleton `ModuleRegistry` masih kosong.

```text
resolve ModuleRepository
        │
        ▼
ModuleRegistry.count() === 0 ?
        │ yes
        ▼
ModuleBootstrapService.bootstrap(base_path('Modules'))
```

Pada normal application bootstrap, `CoreServiceProvider` membutuhkan module metadata untuk activation checks sehingga flow ini biasanya dijalankan pada startup.

---

# 2. Discovery Pipeline

```text
Modules/
   │
   ▼
ModuleDiscovery
   │
   ▼
sorted module.yaml paths
   │
   ▼
ModuleManifestLoader
   │
   ▼
raw YAML string
   │
   ▼
ModuleManifestParser
   │
   ▼
parsed array
   │
   ▼
ModuleDefinitionFactory
   │
   └──── ModuleManifestValidator
   │
   ▼
ModuleDefinition[]
```

Setiap stage memiliki satu tanggung jawab.

---

# 3. ModuleDiscovery

`ModuleDiscovery`:

1. menerima root module path;
2. membaca direct child directories;
3. mengabaikan files pada module root;
4. mengabaikan directory tanpa `module.yaml`;
5. menghasilkan manifest paths;
6. mengurutkan manifest paths secara deterministic.

Current output:

```php
list<string> // module.yaml paths
```

Bukan:

```text
DiscoveredModule object
```

Historical ADR-004 pernah menetapkan `DiscoveredModule`, tetapi contract tersebut tidak terdapat pada current source dan tidak lagi menjadi implementation requirement.

---

# 4. Manifest Loading

`ModuleManifestLoader` menerima satu manifest path dan hanya bertanggung jawab membaca raw contents.

```text
manifest path
     ↓
ModuleManifestLoader
     ↓
raw YAML string
```

Missing/unreadable file menghasilkan failure.

Loader tidak melakukan parsing atau validation.

---

# 5. Manifest Parsing

`ModuleManifestParser` menggunakan Symfony YAML.

```text
raw YAML
   ↓
ModuleManifestParser
   ↓
PHP array
```

Invalid YAML atau YAML yang bukan object/array menghasilkan exception.

---

# 6. Manifest Validation & Definition Factory

Current responsibility:

```text
parsed array
   ↓
ModuleDefinitionFactory
   ↓
ModuleManifestValidator
   ↓
ModuleDefinition::fromArray()
```

Validator memeriksa required fields dan current field types.

Current required fields:

```text
schema
name
display_name
version
description
providers
dependencies
metadata
extra
```

Declared provider class strings juga divalidasi agar dapat di-autoload.

---

# 7. Dependency Validation

Setelah seluruh definitions dibuat:

```text
ModuleDefinition[]
      ↓
DependencyResolver
      ↓
topological order
```

Resolver fail-fast untuk:

```text
missing dependency
circular dependency
```

Dependency identity menggunakan exact `ModuleDefinition.name`.

## Current boundary

Resolved order saat ini digunakan oleh event discovery, tetapi belum menjadi guaranteed order untuk seluruh provider-registration path.

Jadi current contract adalah:

```text
dependency correctness validation ✅
provider boot-order guarantee       ⚠ not frozen
```

---

# 8. Event Discovery

Untuk definitions hasil dependency resolution:

```text
ModuleBootstrapService
      ↓
Modules/<Name>/Listeners
      ↓
EventDiscoveryService
      ↓
ModuleEventRegistry
```

`CoreServiceProvider::boot()` kemudian memasang listener ke Laravel Event dispatcher.

Current event-discovery flow belum secara eksplisit difilter oleh `ModuleStateRepository`, sehingga disabled-state semantics untuk listener tidak boleh diasumsikan lebih kuat daripada current source.

---

# 9. Registry Loading

`ModuleLoader` menerima definitions dan mendaftarkannya ke singleton `ModuleRegistry`.

```text
ModuleDefinition[]
      ↓
ModuleLoader
      ↓
ModuleRegistry
```

Duplicate module name gagal melalui `ModuleAlreadyRegisteredException`.

Setelah registry terisi, application-facing reads menggunakan `ModuleRepository`.

---

# 10. Discovery vs Runtime State

Discovery metadata dan runtime activation state adalah concern terpisah.

```text
DISCOVERY
module exists?
manifest valid?
dependencies valid?
metadata registered?

ACTIVATION STATE
enabled?
disabled?
```

Module dapat ditemukan dan terdaftar metadata-nya walaupun activation state disabled.

---

# 11. Error Handling

Discovery/bootstrap mengikuti fail-fast untuk configuration errors.

Examples:

```text
malformed YAML
missing manifest field
wrong manifest field type
unknown provider class in declared providers
missing dependency
circular dependency
duplicate module registration
```

Jangan melakukan silent fallback yang menghasilkan partial/unknown metadata state.

---

# 12. Extension Rules

Future discovery extension boleh menambahkan capability seperti caching atau external source hanya jika demonstrated requirement tersedia.

Jangan menambah generic plugin marketplace, recursive tree, atau remote registry secara spekulatif.

Current simple direct-directory discovery adalah canonical KISS baseline.

---

# Related Documents

- [`kernel.md`](kernel.md)
- [`module-lifecycle.md`](module-lifecycle.md)
- [`module-manager.md`](module-manager.md)
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry
- ADR-010 — Module Identity
