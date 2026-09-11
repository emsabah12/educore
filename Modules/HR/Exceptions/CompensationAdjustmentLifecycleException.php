<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika sebuah operasi lifecycle Compensation Adjustment
 * (create/submit/approve/reject/cancel) melanggar aturan bisnis di
 * HR-006 §7.8, termasuk pelanggaran maker-checker
 * (`requested_by_membership_id === approved_by_membership_id`).
 *
 * Controller di layer HTTP akan menangkap exception ini dan
 * menerjemahkannya menjadi ApiErrorResponse yang rapi — bukan
 * meloloskan pesan mentah ke client.
 */
final class CompensationAdjustmentLifecycleException extends RuntimeException {}
