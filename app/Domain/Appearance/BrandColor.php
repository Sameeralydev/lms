<?php

namespace App\Domain\Appearance;

final class BrandColor
{
    public static function supportsWhiteForeground(string $hex): bool
    {
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
            return false;
        }

        $channels = array_map(
            static function (string $channel): float {
                $value = hexdec($channel) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1), 2),
        );
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05) >= 4.5;
    }
}
