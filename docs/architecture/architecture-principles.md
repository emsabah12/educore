# Architecture Principles

Version : 1.0
Status : Locked
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1

> **Status:** Stable
> **Applies To:** EduCore Platform Kernel

---

# Purpose

Dokumen ini mendefinisikan prinsip-prinsip arsitektur yang menjadi pedoman dalam pengembangan EduCore Platform Kernel.

Prinsip-prinsip ini menjadi dasar dalam setiap keputusan desain, implementasi, dan evolusi sistem.

Apabila terdapat konflik antara implementasi dan prinsip arsitektur, maka implementasi harus disesuaikan agar tetap mematuhi prinsip-prinsip yang tercantum dalam dokumen ini.

---

# 1. Architecture Before Implementation

Keputusan implementasi harus berasal dari keputusan arsitektur, bukan sebaliknya.

Framework, library, maupun teknologi hanya digunakan apabila mendukung kebutuhan arsitektur yang telah ditetapkan.

Arsitektur menjadi acuan utama dalam pengembangan sistem.

---

# 2. Domain Before Framework

Domain model tidak boleh bergantung pada framework.

Framework ditempatkan pada Infrastructure Layer.

Business Rules harus tetap dapat dipahami tanpa mengetahui framework yang digunakan.

---

# 3. Single Responsibility

Setiap komponen hanya memiliki satu tanggung jawab yang jelas.

Contoh:

- ModuleDiscovery hanya menemukan modul.
- ManifestParser hanya membaca manifest.
- ManifestValidator hanya memvalidasi manifest.
- ModuleRegistry hanya menyimpan metadata.
- ModuleManager hanya mengelola kebijakan runtime.

---

# 4. Separation of Metadata and Runtime

Metadata dan Runtime State merupakan dua konsep yang berbeda.

Metadata bersifat statis dan berasal dari manifest.

Runtime State bersifat dinamis dan berasal dari sistem.

Keduanya tidak boleh disimpan pada objek yang sama.

---

# 5. Immutable Metadata

Metadata yang telah berhasil diparsing menjadi immutable object.

Selama proses runtime, metadata tidak boleh dimodifikasi.

Perubahan metadata hanya dapat dilakukan dengan mengubah sumber metadata dan menjalankan proses discovery kembali.

---

# 6. Mutable Runtime State

State runtime dikelola secara terpisah dari metadata.

Contoh state runtime:

- enabled
- disabled

State dapat berubah selama aplikasi berjalan tanpa mengubah metadata.

---

# 7. Single Source of Truth

Setiap jenis informasi hanya memiliki satu sumber kebenaran.

Contoh:

| Data               | Source of Truth       |
| ------------------ | --------------------- |
| Module Metadata    | module.yaml           |
| Runtime State      | ModuleStateRepository |
| Registered Modules | ModuleRegistry        |

Duplikasi sumber data harus dihindari.

---

# 8. Composition Over Inheritance

Gunakan komposisi sebelum mempertimbangkan pewarisan.

Inheritance hanya digunakan apabila terdapat hubungan "is-a" yang nyata dan terdapat perilaku yang memang layak diwariskan.

Pewarisan hanya untuk berbagi data tidak diperbolehkan.

---

# 9. Explicit Dependency Direction

Dependency harus mengalir dalam satu arah.

Lapisan yang lebih tinggi boleh bergantung pada abstraksi dari lapisan yang lebih rendah, tetapi tidak sebaliknya.

Reverse dependency tidak diperbolehkan.

---

# 10. Fail Fast

Kesalahan harus ditemukan sedini mungkin.

Manifest yang tidak valid harus ditolak pada tahap discovery.

Sistem tidak boleh menunda validasi hingga runtime apabila validasi dapat dilakukan lebih awal.

---

# 11. Convention Over Configuration

Gunakan konvensi yang konsisten untuk mengurangi konfigurasi.

Struktur modul, lokasi manifest, namespace, dan penamaan mengikuti konvensi yang telah ditetapkan.

Konfigurasi tambahan hanya dibuat apabila benar-benar diperlukan.

---

# 12. Keep the Kernel Small

Kernel hanya berisi kemampuan yang bersifat fundamental dan reusable.

Kernel tidak boleh berisi logika bisnis aplikasi.

Semakin kecil Kernel, semakin mudah dipelihara dan dikembangkan.

---

# 13. Stable Module Identity

Identitas modul berasal dari field `name` pada `module.yaml`.

Identity harus:

- unik
- stabil
- konsisten
- tidak berubah selama umur modul

UUID tidak digunakan sebagai identitas metadata modul.

---

# 14. UUID for Domain Entities

UUID v7 digunakan untuk Domain Entity yang memiliki lifecycle, seperti:

- User
- Student
- Teacher
- School
- Invoice
- Payment
- AuditLog

Metadata object tidak menggunakan UUID.

---

# 15. Readability Over Cleverness

Kode harus mudah dipahami oleh pengembang lain.

Implementasi sederhana lebih diutamakan daripada solusi yang kompleks namun sulit dipelihara.

Optimisasi hanya dilakukan apabila terdapat kebutuhan yang terukur.

---

# 16. Testability

Setiap komponen harus dapat diuji secara terisolasi.

Business Rules tidak boleh bergantung pada framework sehingga unit testing dapat dilakukan tanpa bootstrap aplikasi.

---

# 17. Backward Compatibility

Perubahan pada Kernel harus mempertimbangkan kompatibilitas terhadap modul yang telah ada.

Breaking changes harus didokumentasikan secara eksplisit melalui Architecture Decision Record (ADR).

---

# 18. Documentation as Architecture

Dokumentasi merupakan bagian dari arsitektur.

Setiap keputusan penting harus terdokumentasi.

Kode dan dokumentasi harus berkembang secara bersamaan.

---

# Summary

EduCore Platform Kernel dibangun berdasarkan prinsip:

- Architecture before implementation
- Domain before framework
- Clean separation of concerns
- Immutable metadata
- Mutable runtime state
- Stable module identity
- Single source of truth
- Composition over inheritance
- Fail fast validation
- Convention over configuration
- Small and reusable kernel
- Testable architecture
- Well-documented decisions

Seluruh pengembangan Kernel harus mengikuti prinsip-prinsip tersebut untuk menjaga konsistensi, maintainability, dan skalabilitas platform dalam jangka panjang.
