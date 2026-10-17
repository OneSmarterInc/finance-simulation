<?php

namespace Tests\Feature\Foundation;

use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_seed_data_creates_expected_identities(): void
    {
        $this->seed();

        $this->assertSame(1, Institution::count());
        $this->assertSame(1, Admin::count());
        $this->assertSame(1, Faculty::count());
        $this->assertSame(15, Student::count());
        $this->assertSame(17, User::count());

        $this->assertNotNull(User::where('email', 'admin@example.com')->firstOrFail()->admin);
        $this->assertNotNull(User::where('email', 'faculty@example.com')->firstOrFail()->faculty);
        $this->assertNotNull(User::where('email', 'student@example.com')->firstOrFail()->student);
        $this->assertNotNull(User::where('email', 'student2@example.com')->firstOrFail()->student);
        $this->assertNotNull(User::where('email', 'student3@example.com')->firstOrFail()->student);
    }

    public function test_seeded_credentials_can_authenticate(): void
    {
        $this->seed();

        $response = $this->post(route('login.store'), [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
