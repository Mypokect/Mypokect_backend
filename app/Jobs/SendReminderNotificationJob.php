<?php

namespace App\Jobs;

use App\Models\Reminder;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendReminderNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Reminder $reminder,
        public string $notificationType = 'before'
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        Log::info("Sending {$this->notificationType} notification for reminder {$this->reminder->id}");

        try {
            $result = $notificationService->sendReminderNotification(
                $this->reminder,
                $this->notificationType
            );

            // Update last notified timestamp
            $this->reminder->update([
                'last_notified_at' => now(),
            ]);

            Log::info("Notification sent for reminder {$this->reminder->id}", $result);
        } catch (\Exception $e) {
            Log::error("Failed to send notification for reminder {$this->reminder->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed permanently for reminder {$this->reminder->id}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
