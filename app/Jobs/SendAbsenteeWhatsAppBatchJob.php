<?php

namespace App\Jobs;

use App\Services\AbsenteeNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class SendAbsenteeWhatsAppBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $attendanceIds;
    public ?string $customTemplate;
    public ?int $userId;

    /**
     * Timeout for long-running batch job (e.g. 1 hour).
     */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(array $attendanceIds, ?string $customTemplate = null, ?int $userId = null)
    {
        $this->attendanceIds = $attendanceIds;
        $this->customTemplate = $customTemplate;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(AbsenteeNotificationService $service): void
    {
        Log::info('SendAbsenteeWhatsAppBatchJob started for ' . count($this->attendanceIds) . ' attendance records.');

        try {
            $summary = $service->processBatch($this->attendanceIds, $this->customTemplate, $this->userId);
            Log::info('SendAbsenteeWhatsAppBatchJob finished successfully.', $summary);
        } catch (Exception $e) {
            Log::error('SendAbsenteeWhatsAppBatchJob failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
