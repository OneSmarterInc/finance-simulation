<?php

namespace App\Notifications\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MailjetChannel
{
    public function send(object $notifiable, object $notification): void
    {
        $key = config('services.mailjet.key');
        $secret = config('services.mailjet.secret');

        // Without credentials the request can only fail. Log it instead of
        // turning a registration or a password reset into a server error.
        if (blank($key) || blank($secret)) {
            Log::warning('Mailjet is not configured; email not sent.', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $message = $notification->toMailjet($notifiable);

        $payload = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => config('mail.from.address'),
                        'Name' => config('mail.from.name'),
                    ],
                    'To' => $message['to'],
                    'Subject' => $message['subject'],
                    'HTMLPart' => $message['html'],
                ],
            ],
        ];

        $response = Http::withBasicAuth($key, $secret)
            ->acceptJson()
            ->post(
                'https://api.mailjet.com/v3.1/send',
                $payload
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Mailjet API error: '.$response->body()
            );
        }
    }
}