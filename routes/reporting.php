<?php

use App\Http\Controllers\Reporting\ReportingConnectorController as Connector;
use Illuminate\Support\Facades\Route;

/*
 * U3 (RP1): the reporting connector's machine-facing endpoints. These run in
 * the api middleware group: no session, no cookies and no CSRF token, since
 * Claude's servers call them directly. Sign-in itself is in routes/web.php.
 */
Route::middleware('reporting.enabled')->group(function () {
    Route::get('.well-known/oauth-protected-resource/mcp/reporting', [Connector::class, 'protectedResource'])->name('reporting.metadata.resource');
    Route::get('.well-known/oauth-protected-resource', [Connector::class, 'protectedResource']);
    Route::get('.well-known/oauth-authorization-server', [Connector::class, 'authorizationServer'])->name('reporting.metadata.server');
    Route::get('.well-known/oauth-authorization-server/oauth/reporting', [Connector::class, 'authorizationServer']);

    Route::post('oauth/reporting/register', [Connector::class, 'register'])->middleware('throttle:20,60')->name('reporting.oauth.register');
    Route::post('oauth/reporting/token', [Connector::class, 'token'])->middleware('throttle:60,1')->name('reporting.oauth.token');

    Route::post('mcp/reporting', [Connector::class, 'mcp'])->name('reporting.mcp');
    Route::get('mcp/reporting', [Connector::class, 'mcpGet']);
});
