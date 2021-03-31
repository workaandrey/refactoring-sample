<?php

namespace App\Services\SmsProviders;

use App\Contracts\SmsProviderContract;

class SomeSmsProvider implements SmsProviderContract
{

    /**
     * @var string
     */
    private $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }


    public function send(int $phone, string $message): bool
    {
        $response = (new \GuzzleHttp\Client())->post(
            'https://a2p-api.megalabs.ru/sms/v1/sms',
            [
                'http_errors' => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . $this->token
                ],
                'json' => [
                    'from' => 'ya_verny',
                    'to' => $phone,
                    'message' => $message
                ]
            ]
        );

        return $response->getStatusCode() == 200;
    }
}