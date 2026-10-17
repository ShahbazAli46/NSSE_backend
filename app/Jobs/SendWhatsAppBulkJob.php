<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Universal Queue Job for sending bulk WhatsApp messages across the system
 * (e.g. fee notices, exam results, announcements, staff messages)
 * with central anti-ban rate limiting and jitter sleep.
 */
class SendWhatsAppBulkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $items;
    public string $batchTag;
    public ?int $userId;
    public array $options;

    /**
     * Timeout for long-running batch job (e.g. 2 hours).
     */
    public int $timeout = 7200;

    /**
     * Create a new job instance.
     *
     * @param array $items Array of ['phone' => string, 'message' => string, 'id' => mixed, ...]
     * @param string $batchTag Identifier for logging/categorization (e.g. 'fee_reminder', 'announcement')
     * @param int|null $userId
     * @param array $options Optional anti-ban config overrides
     */
    public function __construct(array $items, string $batchTag = 'general', ?int $userId = null, array $options = [])
    {
        $this->items    = $items;
        $this->batchTag = $batchTag;
        $this->userId   = $userId;
        $this->options  = $options;
    }

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $service): void
    {
        $count = count($this->items);
        Log::info("SendWhatsAppBulkJob [{$this->batchTag}] started for {$count} recipients (User ID: {$this->userId}).");

        try {
            $summary = $service->dispatchBatchWithAntiBan($this->items, null, $this->options);
            Log::info("SendWhatsAppBulkJob [{$this->batchTag}] completed successfully.", $summary);
        } catch (Exception $e) {
            Log::error("SendWhatsAppBulkJob [{$this->batchTag}] failed: " . $e->getMessage());
            throw $e;
        }
    }
}
