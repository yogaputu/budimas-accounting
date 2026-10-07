<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_publicly_available(): void
    {
        $this->withoutVite();

        $response = $this->get(route('login'));

        $response
            ->assertOk()
            ->assertSee('Masuk ke Accounting');
    }
}
