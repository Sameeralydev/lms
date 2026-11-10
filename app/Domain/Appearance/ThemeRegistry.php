<?php

namespace App\Domain\Appearance;

final class ThemeRegistry
{
    /** @return array<string, array{name: string, description: string}> */
    public static function all(): array
    {
        return [
            'executive-navy' => ['name' => 'Executive Navy', 'description' => 'Deep navy surfaces with electric blue and cyan accents.'],
            'academic-emerald' => ['name' => 'Academic Emerald', 'description' => 'Calm green accents with warm academic neutrals.'],
            'modern-indigo' => ['name' => 'Modern Indigo', 'description' => 'Clear indigo accents on cool, bright surfaces.'],
            'warm-ivory' => ['name' => 'Warm Ivory', 'description' => 'Soft ivory canvas with restrained terracotta highlights.'],
            'graphite-dark' => ['name' => 'Graphite Dark', 'description' => 'Low-glare graphite surfaces with vivid blue focus cues.'],
            'midnight-violet' => ['name' => 'Midnight Violet', 'description' => 'Dark navy surfaces with violet and lilac accents.'],
        ];
    }
}
