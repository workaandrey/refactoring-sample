<?php

namespace App\Services;

use App\Contracts\SmsProviderContract;

class SmsService
{
    /**
     * @var SomeSmsProvider
     */
    private $smsProvider;

    public function __construct(SmsProviderContract $smsProvider)
    {

        $this->smsProvider = $smsProvider;
    }

    public function sendSms(int $phone, string $message)
    {
        return $this->smsProvider->sendSms($phone, $message);
    }
}