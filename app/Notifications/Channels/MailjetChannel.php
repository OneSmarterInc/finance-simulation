<?php

namespace App\Notifications\Channels;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MailjetChannel
{
    public function send(object $notifiable, object $notification): void
    {
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

        $response = Http::withBasicAuth(
            config('services.mailjet.key'),
            config('services.mailjet.secret')
        )
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