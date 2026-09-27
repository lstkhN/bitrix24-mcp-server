<?php

/**
 * Минимальные стобы Битрикс для unit-тестов вне CMS-окружения.
 *
 * Служебные классы с той же сигнатурой, что используется в lib/.
 * Не являются заменой реальной Битрикс-логики.
 */

namespace Bitrix\Main\Config {
    if (!class_exists(Option::class)) {
        class Option
        {
            /** @var array<string, mixed> */
            public static array $values = [];

            public static function get(string $module, string $name, $default = false)
            {
                return self::$values[$module . '.' . $name] ?? $default;
            }

            public static function set(string $module, string $name, $value): void
            {
                self::$values[$module . '.' . $name] = $value;
            }

            public static function reset(): void
            {
                self::$values = [];
            }
        }
    }
}

namespace Bitrix\Main {
    if (!class_exists(Application::class)) {
        class Application
        {
            public static function getConnection()
            {
                throw new \RuntimeException('DB connection is not available in unit tests');
            }
        }
    }
}
