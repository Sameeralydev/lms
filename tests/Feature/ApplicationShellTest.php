<?php

namespace Tests\Feature;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ApplicationShellTest extends TestCase
{
    public function test_the_public_home_page_renders_the_inertia_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('welcome')
                ->where('appName', 'TechMentors.PK'));
    }

    public function test_public_responses_set_security_headers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_untrusted_host_headers_are_rejected(): void
    {
        $this->get('http://attacker.example/')
            ->assertBadRequest()
            ->assertDontSee('attacker.example');
    }

    public function test_production_responses_set_a_restrictive_content_security_policy(): void
    {
        config(['app.env' => 'production']);

        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data:; font-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'");
    }

    public function test_a_health_diagnostic_exception_is_rendered_without_debug_details(): void
    {
        config(['app.debug' => false]);
        Event::listen(DiagnosingHealth::class, fn () => throw new \RuntimeException('private database detail'));

        $this->get('/up')
            ->assertServerError()
            ->assertDontSee('private database detail')
            ->assertDontSee('Stack trace');
    }

    public function test_session_security_defaults_are_enabled(): void
    {
        $this->assertTrue(config('session.encrypt'));
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame('json', config('session.serialization'));
    }

    public function test_production_debugging_is_disabled_in_the_test_configuration(): void
    {
        $this->assertFalse(config('app.debug'));
        $this->assertFalse(config('app.e2e_test_mode'));
    }
}
