<?php

namespace App\Providers;

use App\Contracts\SmsProviderContract;
use App\Services\SmsProviders\SomeSmsProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(SmsProviderContract::class, function($app) {
            return new SomeSmsProvider(config('services.sms.someProvider.token'));
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

    }
}
