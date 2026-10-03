<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * The root path is the landing page, which the Python application redirects
     * to `/login` (301) — see `routes/public-pages.php`. The stock scaffold
     * asserted 200, which was only true before that route was ported.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
