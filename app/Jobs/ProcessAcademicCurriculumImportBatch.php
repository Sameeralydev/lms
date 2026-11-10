<?php

namespace App\Jobs;

use App\Domain\Academic\Curriculum\CurriculumImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessAcademicCurriculumImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public function __construct(public readonly int $batchId) {}

    public function handle(CurriculumImportProcessor $processor): void
    {
        $processor->process($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        app(CurriculumImportProcessor::class)->markFailed($this->batchId, 'processing_failed');
    }
}
