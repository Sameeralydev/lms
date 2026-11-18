<?php

namespace App\Domain\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Disabled-by-default, policy-driven preview calculator; it never mutates invoices. */
final class BillingProrationCalculator
{
    /**
     * @param  array{enabled:bool,method:string,effective_from:string,effective_until:string|null}  $policy
     * @return array{method:string,full_amount:string,eligible_days:int,period_days:int,prorated_amount:string,eligible_from:string,eligible_until:string}
     */
    public function preview(string $amount, string $periodStart, string $periodEnd, string $chargeStart, string $chargeEnd, array $policy): array
    {
        if (! $policy['enabled'] || $policy['method'] !== 'calendar_days') {
            throw ValidationException::withMessages(['policy' => 'Enable a supported proration method before requesting a preview.']);
        }
        $periodFrom = Carbon::parse($periodStart)->startOfDay();
        $periodTo = Carbon::parse($periodEnd)->startOfDay();
        $chargeFrom = Carbon::parse($chargeStart)->startOfDay();
        $chargeTo = Carbon::parse($chargeEnd)->startOfDay();
        $policyFrom = Carbon::parse($policy['effective_from'])->startOfDay();
        $policyTo = $policy['effective_until'] === null ? null : Carbon::parse($policy['effective_until'])->startOfDay();
        if ($periodTo->lt($periodFrom) || $chargeTo->lt($chargeFrom) || ($policyTo !== null && $policyTo->lt($policyFrom))) {
            throw ValidationException::withMessages(['period_end' => 'The selected billing, charge, or policy dates are invalid.']);
        }

        $eligibleFrom = $periodFrom->max($chargeFrom)->max($policyFrom);
        $eligibleTo = $periodTo->min($chargeTo)->min($policyTo ?? $periodTo);
        if ($eligibleTo->lt($eligibleFrom)) {
            throw ValidationException::withMessages(['charge_start' => 'The active proration policy and charge dates do not overlap the billing period.']);
        }
        $periodDays = (int) $periodFrom->diffInDays($periodTo) + 1;
        $eligibleDays = (int) $eligibleFrom->diffInDays($eligibleTo) + 1;
        $fullMinor = BillingMoney::toMinor($amount);
        if ($fullMinor < 1) {
            throw ValidationException::withMessages(['amount' => 'The full-period amount must be positive.']);
        }
        $denominator = $periodDays;
        $numerator = $fullMinor * $eligibleDays;
        $proratedMinor = intdiv($numerator + intdiv($denominator, 2), $denominator);

        return [
            'method' => $policy['method'], 'full_amount' => BillingMoney::fromMinor($fullMinor),
            'eligible_days' => $eligibleDays, 'period_days' => $periodDays, 'prorated_amount' => BillingMoney::fromMinor($proratedMinor),
            'eligible_from' => $eligibleFrom->toDateString(), 'eligible_until' => $eligibleTo->toDateString(),
        ];
    }
}
