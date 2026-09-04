<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika sebuah operasi lifecycle Recruitment (Vacancy,
 * Application, Onboarding, dst) melanggar state machine atau aturan
 * bisnis di HR-003.
 */
final class RecruitmentLifecycleException extends RuntimeException {}
