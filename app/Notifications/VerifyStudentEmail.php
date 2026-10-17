<?php

namespace App\Notifications;

use App\Notifications\Channels\MailjetChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class VerifyStudentEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $verificationUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return [MailjetChannel::class];
    }

    public function toMailjet(object $notifiable): array
    {
        return [
            'subject' => 'Verify your Flexee Financial Accounting account',

            'to' => [
                [
                    'Email' => $notifiable->email,
                    'Name' => $notifiable->name,
                ],
            ],

            'html' => view(
                'emails.verify-student-email',
                [
                    'user' => $notifiable,
                    'verificationUrl' => $this->verificationUrl,
                ]
            )->render(),
        ];
    }
}
