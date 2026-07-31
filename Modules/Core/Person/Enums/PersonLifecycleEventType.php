<?php

declare(strict_types=1);

namespace Modules\Core\Person\Enums;

enum PersonLifecycleEventType: string
{
    case CREATED = 'CREATED';

    case ACTIVATED = 'ACTIVATED';

    case DEACTIVATED = 'DEACTIVATED';

    case ARCHIVED = 'ARCHIVED';

    case DECEASED = 'DECEASED';
}
