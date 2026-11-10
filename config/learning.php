<?php

return [
    'activity_response_retention_days' => (int) env('LEARNING_ACTIVITY_RESPONSE_RETENTION_DAYS', 1095),
    'media_archive_after_days' => (int) env('LEARNING_MEDIA_ARCHIVE_AFTER_DAYS', 365),
    'media_purge_after_days' => (int) env('LEARNING_MEDIA_PURGE_AFTER_DAYS', 2555),
];
