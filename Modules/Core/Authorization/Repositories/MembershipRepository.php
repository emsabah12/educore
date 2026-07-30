<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Repositories;

use Modules\Core\Authorization\Models\Membership;
use Modules\Core\Authorization\Repositories\Contracts\MembershipRepositoryInterface;
use Modules\Core\Shared\Repositories\BaseRepository;

/**
 * @extends BaseRepository<Membership>
 */
final class MembershipRepository extends BaseRepository implements MembershipRepositoryInterface
{
    public function __construct(Membership $model)
    {
        parent::__construct($model);
    }
}
