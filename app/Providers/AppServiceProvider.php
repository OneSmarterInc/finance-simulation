<?php

namespace App\Providers;

use App\Events\CourseDeploymentReachedWarmupBoundary;
use App\Listeners\DispatchFirmWarmups;
use App\Notifications\Channels\MailjetChannel;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ManufacturingEngine::class,
            ManufacturingCFEngine::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            CourseDeploymentReachedWarmupBoundary::class,
            DispatchFirmWarmups::class
        );

        $this->app
            ->make(ChannelManager::class)
            ->extend(
                MailjetChannel::class,
                function ($app) {
                    return $app->make(MailjetChannel::class);
                }
            );

        // Re-apply these on Livewire actions, not only on the first page load.
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\AdminMiddleware::class,
            \App\Http\Middleware\EnsureStudentAccessConfirmed::class,
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