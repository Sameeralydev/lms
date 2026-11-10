<?php

namespace App\Providers;

use App\Domain\Academic\Attendance\AttendanceOutboxDispatcher;
use App\Domain\Academic\Attendance\LocalAttendanceOutboxDispatcher;
use App\Domain\Admissions\AdmissionOutboxDispatcher;
use App\Domain\Admissions\Documents\AdmissionDocumentScanner;
use App\Domain\Admissions\Documents\ClamAvAdmissionDocumentScanner;
use App\Domain\Admissions\Documents\UnavailableAdmissionDocumentScanner;
use App\Domain\Admissions\LocalAdmissionOutboxDispatcher;
use App\Domain\Tenancy\TenantContext;
use App\Events\Admissions\AdmissionOutboxDispatched;
use App\Events\Attendance\AttendanceOutboxDispatched;
use App\Listeners\DeliverAttendanceNotification;
use App\Listeners\DeliverEnrollmentWaitlistNotification;
use App\Models\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AdmissionOutboxDispatcher::class, LocalAdmissionOutboxDispatcher::class);
        $this->app->bind(AttendanceOutboxDispatcher::class, LocalAttendanceOutboxDispatcher::class);
        $this->app->bind(AdmissionDocumentScanner::class, function (): AdmissionDocumentScanner {
            if (config('admissions.document_scanner') === 'clamav') {
                return new ClamAvAdmissionDocumentScanner(
                    (string) config('admissions.clamav_binary'),
                    (int) config('admissions.clamav_max_signature_age_hours', 48),
                    (string) config('admissions.clamav_timezone', config('app.timezone', 'UTC')),
                    (string) config('admissions.clamav_signature_directory', ''),
                    (string) config('admissions.clamav_signature_tool_binary', ''),
                    null,
                    (int) config('admissions.clamav_timeout', 120),
                );
            }

            return new UnavailableAdmissionDocumentScanner;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(AdmissionOutboxDispatched::class, DeliverEnrollmentWaitlistNotification::class);
        Event::listen(AttendanceOutboxDispatched::class, DeliverAttendanceNotification::class);
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext);

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('diagnostics', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())));
        RateLimiter::for('invitations', function (Request $request): array {
            $token = (string) $request->route('token', '');

            return [
                Limit::perMinute(10)->by('invitation-token:'.hash('sha256', $token)),
                Limit::perMinute(60)->by('invitation-ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('applicant.registration', fn (Request $request) => Limit::perMinute(4)->by($request->ip()));
        RateLimiter::for('applicant.application', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('applicant.guardian', fn (Request $request) => Limit::perHour(5)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('sis.documents.upload', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('academic.curriculum.import', function (Request $request): Limit {
            $tenant = $request->route('tenant');
            $tenantKey = $tenant instanceof Tenant ? (string) $tenant->getKey() : (string) $tenant;
            $identity = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute(10)->by($identity.'|'.$tenantKey);
        });
        RateLimiter::for('sis.family-search', function (Request $request): Limit {
            $tenant = $request->route('tenant');
            $tenantKey = $tenant instanceof Tenant ? (string) $tenant->getKey() : (string) $tenant;
            $identity = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute(30)->by($identity.'|'.$tenantKey);
        });
        RateLimiter::for('learning.media.upload', function (Request $request): Limit {
            $tenant = $request->route('tenant');
            $tenantKey = $tenant instanceof Tenant ? (string) $tenant->getKey() : (string) $tenant;
            $identity = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute(10)->by($identity.'|'.$tenantKey);
        });
    }
}
