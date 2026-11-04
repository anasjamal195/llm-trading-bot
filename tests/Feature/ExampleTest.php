<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_admin(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/admin');
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get('/admin/login');
        $response->assertStatus(200);
    }

    public function test_admin_requires_auth(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/api/status')->assertRedirect('/admin/login');
    }
}
