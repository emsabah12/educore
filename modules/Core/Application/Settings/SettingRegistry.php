<?php

namespace Modules\Core\Application\Settings;

use Modules\Core\Domain\Settings\Exceptions\SettingException;

/**
 * Daftar definisi aturan berjenjang yang didaftarkan modul (PRD-000 §8.2).
 */
final class SettingRegistry
{
    /** Pola key: minimal dua bagian, huruf kecil, mis. `hr.attendance.check_in_time`. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function define(SettingDefinition $definition): self
    {
        if (preg_match(self::KEY_PATTERN, $definition->key) !== 1 || strlen($definition->key) > 100) {
            throw SettingException::invalidKey($definition->key);
        }

        $this->definitions[$definition->key] = $definition;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @throws SettingException
     */
    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key] ?? throw SettingException::unknownKey($key);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
