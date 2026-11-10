<?php

namespace App\Domain\Learning;

use Illuminate\Support\Facades\DB;

final class CourseDeliveryActivityRetentionProcessor
{
    /** Remove expired encrypted activity responses in bounded, repeatable batches. */
    public function run(int $batchSize = 500): int
    {
        $batchSize = max(1, min($batchSize, 5000));
        $deleted = 0;

        do {
            $ids = DB::table('course_delivery_activity_responses')
                ->whereNotNull('retention_until')
                ->where('retention_until', '<=', now())
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('course_delivery_activity_responses')
                ->whereIn('id', $ids)
                ->whereNotNull('retention_until')
                ->where('retention_until', '<=', now())
                ->delete();
        } while ($ids->count() === $batchSize);

        return $deleted;
    }
}
