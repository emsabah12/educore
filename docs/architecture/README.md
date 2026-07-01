# EduCore Architecture

Selamat datang di dokumentasi arsitektur **EduCore Platform Kernel**.

Dokumentasi ini menjelaskan bagaimana platform dirancang, alasan di balik setiap keputusan arsitektur, serta bagaimana komponen utama saling berinteraksi.

---

# Tujuan

Dokumentasi ini bertujuan untuk:

- Menjelaskan arsitektur platform.
- Mendokumentasikan keputusan desain (Architecture Decision Record / ADR).
- Menjadi referensi utama bagi developer.
- Menjaga konsistensi implementasi antar sprint.

---

# Struktur Dokumentasi

## Architecture Decision Records

Berisi seluruh keputusan arsitektur yang telah diterima.

Lokasi:

```
docs/architecture/adr/
```

---

## Kernel

Menjelaskan filosofi dan tanggung jawab EduCore Platform Kernel.

```
kernel.md
```

---

## Folder Structure

Menjelaskan struktur folder platform beserta alasannya.

```
folder-structure.md
```

---

## Module Discovery

Menjelaskan proses discovery modul.

```
discovery-flow.md
```

---

## Module Manager

Menjelaskan peran ModuleManager sebagai Kernel Facade.

```
module-manager.md
```

---

## Module Lifecycle

Menjelaskan lifecycle modul dari discovery hingga runtime.

```
module-lifecycle.md
```

---

# Dokumentasi Lain

- PRD → `docs/prd`
- Sprint → `docs/sprint`

---

# Prinsip

EduCore mengikuti prinsip:

- Modular Monolith
- Clean Architecture
- Separation of Concerns
- Thin Command
- Repository Pattern
- Service Layer
- Architecture Decision Records (ADR)

Seluruh perubahan arsitektur harus terdokumentasi melalui ADR.
