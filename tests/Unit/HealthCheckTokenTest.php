<?php

namespace Tests\Unit;

use App\Security\HealthCheckToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HealthCheckTokenTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function invalidTokens(): array
    {
        return [
            'missing configured token' => [null, 'some-provided-token-that-is-long-enough'],
            'short configured token' => ['too-short', 'too-short'],
            'missing provided token' => ['a-configured-token-that-is-long-enough', null],
            'incorrect provided token' => ['a-configured-token-that-is-long-enough', 'another-token-that-is-long-enough'],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_it_rejects_missing_short_or_mismatched_tokens(?string $expected, ?string $provided): void
    {
        $this->assertFalse((new HealthCheckToken)->matches($expected, $provided));
    }

    public function test_it_accepts_a_matching_token_of_at_least_32_characters(): void
    {
        $token = 'a-secure-random-token-with-at-least-32-characters';

        $this->assertTrue((new HealthCheckToken)->matches($token, $token));
    }
}
