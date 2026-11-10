<?php

namespace App\Jobs;

use App\Domain\Sis\SisStudentImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessSisStudentImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $batchId) {}

    public function handle(SisStudentImportProcessor $processor): void
    {
        $processor->process($this->batchId);
    }

    public function failed(Throwable $exception): void
    {
        app(SisStudentImportProcessor::class)->markFailed($this->batchId);
    }
}
