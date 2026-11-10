<?php

namespace App\Security;

class HealthCheckToken
{
    public function matches(?string $expected, ?string $provided): bool
    {
        return is_string($expected)
            && strlen($expected) >= 32
            && is_string($provided)
            && hash_equals($expected, $provided);
    }
}
