<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedbackRequest;
use App\Http\Requests\ProfileDataRequest;
use App\Mail\FeedbackMessage;
use App\Models\Member;
use App\Models\FamilyStatus;
use App\Models\City;
use App\Notifications\FeedbackNotification;
use App\Services\Member\SendPhoneCodeService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use App\Http\Requests\CheckPhoneCodeRequest;
use App\Http\Requests\RegistrationRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\LoginRegistrationRequest;
use App\Http\Requests\UpdateRequest;
use App\Http\Requests\UploadRequest;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use TimeHunter\LaravelGoogleReCaptchaV2\Facades\GoogleReCaptchaV2;

class ApiController extends Controller
{
    /**
     * @var \Illuminate\Contracts\Auth\Authenticatable|null
     */
    private $member;

    public function __construct()
    {
        $this->middleware(
            'auth:api',
            [
                'except' => [
                    'login',
                    'login_registration',
                    'feedback',
                    'registration',
                    'send_phone_code',
                    'check_phone_code',
                    'get_current_city'
                ]
            ]
        );
    }

    public function login(LoginRequest $request)
    {
        $credentials = request(['phone', 'password']);

        if (!$token = auth()->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $ttl = auth()->factory()->getTTL();

        return response()
            ->json(
                [
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => $ttl * 60
                ]
            )
            ->withCookie(cookie('access_token', $token, $ttl));
    }

    public function login_registration(LoginRegistrationRequest $request)
    {
        if (Member::where('phone', $request->phone)->whereNotNull('password')->exists()) {
            return response()->json(
                [
                    'registration_status' => 'registered'
                ]
            );
        } else {
            $send_sms = $this->send_sms($request->phone);

            return response()->json(
                [
                    'registration_status' => 'not registered',
                    'send_phone_code' => $send_sms['status']
                ]
            );
        }
    }

    public function send_phone_code(LoginRegistrationRequest $request, SendPhoneCodeService $sendPhoneCodeService)
    {
        return response()->json($sendPhoneCodeService->sendCode($request->phone));
    }

    public function check_phone_code(CheckPhoneCodeRequest $request)
    {
        $member = Member::where('phone', $request->phone)
            ->where('sms_code', $request->code)
            ->where('sms_code_expire', '>=', Carbon::now());

        if (!$member->exists()) {
            return response()->json(
                [
                    'errors' => ['code' => ['SMS code is incorrect.']],
                ],
                422
            );
        }

        $member->update(['phone_verified_at' => Carbon::now()]);

        return response()->json(
            [
                'status' => true,
                'phone_confirm_token' => $this->phone_confirm_token($request->code)
            ]
        );
    }

    public function registration(RegistrationRequest $request)
    {
        $member = Member::where('phone', $request->phone)->first();

        if ($request->phone_confirm_token != $this->phone_confirm_token($member->sms_code)) {
            return response()->json(
                [
                    'errors' => ['phone_confirm_token' => ['Token is incorrect.']],
                ],
                422
            );
        }

        $geoip = geoip()->getLocation();

        $member->update(
            [
                'password' => Hash::make($request->password),
                'bad_ip' => City::where('name', $geoip->city)->exists() ? 0 : 1,
                'geoip' => json_encode(((array)$geoip)[chr(0) . '*' . chr(0) . 'attributes']),
                'sms_code' => null,
                'sms_code_expire' => null
            ]
        );

        $token = auth()->tokenById($member->id);
        $ttl = auth()->factory()->getTTL();

        return response()
            ->json(
                [
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => auth()->factory()->getTTL() * 60
                ]
            )
            ->withCookie(cookie('access_token', $token, $ttl));
    }

    public function profileData(ProfileDataRequest $request): string
    {
        $this->member = auth()->user();

        if ($this->member->status->name != 'REGISTERED') {
            throw new HttpResponseException(response()->json(
                ['errors' => ['member_status' => ['Статус участника должен быть REGISTERED.']],],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            ));
        }

        $upd = $this->patch($request);

        if (isset($upd['errors'])) {
            throw new HttpResponseException(response()->json(
                ['errors' => $upd['errors']],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            ));
        } elseif ($upd == true) {
            return response()->json(['status' => true])->getContent();
        }
    }

    public function update(UpdateRequest $request)
    {
        $this->member = auth()->user();

        if ($this->member->status->name != 'BASE_FORM_REFILL') {
            throw new HttpResponseException(response()->json(
                ['errors' => ['member_status' => ['Статус участника должен быть BASE_FORM_REFILL.']],],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            ));
        }

        $upd = $this->patch($request);

        if (isset($upd['errors'])) {
            throw new HttpResponseException(response()->json(
                ['errors' => $upd['errors']],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            ));
        } elseif ($upd == true) {
            return response()->json(['status' => true]);
        }
    }

    private function patch($request)
    {
        $this->member = auth()->user();

        $requestData = $request->except('old_password');

        if ($request->has('password')) {
            if ($request->has('old_password') && auth()->validate(
                    [
                        'phone' => $this->member->phone,
                        'password' => $request->old_password
                    ]
                )) {
                $requestData['password'] = Hash::make($request->password);
            } else {
                return ['errors' => ['old_password' => ['Old password requred.']]];
            }
        }

        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('photos/' . $this->member->id);
            $requestData['photo'] = $photoPath;
        }

        if ($this->member->update($requestData)) {
            if ($this->member->update(['status_id' => 2])) {
                return true;
            }
        }

        return ['errors' => ['update' => ['Не удалось сохранить данные в БД.']]];
    }

