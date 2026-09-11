<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika sebuah operasi lifecycle Compensation Assignment
 * (create draft/approve/end/correct) melanggar aturan bisnis di
 * HR-006 §7.3 (Compensation Assignment) atau §7.4 (Effective-range
 * overlap rule).
 *
 * Controller di layer HTTP (step berikutnya) akan menangkap exception
 * ini dan menerjemahkannya menjadi ApiErrorResponse yang rapi — bukan
 * meloloskan pesan mentah ke client.
 */
final class CompensationLifecycleException extends RuntimeException {}
