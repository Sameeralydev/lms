<?php

namespace App\Domain\Admissions;

use App\Models\AdmissionOutboxEvent;

interface AdmissionOutboxDispatcher
{
    public function dispatch(AdmissionOutboxEvent $outbox): void;
}
