<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivateStorageSurfaceTest extends TestCase
{
    public function test_private_storage_has_no_framework_serving_or_upload_routes(): void
    {
        $this->get('/storage/private/phase-01-canary.txt')->assertNotFound();

        $this->put('/storage/private/phase-01-canary.txt')->assertNotFound();
    }
}
