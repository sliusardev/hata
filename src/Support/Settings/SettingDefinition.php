<?php

namespace Hata\Support\Settings;

/**
 * One site setting key: the config path it overrides (null for a value the app only stores and reads itself),
 * the type it reaches config as, and whether it is stored encrypted.
 */
final readonly class SettingDefinition
{
    /**
     * @param  'int'|'bool'|'array'|null  $cast
     */
    public function __construct(
        public string $key,
        public ?string $config = null,
        public ?string $cast = null,
        public bool $encrypted = false,
    ) {}
}
