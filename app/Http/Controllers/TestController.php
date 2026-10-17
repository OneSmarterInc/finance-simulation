<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mailjet\Client;
use Mailjet\Resources;

class TestController extends Controller
{
    public function testMail(): JsonResponse
    {
        $mailjet = new Client(
            config('services.mailjet.key'),
            config('services.mailjet.secret'),
            true,
            [
                'version' => 'v3.1',
            ]
        );

        $body = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => config('mail.from.address'),
                        'Name' => config('mail.from.name'),
                    ],
                    'To' => [
                        [
                            'Email' => 'developer.mukeshk@gmail.com',
                            'Name' => 'Test User',
                        ],
                    ],
                    'Subject' => 'FLEXEE Mailjet Test',
                    'TextPart' => 'This is a test email from FLEXEE Finance Simulation using Mailjet API.',
                    'HTMLPart' => '
                        <h2>FLEXEE - FINANCE</h2>
                        <p>This is a test email.</p>
                        <p>Mailjet API is working correctly.</p>
                    ',
                ],
            ],
        ];

        $response = $mailjet->post(
            Resources::$Email,
            ['body' => $body]
        );

        if (! $response->success()) {
            return response()->json([
                'success' => false,
                'status' => $response->getStatus(),
                'response' => $response->getData(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Test email sent successfully.',
            'response' => $response->getData(),
        ]);
    }
}