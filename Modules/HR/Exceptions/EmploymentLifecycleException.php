<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika sebuah operasi lifecycle Employment (create/activate/
 * cancel/end) melanggar aturan bisnis di HR-002 §7 (Lifecycle & Business
 * Invariants) atau §9 (Concurrency & Transaction Contract).
 *
 * Controller di layer HTTP (Step 5) akan menangkap exception ini dan
 * menerjemahkannya menjadi ApiErrorResponse yang rapi — bukan meloloskan
 * pesan mentah ke client.
 */
final class EmploymentLifecycleException extends RuntimeException {}
