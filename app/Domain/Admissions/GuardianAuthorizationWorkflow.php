<?php

namespace App\Domain\Admissions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Mail\AdmissionGuardianInvitation;
use App\Models\AdmissionApplication;
use App\Models\AdmissionGuardianAuthorization;
use App\Models\AdmissionSetting;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class GuardianAuthorizationWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{guardian_name:string,guardian_email:string,relationship:string} $data */
    public function invite(AdmissionApplication $application, User $applicant, array $data): AdmissionGuardianInvitationResult
    {
        if (! $this->requiresGuardian($application)) {
            throw ValidationException::withMessages(['guardian' => 'A guardian request is only required for an applicant below the institution age threshold.']);
        }
        abort_unless((int) $application->applicant_user_id === (int) $applicant->getKey(), 404);
        abort_unless($application->status === 'draft', 409);

        $email = Str::lower(trim($data['guardian_email']));
        if ($email === Str::lower((string) $applicant->email)) {
            throw ValidationException::withMessages(['guardian_email' => 'A guardian must use a separate verified email account.']);
        }
        if (! app()->environment('testing') && $this->defaultMailerIsUnsafe()) {
            abort(503, 'Guardian email delivery is not configured securely. No invitation was created.');
        }

        $token = bin2hex(random_bytes(32));
        $tenant = $this->context->tenant();
        $authorization = DB::transaction(function () use ($application, $applicant, $data, $email, $token): AdmissionGuardianAuthorization {
            AdmissionApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $existingActive = AdmissionGuardianAuthorization::query()
                ->where('application_id', $application->getKey())
                ->where('guardian_email', $email)
                ->where('status', 'active')
                ->exists();
            if ($existingActive) {
                throw ValidationException::withMessages(['guardian_email' => 'This guardian already has active access.']);
            }

            AdmissionGuardianAuthorization::query()
                ->where('application_id', $application->getKey())
                ->where('guardian_email', $email)
                ->whereIn('status', ['invited', 'guardian_verified'])
                ->update([
                    'status' => 'revoked', 'revoked_by' => $applicant->getKey(), 'revoked_at' => now(),
                    'token_hash' => null, 'updated_at' => now(),
                ]);

            $authorization = AdmissionGuardianAuthorization::query()->create([
                'application_id' => $application->getKey(),
                'applicant_user_id' => $applicant->getKey(),
                'guardian_name' => trim($data['guardian_name']),
                'guardian_email' => $email,
                'relationship' => $data['relationship'],
                'token_hash' => hash('sha256', $token),
                'status' => 'invited',
                'expires_at' => now()->addDays(7),
            ]);
            $application->profile()->update([
                'guardian_name' => trim($data['guardian_name']),
                'guardian_email' => $email,
                'guardian_relationship' => $data['relationship'],
                'guardian_consent_at' => null,
            ]);

            return $authorization;
        });

        $url = URL::temporarySignedRoute('public.admissions.guardians.invitation', now()->addDays(7), [
            'tenant' => $tenant->slug,
        ]).'#'.$token;

        try {
            Mail::to($authorization->guardian_email)->send(new AdmissionGuardianInvitation(
                $url,
                (string) Institution::query()->value('display_name'),
                (string) $applicant->name,
                $authorization->relationship,
            ));
        } catch (Throwable) {
            $authorization->forceFill(['status' => 'delivery_failed', 'token_hash' => null, 'expires_at' => null])->save();
            throw ValidationException::withMessages(['guardian_email' => 'The guardian request could not be delivered. No access was granted.']);
        }

        $this->audit->record('admissions.guardian.invited', $applicant, (int) $tenant->getKey(), $authorization, [
            'application_id' => $application->getKey(), 'relationship' => $authorization->relationship,
            'status' => $authorization->status,
        ]);

        return new AdmissionGuardianInvitationResult($authorization, $url);
    }

    public function accept(Tenant $tenant, string $token, User $guardian): AdmissionGuardianAuthorization
    {
        abort_unless($guardian->email_verified_at !== null, 404);
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($tenant, $hash, $guardian): AdmissionGuardianAuthorization {
            $authorization = AdmissionGuardianAuthorization::query()
                ->where('tenant_id', $tenant->getKey())->where('token_hash', $hash)
                ->lockForUpdate()->firstOrFail();
            abort_unless($authorization->status === 'invited' && $authorization->expires_at?->isFuture(), 404);
            abort_unless(hash_equals(Str::lower($authorization->guardian_email), Str::lower((string) $guardian->email)), 404);

            $authorization->forceFill([
                'guardian_user_id' => $guardian->getKey(), 'email_verified_at' => now(),
                'status' => 'guardian_verified', 'token_hash' => null,
            ])->save();
            $this->audit->record('admissions.guardian.email_verified', $guardian, (int) $tenant->getKey(), $authorization, [
                'application_id' => $authorization->application_id, 'status' => 'guardian_verified',
            ]);

            return $authorization;
        });
    }

    public function verifyRelationship(AdmissionGuardianAuthorization $authorization, User $reviewer, string $method): AdmissionGuardianAuthorization
    {
        return DB::transaction(function () use ($authorization, $reviewer, $method): AdmissionGuardianAuthorization {
            $locked = AdmissionGuardianAuthorization::query()->whereKey($authorization->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'guardian_verified' && $locked->guardian_user_id !== null, 409);
            abort_if((int) $locked->applicant_user_id === (int) $reviewer->getKey() || (int) $locked->guardian_user_id === (int) $reviewer->getKey(), 403);

            $locked->forceFill([
                'status' => 'active', 'relationship_verified_by' => $reviewer->getKey(),
                'relationship_verified_at' => now(), 'verification_method' => $method,
            ])->save();
            $this->audit->record('admissions.guardian.relationship_verified', $reviewer, (int) $locked->tenant_id, $locked, [
                'application_id' => $locked->application_id, 'verification_method' => $method, 'status' => 'active',
            ]);

            return $locked;
        });
    }

    public function revoke(AdmissionGuardianAuthorization $authorization, User $actor): AdmissionGuardianAuthorization
    {
        return DB::transaction(function () use ($authorization, $actor): AdmissionGuardianAuthorization {
            $locked = AdmissionGuardianAuthorization::query()->whereKey($authorization->getKey())->lockForUpdate()->firstOrFail();
            $actorId = (int) $actor->getKey();
            if ($actorId !== (int) $locked->applicant_user_id && ($locked->guardian_user_id === null || $actorId !== (int) $locked->guardian_user_id)) {
                throw new NotFoundHttpException;
            }
            abort_unless(in_array($locked->status, ['invited', 'guardian_verified', 'active'], true), 409);
            $locked->forceFill([
                'status' => 'revoked', 'revoked_by' => $actor->getKey(), 'revoked_at' => now(), 'token_hash' => null,
            ])->save();
            $this->audit->record('admissions.guardian.revoked', $actor, (int) $locked->tenant_id, $locked, [
                'application_id' => $locked->application_id, 'status' => 'revoked',
            ]);

            return $locked;
        });
    }

    public function requiresGuardian(AdmissionApplication $application): bool
    {
        $profile = $application->profile()->firstOrFail();
        $threshold = $this->ageThreshold((int) $application->tenant_id);

        return $profile->birth_date === null || Carbon::parse($profile->birth_date)->age < $threshold;
    }

    public function hasActiveGuardian(AdmissionApplication $application): bool
    {
        return AdmissionGuardianAuthorization::query()->where('application_id', $application->getKey())
            ->where('applicant_user_id', $application->applicant_user_id)->where('status', 'active')
            ->whereNotNull('guardian_user_id')->whereNotNull('email_verified_at')
            ->whereNotNull('relationship_verified_at')->exists();
    }

    private function ageThreshold(int $tenantId): int
    {
        $policy = AdmissionSetting::query()->where('tenant_id', $tenantId)->value('policy');
        $threshold = is_array($policy) ? ($policy['guardian_age_threshold'] ?? 18) : 18;

        return is_numeric($threshold) && (int) $threshold >= 1 && (int) $threshold <= 21 ? (int) $threshold : 18;
    }

    private function defaultMailerIsUnsafe(): bool
    {
        $default = (string) config('mail.default', '');
        $inspect = function (string $name, int $depth = 0) use (&$inspect): bool {
            if ($depth > 4) {
                return true;
            }
            $mailer = config('mail.mailers.'.$name);
            if (! is_array($mailer)) {
                return true;
            }
            $transport = strtolower((string) ($mailer['transport'] ?? ''));
            if (in_array($transport, ['log', 'array', 'null', 'failover', 'roundrobin'], true)) {
                $children = $mailer['mailers'] ?? [];
                if (! is_array($children) || $children === []) {
                    return true;
                }
                foreach ($children as $child) {
                    if (! is_string($child) || $inspect($child, $depth + 1)) {
                        return true;
                    }
                }

                return false;
            }

            return $transport === '';
        };

        return $inspect($default);
    }
}
