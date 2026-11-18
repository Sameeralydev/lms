<?php

namespace App\Domain\Billing;

use App\Domain\Tenancy\TenantContext;
use App\Models\BillingRecurringPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class BillingRecurringProcessor
{
    public function __construct(private readonly TenantContext $context, private readonly BillingInvoiceIssuer $issuer) {}

    /**
     * Process due plans; failures remain retryable and no plan advances on failure.
     *
     * @return array{processed:int,failed:int}
     */
    public function processDue(): array
    {
        $processed = 0;
        $failed = 0;
        DB::table('billing_recurring_plans')->where('status', 'active')->where('next_run_at', '<=', now())->orderBy('id')->limit(500)->pluck('id')->each(function ($id) use (&$processed, &$failed): void {
            try {
                if ($this->process((int) $id)) {
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $exception) {
                // Log only the stable exception class and plan ID; never log request/configuration payloads.
                logger()->warning('Recurring billing plan attempt failed.', ['plan_id' => (int) $id, 'exception_class' => $exception::class]);
                $plan = DB::table('billing_recurring_plans')->where('id', $id)->first(['tenant_id', 'period_start', 'period_end']);
                if ($plan !== null) {
                    $run = DB::table('billing_recurring_runs')->where('plan_id', $id)->where('period_start', $plan->period_start)->where('period_end', $plan->period_end)->first(['attempts', 'created_at']);
                    DB::table('billing_recurring_runs')->updateOrInsert(
                        ['tenant_id' => $plan->tenant_id, 'plan_id' => $id, 'period_start' => $plan->period_start, 'period_end' => $plan->period_end],
                        ['status' => 'failed', 'failure_code' => substr(hash('sha256', $exception::class), 0, 64), 'last_attempt_at' => now(),
                            'updated_at' => now(), 'created_at' => $run->created_at ?? now(), 'attempts' => (int) ($run->attempts ?? 0) + 1],
                    );
                }
                $failed++;
            } finally {
                $this->context->clear();
            }
        });

        return ['processed' => $processed, 'failed' => $failed];
    }

    private function process(int $id): bool
    {
        $planData = DB::table('billing_recurring_plans')->where('id', $id)->first();
        if ($planData === null) {
            return true;
        }
        $tenant = Tenant::query()->findOrFail((int) $planData->tenant_id);
        // created_by is immutable provenance, not a permanent execution dependency. Resolve
        // a currently authorized operator each time so staff turnover cannot strand a plan.
        $memberships = TenantMembership::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->getKey())
            ->where('institution_id', $planData->institution_id)
            ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $planData->campus_id))
            ->where('status', 'active')->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [(int) $planData->created_by])->get();
        $membership = null;
        foreach ($memberships as $candidate) {
            $this->context->set($tenant, $candidate);
            if ($candidate->hasPermission('billing.invoices.issue')) {
                $membership = $candidate;
                break;
            }
        }
        if ($membership === null) {
            $this->context->clear();
            throw new \RuntimeException('No active institution billing operator is authorized to issue recurring invoices.');
        }
        $actor = User::query()->findOrFail((int) $membership->user_id);

        return DB::transaction(function () use ($id, $actor): bool {
            $plan = BillingRecurringPlan::query()->lockForUpdate()->findOrFail($id);
            if ($plan->status !== 'active' || $plan->next_run_at->isFuture()) {
                return true;
            }
            $start = Carbon::parse($plan->period_start)->startOfDay();
            $end = Carbon::parse($plan->period_end)->startOfDay();
            $due = $end->copy()->addDays((int) $plan->due_days_after_period);
            $run = DB::table('billing_recurring_runs')->where('plan_id', $id)->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->first();
            if ($run?->status === 'completed') {
                return true;
            }
            try {
                $invoice = $this->issuer->issue([
                    'student_id' => (int) $plan->student_id, 'fee_structure_id' => (int) $plan->fee_structure_id,
                    'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'due_on' => $due->toDateString(),
                    'idempotency_key' => 'recurring:'.hash('sha256', $id.':'.$start->toDateString().':'.$end->toDateString()),
                ], $actor);
                DB::table('billing_recurring_runs')->updateOrInsert(
                    ['tenant_id' => $plan->tenant_id, 'plan_id' => $id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()],
                    ['invoice_id' => $invoice->getKey(), 'status' => 'completed', 'failure_code' => null, 'last_attempt_at' => now(), 'updated_at' => now(), 'created_at' => $run->created_at ?? now(), 'attempts' => (int) ($run->attempts ?? 0) + 1],
                );
                $months = match ($plan->frequency) {
                    'monthly' => 1, 'annual' => 12, default => throw new \LogicException('Unsupported recurring frequency.')
                };
                $nextStart = $start->copy()->addMonthsNoOverflow($months);
                $nextEnd = $nextStart->copy()->addMonthsNoOverflow($months)->subDay();
                $nextRun = Carbon::parse($nextEnd->toDateString(), $plan->timezone)->addDays((int) $plan->due_days_after_period)->startOfDay()->utc();
                $plan->forceFill(['period_start' => $nextStart->toDateString(), 'period_end' => $nextEnd->toDateString(), 'next_run_at' => $nextRun])->save();

                return true;
            } catch (\Throwable $exception) {
                DB::table('billing_recurring_runs')->updateOrInsert(
                    ['tenant_id' => $plan->tenant_id, 'plan_id' => $id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()],
                    ['status' => 'failed', 'failure_code' => substr(hash('sha256', $exception::class), 0, 64), 'last_attempt_at' => now(), 'updated_at' => now(), 'created_at' => $run->created_at ?? now(), 'attempts' => (int) ($run->attempts ?? 0) + 1],
                );
                logger()->warning('Recurring billing invoice generation failed.', ['plan_id' => $id, 'exception_class' => $exception::class]);

                return false;
            }
        }, 3);
    }
}
