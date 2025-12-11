<?php

namespace App\Services;

use App\Models\NotificationToken;
use App\Models\Reminder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Service for sending push notifications via Firebase Cloud Messaging (FCM).
 */
class NotificationService
{
    private string $fcmServerKey;
    private string $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

    public function __construct()
    {
        $this->fcmServerKey = config('services.fcm.server_key');
    }

    /**
     * Send a reminder notification to a user.
     * 
     * @param Reminder $reminder
     * @param string $type Type of notification: 'before' or 'today'
     * @return array Results with success/failure counts
     */
    public function sendReminderNotification(Reminder $reminder, string $type = 'before'): array
    {
        $user = $reminder->user;
        $tokens = NotificationToken::where('user_id', $user->id)->pluck('token')->toArray();
        
        if (empty($tokens)) {
            Log::info("No FCM tokens found for user {$user->id}");
            return ['success' => 0, 'failure' => 0];
        }

        $notification = $this->buildNotificationPayload($reminder, $type);
        
        return $this->sendToTokens($tokens, $notification, $reminder);
    }

    /**
     * Build notification payload based on reminder and type.
     * 
     * @param Reminder $reminder
     * @param string $type
     * @return array
     */
    private function buildNotificationPayload(Reminder $reminder, string $type): array
    {
        $dueDate = Carbon::parse($reminder->due_date)
            ->setTimezone($reminder->timezone)
            ->format('d/m/Y H:i');

        $title = match ($type) {
            'today' => '⚠️ Pago vence hoy',
            'before' => '🔔 Recordatorio de pago',
            default => '💰 Recordatorio financiero',
        };

        $body = match ($type) {
            'today' => "Hoy vence: {$reminder->title}",
            'before' => "{$reminder->title} vence el {$dueDate}",
            default => $reminder->title,
        };

        if ($reminder->amount) {
            $amount = number_format($reminder->amount, 0, ',', '.');
            $body .= " - \${$amount}";
        }

        return [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
        ];
    }

    /**
     * Send notification to multiple FCM tokens.
     * 
     * @param array $tokens FCM tokens
     * @param array $notification Notification payload
     * @param Reminder $reminder
     * @return array
     */
    private function sendToTokens(array $tokens, array $notification, Reminder $reminder): array
    {
        $data = [
            'reminder_id' => (string) $reminder->id,
            'type' => 'reminder',
            'action' => 'open_reminder',
        ];

        $payload = [
            'registration_ids' => $tokens,
            'notification' => $notification,
            'data' => $data,
            'priority' => 'high',
            'content_available' => true,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => "key={$this->fcmServerKey}",
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, $payload);

            if ($response->successful()) {
                $result = $response->json();
                
                Log::info("FCM notification sent for reminder {$reminder->id}", [
                    'success' => $result['success'] ?? 0,
                    'failure' => $result['failure'] ?? 0,
                ]);

                // Remove invalid tokens
                $this->removeInvalidTokens($result['results'] ?? [], $tokens);

                return [
                    'success' => $result['success'] ?? 0,
                    'failure' => $result['failure'] ?? 0,
                ];
            } else {
                Log::error("FCM request failed for reminder {$reminder->id}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['success' => 0, 'failure' => count($tokens)];
            }
        } catch (\Exception $e) {
            Log::error("Exception sending FCM notification for reminder {$reminder->id}", [
                'error' => $e->getMessage(),
            ]);

            return ['success' => 0, 'failure' => count($tokens)];
        }
    }

    /**
     * Remove invalid or unregistered tokens from database.
     * 
     * @param array $results FCM results array
     * @param array $tokens Original tokens array
     */
    private function removeInvalidTokens(array $results, array $tokens): void
    {
        foreach ($results as $index => $result) {
            if (isset($result['error']) && 
                in_array($result['error'], ['InvalidRegistration', 'NotRegistered'])) {
                
                $token = $tokens[$index] ?? null;
                if ($token) {
                    NotificationToken::where('token', $token)->delete();
                    Log::info("Removed invalid FCM token: {$token}");
                }
            }
        }
    }

    /**
     * Send a test notification to verify FCM setup.
     * 
     * @param string $token FCM token
     * @return bool
     */
    public function sendTestNotification(string $token): bool
    {
        $payload = [
            'to' => $token,
            'notification' => [
                'title' => 'Prueba de notificación',
                'body' => 'Tu configuración de notificaciones funciona correctamente.',
                'sound' => 'default',
            ],
            'priority' => 'high',
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => "key={$this->fcmServerKey}",
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, $payload);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Test notification failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
