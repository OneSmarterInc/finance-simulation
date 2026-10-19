<?php

namespace App\Services\Reporting;

use App\Models\ReportingClient;
use App\Models\ReportingConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * U3 (RP1, RP3): the OAuth side of the reporting connector, kept to what
 * Claude's client uses. Authorization code with S256 PKCE, public clients
 * (Claude's Client ID Metadata Document, or Dynamic Client Registration),
 * one-use codes, access tokens for an hour and refresh tokens that rotate on
 * every use. Only One Smarter admins can approve, and every token is stored
 * as a SHA-256 hash, so the database never holds one that works.
 */
class ReportingOAuth
{
    public const SCOPE = 'reporting:read';

    public static function resource(): string
    {
        return url('/mcp/reporting');
    }

    public static function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed> RFC 9728 */
    public static function protectedResourceMetadata(): array
    {
        return [
            'resource' => self::resource(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Flexee Financial Accounting reporting',
        ];
    }

    /** @return array<string, mixed> RFC 8414 */
    public static function authorizationServerMetadata(): array
    {
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => route('reporting.oauth.authorize'),
            'token_endpoint' => route('reporting.oauth.token'),
            'registration_endpoint' => route('reporting.oauth.register'),
            'scopes_supported' => [self::SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
        ];
    }

    /** A redirect URI the connector will send a sign-in back to: https on an allowed host, or a loopback address. */
    public static function redirectAllowed(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        if (! in_array($host, (array) config('flexee.reporting.redirect_hosts'), true)) {
            return false;
        }
        $loopback = in_array($host, ['localhost', '127.0.0.1'], true);

        return $parts['scheme'] === 'https' || ($loopback && $parts['scheme'] === 'http');
    }

    /** The registered URI matches exactly, or, for a loopback address, in all but the port (RFC 8252). */
    public static function redirectMatches(ReportingClient $client, string $uri): bool
    {
        foreach ($client->redirect_uris as $registered) {
            if (hash_equals((string) $registered, $uri)) {
                return true;
            }
            $a = parse_url((string) $registered);
            $b = parse_url($uri);
            if (is_array($a) && is_array($b) && in_array(strtolower($a['host'] ?? ''), ['localhost', '127.0.0.1'], true)
                && ($a['scheme'] ?? '') === ($b['scheme'] ?? '') && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
                && ($a['path'] ?? '/') === ($b['path'] ?? '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 7591. Returns the registration response, or an error code and description.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, body: array<string, mixed>}
     */
    public function register(array $input): array
    {
        $uris = $input['redirect_uris'] ?? null;
        if (! is_array($uris) || $uris === [] || count($uris) > 5) {
            return ['ok' => false, 'body' => ['error' => 'invalid_redirect_uri', 'error_description' => 'Give one to five redirect URIs.']];
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || mb_strlen($uri) > 500 || ! self::redirectAllowed($uri)) {
                return ['ok' => false, 'body' => ['error' => 'invalid_redirect_uri', 'error_description' => 'This connector signs in only for Claude.']];
            }
        }
        $method = $input['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            return ['ok' => false, 'body' => ['error' => 'invalid_client_metadata', 'error_description' => 'Only public clients (token_endpoint_auth_method "none") are supported.']];
        }
        $clientId = 'flexee-'.Str::lower(Str::random(32));
        $client = ReportingClient::query()->create([
            'client_id_hash' => self::hash($clientId),
            'client_id' => $clientId,
            'kind' => ReportingClient::DCR,
            'name' => is_string($input['client_name'] ?? null) ? mb_substr(strip_tags($input['client_name']), 0, 200) : null,
            'redirect_uris' => array_values($uris),
        ]);

        return ['ok' => true, 'body' => [
            'client_id' => $clientId,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => self::SCOPE,
        ]];
    }

    /**
     * The client named by a client_id: one registered here, or Claude's
     * published identity, read from its metadata document on an allowed host.
     */
    public function client(string $clientId): ?ReportingClient
    {
        if ($clientId === '' || mb_strlen($clientId) > 500) {
            return null;
        }
        $client = ReportingClient::query()->where('client_id_hash', self::hash($clientId))->first();
        if ($client && ($client->kind === ReportingClient::DCR || $client->updated_at->gt(now()->subDay()))) {
            return $client;
        }
        if (! str_starts_with($clientId, 'https://')) {
            return null;
        }
        $host = strtolower((string) parse_url($clientId, PHP_URL_HOST));
        if (! in_array($host, (array) config('flexee.reporting.client_metadata_hosts'), true)) {
            return null;
        }
        try {
            $doc = Http::timeout(5)->acceptJson()->get($clientId)->throw()->json();
        } catch (Throwable) {
            return $client;
        }
        if (! is_array($doc) || ($doc['client_id'] ?? null) !== $clientId || ! is_array($doc['redirect_uris'] ?? null)) {
            return null;
        }
        $uris = array_values(array_filter($doc['redirect_uris'], fn ($u) => is_string($u) && mb_strlen($u) <= 500 && self::redirectAllowed($u)));
        if ($uris === []) {
            return null;
        }
        $client ??= new ReportingClient(['client_id_hash' => self::hash($clientId), 'client_id' => $clientId, 'kind' => ReportingClient::CIMD]);
        $client->forceFill([
            'name' => is_string($doc['client_name'] ?? null) ? mb_substr(strip_tags($doc['client_name']), 0, 200) : null,
            'redirect_uris' => $uris,
            'updated_at' => now(),
        ])->save();

        return $client;
    }

    /**
     * Checks an authorization request before anything is shown. A problem with
     * the client or redirect URI is shown on our page; anything else goes back
     * to the client as an OAuth error.
     *
     * @param  array<string, mixed>  $q
     * @return array{client?: ReportingClient, redirect?: string, error?: string, description?: string, redirectable?: bool}
     */
    public function check(array $q): array
    {
        $client = $this->client((string) ($q['client_id'] ?? ''));
        if (! $client) {
            return ['error' => 'invalid_client', 'description' => 'This sign-in request comes from a client the Flexee reporting connector doesn\'t know.', 'redirectable' => false];
        }
        $redirect = (string) ($q['redirect_uri'] ?? '');
        if ($redirect === '' && count($client->redirect_uris) === 1) {
            $redirect = (string) $client->redirect_uris[0];
        }
        if ($redirect === '' || ! self::redirectMatches($client, $redirect)) {
            return ['error' => 'invalid_request', 'description' => 'The sign-in request names a return address that wasn\'t registered.', 'redirectable' => false];
        }
        $base = ['client' => $client, 'redirect' => $redirect, 'redirectable' => true];
        if (($q['response_type'] ?? '') !== 'code') {
            return $base + ['error' => 'unsupported_response_type', 'description' => 'Only response_type=code is supported.'];
        }
        if (($q['code_challenge_method'] ?? '') !== 'S256' || ! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', (string) ($q['code_challenge'] ?? ''))) {
            return $base + ['error' => 'invalid_request', 'description' => 'A PKCE code_challenge with method S256 is required.'];
        }
        $scope = trim((string) ($q['scope'] ?? ''));
        if ($scope !== '' && collect(preg_split('/\s+/', $scope))->reject(fn ($s) => in_array($s, [self::SCOPE, 'offline_access'], true))->isNotEmpty()) {
            return $base + ['error' => 'invalid_scope', 'description' => 'The only scope is '.self::SCOPE.'.'];
        }
        if (filled($q['resource'] ?? null) && rtrim((string) $q['resource'], '/') !== rtrim(self::resource(), '/')) {
            return $base + ['error' => 'invalid_target', 'description' => 'The resource must be '.self::resource().'.'];
        }

        return $base;
    }

    /** The one-use code an approved sign-in carries back. */
    public function issueCode(ReportingClient $client, User $user, string $redirect, string $challenge): string
    {
        $code = Str::random(64);
        DB::table('reporting_codes')->insert([
            'id' => (string) Str::uuid(),
            'code_hash' => self::hash($code),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => $redirect,
            'code_challenge' => $challenge,
            'scope' => self::SCOPE,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        return $code;
    }

    /**
     * The token endpoint.
     *
     * @param  array<string, mixed>  $p
     * @return array{ok: bool, body: array<string, mixed>}
     */
    public function token(array $p): array
    {
        return match ($p['grant_type'] ?? null) {
            'authorization_code' => $this->exchange($p),
            'refresh_token' => $this->refresh($p),
            default => $this->error('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    /** @param array<string, mixed> $p @return array{ok: bool, body: array<string, mixed>} */
    private function exchange(array $p): array
    {
        $client = $this->client((string) ($p['client_id'] ?? ''));
        $code = DB::table('reporting_codes')->where('code_hash', self::hash((string) ($p['code'] ?? '')))->first();
        if (! $client || ! $code || $code->client_id !== $client->id) {
            return $this->error('invalid_grant', 'The code isn\'t valid.');
        }
        // One use only: claim the code before checking the rest.
        $claimed = DB::table('reporting_codes')->where('id', $code->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($claimed !== 1 || now()->gt($code->expires_at)) {
            return $this->error('invalid_grant', 'The code has been used or has expired.');
        }
        if (filled($p['redirect_uri'] ?? null) && ! hash_equals($code->redirect_uri, (string) $p['redirect_uri'])) {
            return $this->error('invalid_grant', 'The redirect_uri doesn\'t match the sign-in.');
        }
        $verifier = (string) ($p['code_verifier'] ?? '');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || ! hash_equals($code->code_challenge, $expected)) {
            return $this->error('invalid_grant', 'The PKCE code_verifier doesn\'t match.');
        }
        $user = User::query()->find($code->user_id);
        if (! $user?->isAdmin()) {
            return $this->error('invalid_grant', 'Only One Smarter admins can connect.');
        }
        $access = $this->newToken('flxa_');
        $refresh = $this->newToken('flxr_');
        ReportingConnection::query()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'scope' => $code->scope,
            'access_hash' => self::hash($access),
            'access_expires_at' => now()->addMinutes((int) config('flexee.reporting.access_minutes')),
            'refresh_hash' => self::hash($refresh),
            'refresh_expires_at' => now()->addDays((int) config('flexee.reporting.refresh_days')),
        ]);
        $client->forceFill(['last_used_at' => now()])->save();

        return $this->tokens($access, $refresh);
    }

    /** @param array<string, mixed> $p @return array{ok: bool, body: array<string, mixed>} */
    private function refresh(array $p): array
    {
        $client = $this->client((string) ($p['client_id'] ?? ''));
        $connection = ReportingConnection::query()->where('refresh_hash', self::hash((string) ($p['refresh_token'] ?? '')))->first();
        if (! $client || ! $connection || $connection->client_id !== $client->id || ! $connection->isActive() || ! $connection->user?->isAdmin()) {
            return $this->error('invalid_grant', 'The refresh token isn\'t valid.');
        }
        $access = $this->newToken('flxa_');
        $refresh = $this->newToken('flxr_');
        // Rotate: the old refresh token stops working in the same write that issues the new one.
        $rotated = ReportingConnection::query()->where('id', $connection->id)->where('refresh_hash', $connection->refresh_hash)->update([
            'access_hash' => self::hash($access),
            'access_expires_at' => now()->addMinutes((int) config('flexee.reporting.access_minutes')),
            'refresh_hash' => self::hash($refresh),
            'refresh_expires_at' => now()->addDays((int) config('flexee.reporting.refresh_days')),
            'updated_at' => now(),
        ]);
        if ($rotated !== 1) {
            return $this->error('invalid_grant', 'The refresh token has already been used.');
        }

        return $this->tokens($access, $refresh);
    }

    /** The connection a bearer token belongs to, while it's live and its admin is still an admin. */
    public function authenticate(?string $bearer): ?ReportingConnection
    {
        if (! is_string($bearer) || ! str_starts_with($bearer, 'flxa_')) {
            return null;
        }
        $connection = ReportingConnection::query()->with('user')->where('access_hash', self::hash($bearer))->first();
        if (! $connection || $connection->revoked_at !== null || $connection->access_expires_at->isPast() || ! $connection->user?->isAdmin()) {
            return null;
        }

        return $connection;
    }

    public function revoke(ReportingConnection $connection, User $by): void
    {
        $connection->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $by->id])->save();
    }

    private function newToken(string $prefix): string
    {
        return $prefix.bin2hex(random_bytes(32));
    }

    /** @return array{ok: bool, body: array<string, mixed>} */
    private function tokens(string $access, string $refresh): array
    {
        return ['ok' => true, 'body' => [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('flexee.reporting.access_minutes') * 60,
            'refresh_token' => $refresh,
            'scope' => self::SCOPE,
        ]];
    }

    /** @return array{ok: bool, body: array<string, mixed>} */
    private function error(string $code, string $description): array
    {
        return ['ok' => false, 'body' => ['error' => $code, 'error_description' => $description]];
    }
}
