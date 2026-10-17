<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_returns_smoke_test_status(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('CF Manufacturing app is running')
            ->assertSee(app()->version())
            ->assertSee('Database connected');
    }
}
