<?php
namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use App\Models\ScheduledTransaction;
use App\Models\User;
use Carbon\Carbon;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

class SendPaymentReminders extends Command
{
    protected $signature = 'app:send-payment-reminders';
    protected $description = 'Scans for upcoming scheduled transactions and sends reminders to users.';

    public function handle()
    {
        $this->info('Starting reminder check...');

        User::whereNotNull('fcm_token')->chunk(100, function ($users) {
            foreach ($users as $user) {
                $transactions = $user->scheduledTransactions()
                    ->whereNotNull('reminder_days_before')
                    ->get();
                
                foreach ($transactions as $transaction) {
                    $nextOccurrenceDate = $this->calculateNextOccurrenceDate($transaction);
                    
                    if ($nextOccurrenceDate) {
                        // Comprobar si esta ocurrencia específica ya fue pagada
                        $isPaid = $transaction->occurrences()
                            ->where('occurrence_date', $nextOccurrenceDate->toDateString())
                            ->where('is_paid', true)
                            ->exists();

                        if (!$isPaid) {
                            $daysBefore = $transaction->reminder_days_before;
                            $notificationSendDate = $nextOccurrenceDate->copy()->subDays($daysBefore);

                            if ($notificationSendDate->isToday()) {
                                $this->sendNotification($user, $transaction, $nextOccurrenceDate);
                            }
                        }
                    }
                }
            }
        });

        $this->info('Reminder check finished.');
        return 0;
    }

    private function calculateNextOccurrenceDate(ScheduledTransaction $transaction): ?Carbon
    {
        $currentDate = Carbon::parse($transaction->start_date);
        $today = Carbon::today();

        if ($transaction->recurrence_type === 'none') {
            return $currentDate->isFuture() || $currentDate->isToday() ? $currentDate : null;
        }

        while ($currentDate->isBefore($today)) {
            switch ($transaction->recurrence_type) {
                case 'daily': $currentDate->addDays($transaction->recurrence_interval); break;
                case 'weekly': $currentDate->addWeeks($transaction->recurrence_interval); break;
                case 'monthly': $currentDate->addMonthsNoOverflow($transaction->recurrence_interval); break;
                case 'yearly': $currentDate->addYearsNoOverflow($transaction->recurrence_interval); break;
                default: return null;
            }
        }
        
        if ($transaction->end_date && $currentDate->isAfter($transaction->end_date)) {
            return null;
        }

        return $currentDate;
    }

    private function sendNotification(User $user, ScheduledTransaction $transaction, Carbon $occurrenceDate)
    {
        if (!$user->fcm_token) return;
        $messaging = app('firebase.messaging');
        $formattedAmount = number_format($transaction->amount, 2);
        $formattedDate = $occurrenceDate->isoFormat('dddd, D [de] MMMM');

        $notification = FirebaseNotification::create(
            'Recordatorio de Pago: ' . $transaction->title,
            "Vence el {$formattedDate} por un monto de \${$formattedAmount}."
        );
        $message = CloudMessage::withTarget('token', $user->fcm_token)
            ->withNotification($notification);
            
        try {
            $messaging->send($message);
            $this->info("Notification sent to user {$user->id} for transaction {$transaction->id}");
        } catch (\Exception $e) {
            $this->error("Failed to send notification to user {$user->id}: " . $e->getMessage());
            Log::error("FCM Error for user {$user->id}: " . $e->getMessage());
        }
    }
}