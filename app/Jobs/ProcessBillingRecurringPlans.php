<?php

namespace App\Jobs;

use App\Domain\Billing\BillingRecurringProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessBillingRecurringPlans implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'billing-recurring-plans';
    }

    public function handle(BillingRecurringProcessor $processor): void
    {
        $result = $processor->processDue();
        if ($result['failed'] > 0) {
            logger()->warning('Some recurring billing plans need retry.', $result);
        }
    }
}
