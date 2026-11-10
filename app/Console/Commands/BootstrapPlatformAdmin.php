<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\PermissionCatalog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class BootstrapPlatformAdmin extends Command
{
    protected $signature = 'platform:bootstrap-admin {email : Email for the first platform super administrator}';

    protected $description = 'Create or promote the first platform super administrator using an interactive password prompt.';

    public function handle(AuditRecorder $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid email address.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        $name = '';
        $password = null;
        if ($user) {
            if (! $this->confirm("Promote the existing account {$email} to platform super administrator?", false)) {
                $this->warn('No account was changed.');

                return self::FAILURE;
            }
        } else {
            $name = trim((string) $this->ask('Administrator name'));
            if ($name === '') {
                $this->error('A name is required.');

                return self::FAILURE;
            }

            $password = (string) $this->secret('Set an initial password (minimum 12 characters)');
            $confirmation = (string) $this->secret('Confirm the initial password');
            if (mb_strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
                $this->error('The password must be at least 12 characters and both entries must match.');

                return self::FAILURE;
            }

        }

        try {
            DB::transaction(function () use ($email, $name, $password, $user, $audit): void {
                $bootstrapState = DB::table('platform_bootstrap_states')->where('id', 1)->lockForUpdate()->first();
                if (! $bootstrapState || $bootstrapState->completed) {
                    throw new RuntimeException('Platform administrator bootstrap has already completed.');
                }
                if (User::query()->whereHas('platformRoles', fn ($query) => $query->where('key', 'platform.super_admin'))->exists()) {
                    throw new RuntimeException('A platform super administrator already exists.');
                }
                if (! $user) {
                    $user = (new User)->forceFill([
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make((string) $password),
                        'email_verified_at' => now(),
                    ]);
                }
                $user->save();
                $role = PermissionCatalog::createPlatformRole(
                    'platform.super_admin',
                    'Platform Super Administrator',
                    'Unrestricted access to platform control-plane operations.',
                    array_keys(PermissionCatalog::platformPermissions()),
                );
                $user->platformRoles()->syncWithoutDetaching([$role->getKey() => ['assigned_by' => $user->getKey()]]);
                $audit->record('platform.super_admin_bootstrapped', $user, null, $user, ['account_created' => $user->wasRecentlyCreated]);
                DB::table('platform_bootstrap_states')->where('id', 1)->update(['completed' => true, 'completed_at' => now()]);
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Platform super administrator is ready.');

        return self::SUCCESS;
    }
}
