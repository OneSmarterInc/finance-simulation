<?php

namespace Tests\Feature\Walkthrough;

use App\Models\User;
use App\Support\Navigation\Home;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live, 19 October 2026: "Go to your courses" on the front page, and the
 * address /dashboard, gave students and admins a bare 403. Each person now
 * lands on their own start page, and an account with no role is signed out
 * and told why.
 */
class HomeByRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_each_role_lands_on_its_own_start_page(): void
    {
        $student = User::query()->where('email', 'student@example.com')->firstOrFail();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $faculty = User::query()->where('email', 'faculty@example.com')->firstOrFail();

        $this->actingAs($student)->get('/')->assertSee('href="'.route('student.courses').'"', false);
        $this->actingAs($student)->get('/dashboard')->assertRedirect(route('student.courses'));
        $this->actingAs($admin)->get('/')->assertSee('href="'.route('admin.dashboard').'"', false);
        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($faculty)->get('/dashboard')->assertOk();
    }

    public function test_an_account_with_no_role_is_signed_out_and_told_why(): void
    {
        $nobody = User::factory()->create(['email' => 'nobody@example.com', 'password' => 'password']);

        $this->actingAs($nobody)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', Home::NO_ACCESS);
        $this->assertGuest();
        $this->get(route('login'))->assertSee('isn&#039;t set up for any course yet', false);
    }
}
