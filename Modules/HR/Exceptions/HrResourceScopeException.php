<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika actor punya permission di workspace organisasi saat
 * ini (sudah lolos `organizational.permission` middleware), TAPI
 * resource target (mis. Employee tertentu) terbukti tidak berada di
 * workspace tersebut (HR-013 §30 — Resource-Scope Authorization
 * Boundary, HR-013-BR-001: "Permission ≠ Resource Ownership").
 *
 * Catatan: HR-013 §53 poin 5 secara eksplisit mencatat "403 vs 404
 * policy for out-of-scope resource probing" sebagai keputusan yang
 * BELUM ditetapkan di dokumen. Controller (Step 3) akan memetakan
 * exception ini ke 403 sebagai keputusan sementara yang konsisten
 * dengan kontrak otorisasi lain di proyek ini — bukan 404 — supaya
 * mudah direvisi terpusat kalau keputusan resminya berbeda nanti.
 */
final class HrResourceScopeException extends RuntimeException {}
