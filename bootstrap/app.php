<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ReportingEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // U3: the reporting connector, outside the session and CSRF middleware.
            Route::middleware('api')->group(base_path('routes/reporting.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'reporting.enabled' => ReportingEnabled::class,
        ]);
        // U9 view-as: keep a faculty member viewing a practice run on that team's pages.
        $middleware->appendToGroup('web', \App\Http\Middleware\RestrictPracticeView::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
