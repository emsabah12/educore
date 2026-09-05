<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika sebuah operasi lifecycle Onboarding (Case, Task)
 * melanggar state machine atau aturan bisnis di HR-003 §8.3.
 */
final class OnboardingLifecycleException extends RuntimeException {}
