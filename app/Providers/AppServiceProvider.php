<?php

namespace App\Providers;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureStudentAccessConfirmed;
use App\Http\Middleware\RestrictPracticeView;
use App\Listeners\DispatchFirmWarmups;
use App\Notifications\Channels\MailjetChannel;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\VersionedManufacturingEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /** Set while this browser has a session from signing in (R15); its value is never trusted for access. */
    public const SIGNED_IN_COOKIE = 'flexee_was_signed_in';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ManufacturingEngine::class,
            VersionedManufacturingEngine::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // U9 view-as: Livewire actions are checked against the page they came from too.
        Livewire::addPersistentMiddleware([RestrictPracticeView::class]);

        Event::listen(
            CourseDeploymentReachedWarmupBoundary::class,
            DispatchFirmWarmups::class
        );

        // Live review R15: remember that this browser was signed in, so the sign-in page can say
        // why a page left open sent the next click there. Signing out on purpose forgets it.
        Event::listen(Login::class, fn () => Cookie::queue(self::SIGNED_IN_COOKIE, '1', 60 * 24 * 30));
        Event::listen(Logout::class, fn () => Cookie::queue(Cookie::forget(self::SIGNED_IN_COOKIE)));

        $this->app
            ->make(ChannelManager::class)
            ->extend(
                MailjetChannel::class,
                function ($app) {
                    return $app->make(MailjetChannel::class);
                }
            );

        // Re-apply these on Livewire actions, not only on the first page load.
        Livewire::addPersistentMiddleware([
            AdminMiddleware::class,
            EnsureStudentAccessConfirmed::class,
        ]);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }
}
