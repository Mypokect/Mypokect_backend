<?php

namespace App\Console\Commands;

use App\Jobs\SendReminderNotificationJob;
use App\Models\Reminder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessReminderNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:process-notifications
                          {--dry-run : Run without dispatching jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending reminders and dispatch notification jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $now = Carbon::now('UTC');

        $this->info("Processing reminders at {$now->toDateTimeString()} UTC");

        // Find reminders that need notifications
        $reminders = Reminder::where('status', 'pending')
            ->whereNull('deleted_at')
            ->get();

        $processed = 0;
        $skipped = 0;

        foreach ($reminders as $reminder) {
            $notificationTime = $reminder->getNotificationDateTime();
            $dueDate = $reminder->due_date;

            // Check if it's time to send notification
            $shouldNotify = false;
            $notificationType = 'before';

            // Check if we should send "before" notification
            if ($now->gte($notificationTime) && 
                $now->lt($dueDate) && 
                (!$reminder->last_notified_at || $reminder->last_notified_at->lt($notificationTime))) {
                $shouldNotify = true;
                $notificationType = 'before';
            }

            // Check if we should send "today" notification
            if ($now->gte($dueDate) && 
                $now->lt($dueDate->copy()->addDay()) &&
                (!$reminder->last_notified_at || $reminder->last_notified_at->lt($dueDate))) {
                $shouldNotify = true;
                $notificationType = 'today';
            }

            if ($shouldNotify) {
                if ($dryRun) {
                    $this->line("Would send {$notificationType} notification for reminder #{$reminder->id}: {$reminder->title}");
                } else {
                    SendReminderNotificationJob::dispatch($reminder, $notificationType);
                    $this->info("Dispatched {$notificationType} notification for reminder #{$reminder->id}");
                }
                $processed++;
            } else {
                $skipped++;
            }
        }

        $this->info("Processed: {$processed}, Skipped: {$skipped}");
        
        Log::info("Reminder notifications processed", [
            'processed' => $processed,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
