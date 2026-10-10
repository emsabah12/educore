<?php

namespace Modules\Core\Application\Settings;

/**
 * Nilai aturan yang berlaku di sebuah node, beserta asal-usulnya.
 *
 *   source = enforced → dikunci oleh tingkat di atas (atau node itu sendiri)
 *   source = nearest  → nilai dari node terdekat
 *   source = default  → belum diatur di mana pun; memakai default modul
 */
final readonly class ResolvedSetting
{
    public function __construct(
        public string $key,
        public mixed $value,
        public string $source,
        public ?string $settingId,
    ) {}

    public function isDefault(): bool
    {
        return $this->source === SettingPrecedence::SOURCE_DEFAULT;
    }

    public function isEnforced(): bool
    {
        return $this->source === SettingPrecedence::SOURCE_ENFORCED;
    }
}
