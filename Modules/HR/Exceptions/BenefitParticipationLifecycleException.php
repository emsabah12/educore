<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika sebuah operasi lifecycle Employee Benefit
 * Participation (create/enroll/dst.) melanggar aturan bisnis di
 * HR-006 §7.6.
 *
 * Controller di layer HTTP (step berikutnya) akan menangkap exception
 * ini dan menerjemahkannya menjadi ApiErrorResponse yang rapi — bukan
 * meloloskan pesan mentah ke client.
 */
final class BenefitParticipationLifecycleException extends RuntimeException {}
