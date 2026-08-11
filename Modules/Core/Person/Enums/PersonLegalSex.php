<?php

declare(strict_types=1);

namespace Modules\Core\Person\Enums;

enum PersonLegalSex: string
{
    case MALE = 'M';
    case FEMALE = 'F';
    case UNSPECIFIED = 'X';
}
