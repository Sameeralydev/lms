<?php

namespace Tests\Feature;

use App\Domain\Identity\PermissionCatalog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\GuardedRefreshDatabase;
use Tests\TestCase;

class AuthFoundationTest extends TestCase
{
    use GuardedRefreshDatabase;

    public function test_login_authenticates_and_regenerates_the_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Existing-Password-2026')]);
        $oldSessionId = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'Existing-Password-2026'])
            ->assertRedirect('/workspaces');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
    }

    public function test_invalid_login_returns_a_generic_field_error(): void
    {
        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'Incorrect-Password-2026'])
            ->assertSessionHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_signed_invitation_throttles_are_isolated_per_token_with_a_shared_ip_ceiling(): void
    {
        $limiter = RateLimiter::limiter('invitations');
        self::assertIsCallable($limiter);

        $requestForToken = static function (string $token): Request {
            $request = Request::create('/invitations/'.$token);
            $route = (new Route('GET', '/invitations/{token}', []))->bind($request);
            $request->setRouteResolver(static fn (): Route => $route);

            return $request;
        };

        /** @var list<Limit> $firstTokenLimits */
        $firstTokenLimits = $limiter($requestForToken('signed-token-one'));
        /** @var list<Limit> $secondTokenLimits */
        $secondTokenLimits = $limiter($requestForToken('signed-token-two'));

        self::assertCount(2, $firstTokenLimits);
        self::assertSame(10, $firstTokenLimits[0]->maxAttempts);
        self::assertNotSame($firstTokenLimits[0]->key, $secondTokenLimits[0]->key);
        self::assertStringNotContainsString('signed-token-one', (string) $firstTokenLimits[0]->key);
        self::assertSame('invitation-ip:127.0.0.1', $firstTokenLimits[1]->key);
        self::assertSame($firstTokenLimits[1]->key, $secondTokenLimits[1]->key);
        self::assertSame(60, $firstTokenLimits[1]->maxAttempts);
    }

    public function test_platform_operator_login_enters_the_platform_control_center(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Existing-Password-2026')]);
        $role = PermissionCatalog::createPlatformRole(
            'platform.operations_admin',
            'Platform Operations Administrator',
            'Test operations role.',
            ['platform.dashboard.view'],
        );
        $user->platformRoles()->attach($role->getKey(), ['assigned_by' => $user->getKey()]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Existing-Password-2026'])
            ->assertRedirect(route('platform.dashboard'));
    }

    public function test_password_reset_is_delivered_without_account_enumeration_and_rotates_credentials(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $knownResponse = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknownResponse = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'missing@example.test']);

        $knownResponse->assertRedirect('/forgot-password')->assertSessionHas('status');
        $unknownResponse->assertRedirect('/forgot-password')->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);

        $notification = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $sent) use (&$notification): bool {
            $notification = $sent;

            return true;
        });
        $this->assertInstanceOf(ResetPassword::class, $notification);

        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'New-Strong-Password-2026',
            'password_confirmation' => 'New-Strong-Password-2026',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-Strong-Password-2026', $user->fresh()->password));
        $this->assertGuest();
    }
}