    public function upload(UploadRequest $request)
    {
        $this->member = auth()->user();

        $docs = [
            'passport_main'           => $this->member->passport_main != '',
            'passport_registration'   => $this->member->passport_registration != '',
            'name_change_certificate' => $this->member->name_change_certificate != '',
            'requisites'              => $this->member->requisites != '',
            'agreement'               => $this->member->agreement != '',
        ];

        foreach ($docs as $docKey => $status) {
            if ($request->hasFile($docKey)) {

                if (!is_null($this->member->$docKey) && $this->member->{$docKey . '_status'} != 'Отклонён') {
                    $docs[$docKey] = true;
                    continue;
                }

                $path = $request->file($docKey)->store('docs/' . $this->member->id . '/' . $docKey, 'local');

                if ($this->member->update([$docKey => $path])) {
                    $this->member->update([$docKey . '_status' => 'На проверке']);
                    $docs[$docKey] = true;
                }
            }
        }

        $updateUserStatus = $this->member->checkAllDocsAndUpdateStatus();

        return response()->json(['status' => true, 'update_user_status' => $updateUserStatus, 'docs_statuses' => $docs]);
    }

    public function get_file($doc)
    {
        $this->member = auth()->user();
        $path = Storage::path($this->member->$doc);

        header('Content-Type: ' . mime_content_type($path));

        header('Content-Length: ' . filesize($path));

        readfile($path);
    }

    public function me()
    {
        $this->member = auth()->user();
        return response()->json($this->member);
    }

    public function refresh()
    {
        $token = auth()->refresh();

        return response()
            ->json([
                'access_token' => $token,
            ])
            ->withCookie(cookie()->forever('access_token', $token));
    }

    public function get_family_statuses()
    {
        return response()->json(FamilyStatus::all());
    }

    public function get_cities()
    {
        return response()->json(City::all());
    }

    public function get_current_city()
    {
        $geoip = geoip()->getLocation();

        $city = City::where('name', $geoip->city)->first();

        return response()->json([
            'city_id' => $city ? $city->id : null,
            'city_name' => $city ? $city->name : null,
        ]);
    }

    public function feedback(FeedbackRequest $request)
    {
        try {
            Mail::to('info@vernopromo.ru')
                ->send(new FeedbackMessage(
                    $request->email,
                    $request->name,
                    $request->message,
                    $request->subject,
                ));
        } catch (\Exception $ex) {
            return [
                'errors' => ['sendmail' => [$ex->getMessage()]],
            ];
        }

        return response()->json(['status' => true]);
    }

    function phone_confirm_token($code)
    {
        $salt = 'JHGDFA*&^*&^FDA1';

        return md5($code . $salt);
    }
}
