<?php

namespace App\Domain\Integrations;

use Illuminate\Http\JsonResponse;

final class CaseStatus
{
    public static function failure(int $httpStatus, string $minor, string $description): JsonResponse
    {
        return response()->json([
            'imsx_codeMajor' => 'failure',
            'imsx_severity' => 'error',
            'imsx_description' => $description,
            'imsx_codeMinor' => ['imsx_codeMinorField' => [[
                'imsx_codeMinorFieldName' => 'CASE',
                'imsx_codeMinorFieldValue' => $minor,
            ]]],
        ], $httpStatus)->header('Cache-Control', 'private, no-store');
    }
}
