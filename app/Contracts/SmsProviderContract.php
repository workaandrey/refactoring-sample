<?php

namespace App\Contracts;

interface SmsProviderContract
{
    public function send(int $phone, string $message): bool;
}