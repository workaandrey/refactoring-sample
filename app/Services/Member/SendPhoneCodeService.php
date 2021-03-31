<?php

namespace App\Services\Member;

use App\Services\SmsService;
use App\Models\Member;

class SendPhoneCodeService
{
    /**
     * @var SmsService
     */
    private $smsService;

    public function __construct(SmsService $smsService)
    {

        $this->smsService = $smsService;
    }

    public function sendCode(string $phone): array
    {
        $member = Member::firstOrCreate(['phone' => $phone]);

        if ($member->last_sms_sended_at == date("Y-m-d") || is_null($member->last_sms_sended_at)) {
            if ($member->sms_sended_counter >= 10) {
                return [
                    'errors' => ['sms_sended_counter' => ['SMS sending limit has been reached.']],
                ];
            }

            $counter = $member->sms_sended_counter + 1;
        } else {
            $counter = 1;
        }

        $member->update(
            [
                'sms_sended_counter' => $counter,
                'last_sms_sended_at' => Carbon::now()
            ]
        );

        if (!env('SMS_SEND', false)) {
            $member->update(
                [
                    'sms_code' => '1111',
                    'sms_code_expire' => Carbon::now()->addHour()
                ]
            );

            return ['status' => true];
        }

        $code = substr(str_shuffle('0123456789'), 0, 4);

        $smsSent = $this->smsService->sendSms((int)('7' . $phone), 'Ваш проверочный код: ' . $code);

        if ($smsSent) {
            $member->update(
                [
                    'sms_code' => $code,
                    'sms_code_expire' => Carbon::now()->addHour()
                ]
            );

            return ['status' => true];
        }

        return ['status' => false];
    }
}