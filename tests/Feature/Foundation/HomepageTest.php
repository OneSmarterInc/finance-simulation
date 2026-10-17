<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_shows_the_product_and_sign_in(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('Flexee Financial Accounting')
            ->assertSee(route('login'), false)
            ->assertDontSee('CF Manufacturing')
            ->assertDontSee(app()->version())
            ->assertDontSee('Database connected');
    }

    public function test_test_mail_route_is_gone(): void
    {
        $this->get('/testMail')->assertNotFound();
    }

    public function test_health_check_stays_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
