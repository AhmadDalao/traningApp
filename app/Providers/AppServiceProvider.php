<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['coach-lookup' => [10, null], 'coach-requests' => [5, 30], 'coach-qr' => [5, null], 'coach-token' => [20, null]] as $name => [$minute, $day]) {
            RateLimiter::for($name, function ($request) use ($name, $minute, $day) {
                $limits = [Limit::perMinute($minute)->by($name.':user:'.$request->user()?->id), Limit::perMinute($minute * 3)->by($name.':ip:'.$request->ip())];
                if ($day) {
                    $limits[] = Limit::perDay($day)->by($name.':day:'.$request->user()?->id);
                }

                return $limits;
            });
        }
    }
}
