<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_redirects_guest_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
