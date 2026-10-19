<?php

namespace Tests\Feature\Reporting;

use App\Livewire\Admin\Reporting;
use App\Models\Admin;
use App\Models\Faculty;
use App\Models\FirmAccountingElectionRequest;
use App\Models\ReportingCall;
use App\Models\ReportingConnection;
use App\Models\Student;
use App\Models\User;
use App\Services\Practice\PracticeRunService;
use App\Services\Reporting\ReportingViews;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\Practice\BuildsPracticeSource;
use Tests\TestCase;

/**
 * U3 layer 1 (RP1 to RP3, decided 19 October 2026): the read-only reporting
 * connector. Claude signs in with an admin's Flexee account (OAuth, PKCE),
 * reads six views over MCP, and gets operational facts only.
 */
class ReportingConnectorTest extends TestCase
{
    use BuildsPracticeSource, RefreshDatabase;

    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    private Faculty $faculty;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->faculty = Faculty::query()->firstOrFail();
        $this->admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        config(['flexee.engine_version_for_new_courses' => 2]);
    }

    public function test_claude_finds_the_sign_in_from_a_401(): void
    {
        $this->postJson('/mcp/reporting', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/reporting').'", scope="reporting:read"');

        $this->getJson('/.well-known/oauth-protected-resource/mcp/reporting')->assertOk()
            ->assertJson(['resource' => url('/mcp/reporting'), 'authorization_servers' => [url('/')]]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJson([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/reporting/authorize'),
            'token_endpoint' => url('/oauth/reporting/token'),
            'registration_endpoint' => url('/oauth/reporting/register'),
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
        ]);
        $this->get('/mcp/reporting')->assertStatus(405);
    }

    public function test_an_admin_connects_and_claude_reads_every_view(): void
    {
        $tokens = $this->connect();

        $init = $this->mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude-ai', 'version' => '1']])->assertOk();
        $this->assertSame('2025-06-18', $init->json('result.protocolVersion'));
        $this->assertSame('flexee-reporting', $init->json('result.serverInfo.name'));
        $this->withToken($tokens['access_token'])->postJson('/mcp/reporting', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);

        $tools = $this->mcp($tokens['access_token'], 'tools/list')->assertOk()->json('result.tools');
        $this->assertSame(['report_courses', 'report_quarters', 'report_closes', 'report_requests', 'report_ai_use', 'report_health'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertStringContainsString('Columns:', $tool['description']);
        }

        $health = $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'report_health', 'arguments' => (object) []])->assertOk();
        $row = $health->json('result.structuredContent.rows.0');
        $this->assertSame('financial_accounting', $row['sim']);
        $this->assertSame('v1', $row['contract']);
        $this->assertSame(0, $row['migrations_pending']);
        $this->assertFalse($row['scheduler_ok'], 'no heartbeat yet');
        $this->assertSame(1, $row['connections_active']);
        $this->assertSame(['rows' => [$row]], json_decode($health->json('result.content.0.text'), true));

        $this->assertSame(-32602, $this->mcp($tokens['access_token'], 'tools/call', ['name' => 'drop_tables'])->json('error.code'));

        // Logged without the rows.
        $call = ReportingCall::query()->where('tool', 'report_health')->firstOrFail();
        $this->assertSame(['ok', 1], [$call->status, $call->rows]);
    }

    public function test_the_views_hold_operational_facts_only_and_leave_out_practice_runs(): void
    {
        $runs = app(PracticeRunService::class);
        $source = $this->sourceCourse('Spring Section', methodChanges: true);
        $practice = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 6);
        $converted = $runs->playTo($this->faculty, $runs->create($this->faculty, $source), 6);
        $converted->forceFill(['is_practice' => false, 'name' => 'Autumn Section'])->save();

        $firm = $converted->firms()->firstOrFail();
        $student = Student::query()->whereIn('id', $firm->memberships()->pluck('student_id'))->firstOrFail();
        FirmAccountingElectionRequest::query()->create([
            'firm_id' => $firm->id, 'course_id' => $converted->id, 'student_id' => $student->id,
            'course_quarter_id' => $converted->quarters()->where('quarter_number', 7)->value('id'),
            'proposed_elections' => ['depreciation' => 'double_declining_balance'], 'status' => 'pending',
            'justification' => 'Our plant wears out fastest in its first years.',
        ]);
        Cache::forever(ReportingViews::SCHEDULER_KEY, now()->subMinute()->getTimestamp());

        $tokens = $this->connect();
        $all = [];
        foreach (array_keys(ReportingViews::tools()) as $tool) {
            $all[$tool] = $this->mcp($tokens['access_token'], 'tools/call', ['name' => $tool])->assertOk()->json('result.structuredContent.rows');
        }

        $courses = collect($all['report_courses'])->keyBy('course');
        $this->assertEqualsCanonicalizing(['Spring Section', 'Autumn Section'], $courses->keys()->all());
        $this->assertSame(3, $courses['Autumn Section']['firms']);
        $this->assertSame('running', $courses['Autumn Section']['status']);
        $this->assertSame(7, $courses['Autumn Section']['quarter_now']);

        $q6 = collect($all['report_quarters'])->firstWhere(fn ($r) => $r['course'] === 'Autumn Section' && $r['quarter'] === 6);
        $this->assertSame('closed', $q6['state']);
        $this->assertSame(3, $q6['firms_submitted']);
        $this->assertNotNull($q6['closed_at']);
        $this->assertCount(9, collect($all['report_closes'])->where('course', 'Autumn Section'), 'three firms, Quarters 4 to 6');
        $this->assertSame([true, 'pending', null], [$all['report_requests'][0]['has_reason'], $all['report_requests'][0]['status'], $all['report_requests'][0]['review']]);
        $this->assertTrue($all['report_health'][0]['scheduler_ok']);
        $this->assertSame(1, $all['report_health'][0]['requests_waiting']);

        // RP2: no names, emails, written work or firm names, and nothing from the practice run.
        $json = json_encode($all);
        $this->assertStringNotContainsString($practice->id, $json);
        $this->assertStringNotContainsString('wears out', $json);
        foreach (User::query()->get() as $user) {
            $this->assertStringNotContainsString($user->email, $json);
            $this->assertStringNotContainsString('"'.$user->name.'"', $json);
        }
        foreach ($converted->firms()->pluck('name') as $name) {
            $this->assertStringNotContainsString('"'.$name.'"', $json);
        }
        $this->assertStringNotContainsString('net_income', $json);
    }

    public function test_pkce_codes_and_return_addresses_are_checked(): void
    {
        $client = $this->register();
        // Only Claude's return addresses can be registered.
        $this->postJson('/oauth/reporting/register', ['redirect_uris' => ['https://evil.example/callback']])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);

        [$query, $verifier] = $this->authorizeQuery($client);
        $this->actingAs($this->admin)->get('/oauth/reporting/authorize?'.http_build_query(['redirect_uri' => 'https://claude.ai/elsewhere'] + $query))->assertStatus(400)->assertSee('return address that wasn', false);
        $this->get('/oauth/reporting/authorize?'.http_build_query(['client_id' => 'flexee-unknown'] + $query))->assertStatus(400);
        $plain = ['code_challenge_method' => 'plain'] + $query;
        $this->assertStringContainsString('error=invalid_request', $this->get('/oauth/reporting/authorize?'.http_build_query($plain))->headers->get('Location'));

        $code = $this->approve($query);
        $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'code_verifier' => Str::random(64)])
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        // The failed attempt used the code up.
        $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'code_verifier' => $verifier])
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->assertSame(0, ReportingConnection::query()->count());

        // Cancel sends Claude away with nothing.
        $this->assertStringContainsString('error=access_denied', $this->post('/oauth/reporting/authorize', $query + ['decision' => 'deny'])->headers->get('Location'));
    }

    public function test_only_admins_can_connect(): void
    {
        $client = $this->register();
        [$query] = $this->authorizeQuery($client);

        // A guest signs in first, and an admin comes back to the consent page.
        $this->get('/oauth/reporting/authorize?'.http_build_query($query))->assertRedirect(route('login'));
        $sorted = $query;
        ksort($sorted);
        $this->post(route('login.store'), ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertRedirect('/oauth/reporting/authorize?'.http_build_query($sorted));
        auth()->logout();

        $faculty = User::query()->where('email', 'faculty@example.com')->firstOrFail();
        $this->actingAs($faculty)->get('/oauth/reporting/authorize?'.http_build_query($query))->assertForbidden()->assertSee('Only One Smarter admins can connect');
        $this->assertStringContainsString('error=access_denied', $this->actingAs($faculty)->post('/oauth/reporting/authorize', $query + ['decision' => 'approve'])->headers->get('Location'));

        // An admin's connection stops working the moment they stop being an admin.
        $tokens = $this->connect();
        $this->mcp($tokens['access_token'], 'ping')->assertOk();
        Admin::query()->where('user_id', $this->admin->id)->delete();
        $this->mcp($tokens['access_token'], 'ping')->assertUnauthorized();
    }

    public function test_refresh_tokens_rotate_and_revoke_ends_the_connection(): void
    {
        $tokens = $this->connect();
        $client = ReportingConnection::query()->firstOrFail()->client->client_id;

        $next = $this->post('/oauth/reporting/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertOk()->json();
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
        $this->post('/oauth/reporting/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $client])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->mcp($tokens['access_token'], 'ping')->assertUnauthorized();
        $this->mcp($next['access_token'], 'ping')->assertOk();

        $this->travel(61)->minutes();
        $this->mcp($next['access_token'], 'ping')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->travelBack();

        $connection = ReportingConnection::query()->firstOrFail();
        $this->actingAs($this->admin);
        Livewire::test(Reporting::class)
            ->assertSee(url('/mcp/reporting'))
            ->assertSee('Active')
            ->call('confirmRevoke', $connection->id)
            ->call('revoke')
            ->assertSee('connection has been revoked', false);
        $this->post('/oauth/reporting/token', ['grant_type' => 'refresh_token', 'refresh_token' => $next['refresh_token'], 'client_id' => $client])->assertStatus(400);
        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    public function test_claudes_published_identity_is_read_from_its_metadata_document(): void
    {
        $id = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
        Http::fake([$id => Http::response(['client_id' => $id, 'client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK, 'https://evil.example/cb']])]);

        [$query, $verifier] = $this->authorizeQuery($id);
        $this->actingAs($this->admin)->get('/oauth/reporting/authorize?'.http_build_query($query))->assertOk()->assertSee('Claude is asking')->assertSee('claude.ai');
        $code = $this->approve($query);
        $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $id, 'redirect_uri' => self::CALLBACK, 'code_verifier' => $verifier])->assertOk();
        $this->assertSame([self::CALLBACK], ReportingConnection::query()->firstOrFail()->client->redirect_uris, 'only Claude\'s return addresses are kept');

        // A metadata document on any other host isn't read.
        [$other] = $this->authorizeQuery('https://evil.example/client.json');
        $this->get('/oauth/reporting/authorize?'.http_build_query($other))->assertStatus(400);
        Http::assertSentCount(1);
    }

    public function test_the_admin_page_shows_what_claude_would_get_and_calls_are_rate_limited(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Reporting::class)
            ->assertSee('Organization settings › Connectors › Add › Custom')
            ->assertSee('No one has connected yet.')
            ->call('show', 'report_health')
            ->assertSee('migrations_pending')
            ->assertSee('Hide rows');
        $this->get(route('admin.reporting'))->assertOk()->assertSee('Reporting');
        $this->actingAs(User::query()->where('email', 'faculty@example.com')->firstOrFail())->get(route('admin.reporting'))->assertForbidden();

        config(['flexee.reporting.calls_per_minute' => 2]);
        $tokens = $this->connect();
        $this->mcp($tokens['access_token'], 'ping')->assertOk();
        $this->mcp($tokens['access_token'], 'ping')->assertOk();
        $this->mcp($tokens['access_token'], 'ping')->assertStatus(429);

        config(['flexee.reporting.enabled' => false]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
    }

    private function register(): string
    {
        return $this->postJson('/oauth/reporting/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK], 'token_endpoint_auth_method' => 'none'])
            ->assertCreated()->json('client_id');
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function authorizeQuery(string $client): array
    {
        $verifier = Str::random(64);

        return [[
            'response_type' => 'code',
            'client_id' => $client,
            'redirect_uri' => self::CALLBACK,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => 'st-'.Str::random(8),
            'scope' => 'reporting:read',
            'resource' => url('/mcp/reporting'),
        ], $verifier];
    }

    private function approve(array $query): string
    {
        $location = $this->actingAs($this->admin)->post('/oauth/reporting/authorize', $query + ['decision' => 'approve'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $this->assertSame([$query['state'], url('/')], [$back['state'], $back['iss']]);

        return $back['code'];
    }

    /** @return array<string, mixed> */
    private function connect(): array
    {
        $client = $this->register();
        [$query, $verifier] = $this->authorizeQuery($client);
        $this->actingAs($this->admin)->get('/oauth/reporting/authorize?'.http_build_query($query))->assertOk()->assertSee('Allow read-only access');
        $code = $this->approve($query);

        return $this->post('/oauth/reporting/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'code_verifier' => $verifier])
            ->assertOk()->assertJson(['token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'reporting:read'])->assertHeader('Cache-Control')->json();
    }

    private function mcp(string $token, string $method, array $params = []): TestResponse
    {
        static $id = 0;

        return $this->withToken($token)->postJson('/mcp/reporting', ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params]);
    }
}
