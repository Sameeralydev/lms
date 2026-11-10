<?php

namespace Tests\Unit;

use App\Domain\Appearance\BrandColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrandColorTest extends TestCase
{
    #[DataProvider('contrastCases')]
    public function test_primary_color_has_sufficient_contrast_with_white_foreground(string $color, bool $expected): void
    {
        $this->assertSame($expected, BrandColor::supportsWhiteForeground($color));
    }

    /** @return array<string, array{string, bool}> */
    public static function contrastCases(): array
    {
        return [
            'default electric blue' => ['#155EEF', true],
            'deep custom navy' => ['#244A72', true],
            'darkest color' => ['#000000', true],
            'light blue cannot use white foreground' => ['#B8D5F2', false],
            'invalid color' => ['not-a-color', false],
        ];
    }
}
