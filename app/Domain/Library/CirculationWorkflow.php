<?php

namespace App\Domain\Library;

use App\Models\LibraryCirculationEvent;
use App\Models\LibraryCopy;
use App\Models\LibraryLoan;
use App\Models\LibraryMembership;
use App\Models\LibraryReservation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CirculationWorkflow
{
    public function checkout(string $accession, string $memberNumber, User $actor): LibraryLoan
    {
        return DB::transaction(function () use ($accession, $memberNumber, $actor): LibraryLoan {
            $copy = LibraryCopy::query()->where('accession_number', $accession)->lockForUpdate()->firstOrFail();
            $membership = LibraryMembership::query()->where('member_number', $memberNumber)->lockForUpdate()->firstOrFail();
            $location = $copy->location()->firstOrFail();
            $this->assertOperationalScope($location->campus_id);
            $this->expireReadyHolds($actor, (int) $location->getKey(), (int) $copy->resource_id);
            $this->assertEligible($membership, $location->getKey());
            $activeCount = LibraryLoan::query()->where('library_membership_id', $membership->getKey())->where('status', 'active')->count();
            if ($activeCount >= (int) $location->max_active_loans) {
                throw ValidationException::withMessages(['member_number' => 'The member has reached the active-loan limit for this library.']);
            }
            $readyHold = LibraryReservation::query()->where('resource_id', $copy->resource_id)->where('copy_id', $copy->getKey())->where('status', 'ready')
                ->orderBy('placed_at')->lockForUpdate()->first();
            if ($readyHold && (int) $readyHold->library_membership_id !== (int) $membership->getKey()) {
                throw ValidationException::withMessages(['accession_number' => 'This copy is held for the next member in the reservation queue.']);
            }
            if ($copy->status !== 'available' && ! ($copy->status === 'reserved' && $readyHold && (int) $readyHold->library_membership_id === (int) $membership->getKey())) {
                throw ValidationException::withMessages(['accession_number' => 'This copy is not available for checkout.']);
            }
            if (! $readyHold && LibraryReservation::query()->where('resource_id', $copy->resource_id)->where('status', 'queued')
                ->whereHas('libraryMembership', fn ($query) => $query->where('library_location_id', $location->getKey()))->exists()) {
                throw ValidationException::withMessages(['accession_number' => 'A reservation is waiting for this resource at this location.']);
            }
            $now = now();
            $loan = LibraryLoan::query()->create([
                'copy_id' => $copy->getKey(), 'library_membership_id' => $membership->getKey(),
                'issued_by' => $actor->getKey(), 'checked_out_at' => $now,
                'due_at' => Carbon::parse($now)->addDays((int) $location->loan_days)->endOfDay(),
                'status' => 'active',
            ]);
            $copy->update(['status' => 'issued']);
            if ($readyHold) {
                $readyHold->update(['status' => 'fulfilled', 'fulfilled_at' => $now]);
            }
            $this->event($actor, 'checkout', $loan->getKey(), null, ['copy_id' => $copy->getKey()]);

            return $loan;
        }, 3);
    }

    public function returnCopy(int $loanId, User $actor, ?string $condition = null): LibraryLoan
    {
        return DB::transaction(function () use ($loanId, $actor, $condition): LibraryLoan {
            $loan = LibraryLoan::query()->whereKey($loanId)->lockForUpdate()->firstOrFail();
            $copy = LibraryCopy::query()->whereKey($loan->copy_id)->lockForUpdate()->firstOrFail();
            $location = $copy->location()->firstOrFail();
            $this->assertOperationalScope($location->campus_id);
            $this->expireReadyHolds($actor, (int) $location->getKey(), (int) $copy->resource_id);
            if ($loan->status !== 'active' || $copy->status !== 'issued') {
                throw ValidationException::withMessages(['loan' => 'This loan has already been returned or is no longer active.']);
            }
            $now = now();
            $loan->update(['status' => 'returned', 'returned_at' => $now, 'returned_by' => $actor->getKey()]);
            if ($condition !== null) {
                $copy->condition = $condition;
            }
            $next = LibraryReservation::query()->where('resource_id', $copy->resource_id)->where('status', 'queued')
                ->whereHas('libraryMembership', fn ($query) => $query->where('library_location_id', $copy->library_location_id))
                ->orderBy('placed_at')->orderBy('id')->lockForUpdate()->first();
            if ($next) {
                $next->update(['status' => 'ready', 'copy_id' => $copy->getKey(), 'expires_at' => Carbon::parse($now)->addHours(48)]);
                $copy->status = 'reserved';
                $this->event($actor, 'reservation_ready', null, $next->getKey(), ['copy_id' => $copy->getKey()]);
            } else {
                $copy->status = 'available';
            }
            $copy->save();
            $this->event($actor, 'return', $loan->getKey(), null, ['condition' => $condition]);

            return $loan;
        }, 3);
    }

    public function renew(int $loanId, User $actor): LibraryLoan
    {
        return DB::transaction(function () use ($loanId, $actor): LibraryLoan {
            $loan = LibraryLoan::query()->whereKey($loanId)->lockForUpdate()->firstOrFail();
            $copy = LibraryCopy::query()->whereKey($loan->copy_id)->lockForUpdate()->firstOrFail();
            $location = $copy->location()->firstOrFail();
            $this->assertOperationalScope($location->campus_id);
            $this->expireReadyHolds($actor, (int) $location->getKey(), (int) $copy->resource_id);
            $membership = LibraryMembership::query()->whereKey($loan->library_membership_id)->firstOrFail();
            $this->assertEligible($membership, (int) $location->getKey());
            if ($loan->status !== 'active' || ! $location->allow_renewals || $loan->renewal_count >= $location->renewal_limit) {
                throw ValidationException::withMessages(['loan' => 'This loan is not eligible for renewal.']);
            }
            $hasQueue = LibraryReservation::query()->where('resource_id', $copy->resource_id)->whereIn('status', ['queued', 'ready'])
                ->whereHas('libraryMembership', fn ($query) => $query->where('library_location_id', $location->getKey()))->exists();
            if ($hasQueue) {
                throw ValidationException::withMessages(['loan' => 'A reservation is waiting for this resource, so it cannot be renewed.']);
            }
            $loan->due_at = Carbon::parse($loan->due_at)->addDays((int) $location->loan_days);
            $loan->renewal_count++;
            $loan->save();
            $this->event($actor, 'renewal', $loan->getKey(), null, ['renewal_count' => $loan->renewal_count, 'due_at' => $loan->due_at->toIso8601String()]);

            return $loan;
        }, 3);
    }

    public function reserve(int $resourceId, int $membershipId, User $actor): LibraryReservation
    {
        return DB::transaction(function () use ($resourceId, $membershipId, $actor): LibraryReservation {
            $resource = \App\Models\LibraryResource::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
            if ($resource->status !== 'active') {
                throw ValidationException::withMessages(['reservation' => 'This catalog resource is not available for reservations.']);
            }
            $membership = LibraryMembership::query()->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            $this->assertEligible($membership, (int) $membership->library_location_id);
            $location = $membership->location()->firstOrFail();
            $this->assertOperationalScope($location->campus_id);
            $activeCount = LibraryReservation::query()->where('library_membership_id', $membershipId)->whereIn('status', ['queued', 'ready'])->count();
            if ($activeCount >= (int) $location->reservation_limit) {
                throw ValidationException::withMessages(['reservation' => 'The member has reached the reservation limit.']);
            }
            if (LibraryReservation::query()->where('resource_id', $resourceId)->where('library_membership_id', $membershipId)->whereIn('status', ['queued', 'ready'])->exists()) {
                throw ValidationException::withMessages(['reservation' => 'This member already has an active reservation for the resource.']);
            }
            $availableCopy = LibraryCopy::query()->where('resource_id', $resourceId)->where('library_location_id', $location->getKey())
                ->where('status', 'available')->orderBy('id')->lockForUpdate()->first();
            if ($availableCopy) {
                throw ValidationException::withMessages(['reservation' => 'A copy is available at this location; check it out instead.']);
            }
            $reservation = LibraryReservation::query()->create([
                'resource_id' => $resource->getKey(), 'library_membership_id' => $membershipId,
                'status' => 'queued', 'placed_at' => now(),
            ]);
            $this->event($actor, 'reservation_placed', null, $reservation->getKey());

            return $reservation;
        }, 3);
    }

    public function cancelReservation(int $reservationId, User $actor): LibraryReservation
    {
        return DB::transaction(function () use ($reservationId, $actor): LibraryReservation {
            $reservation = LibraryReservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
            $libraryMembership = $reservation->libraryMembership()->firstOrFail();
            $this->assertOperationalScope((int) $libraryMembership->location()->value('campus_id'));
            $this->expireReadyHolds($actor, (int) $libraryMembership->library_location_id, (int) $reservation->resource_id);
            $reservation->refresh();
            if ($reservation->status === 'expired') {
                return $reservation;
            }
            if (! in_array($reservation->status, ['queued', 'ready'], true)) {
                throw ValidationException::withMessages(['reservation' => 'This reservation can no longer be cancelled.']);
            }
            $copy = $reservation->copy_id ? LibraryCopy::query()->whereKey($reservation->copy_id)->lockForUpdate()->firstOrFail() : null;
            $reservation->update(['status' => 'cancelled', 'copy_id' => null]);
            if ($copy) {
                $next = LibraryReservation::query()->where('resource_id', $reservation->resource_id)->where('status', 'queued')
                    ->whereHas('libraryMembership', fn ($query) => $query->where('library_location_id', $copy->library_location_id))
                    ->orderBy('placed_at')->orderBy('id')->lockForUpdate()->first();
                if ($next) {
                    $next->update(['status' => 'ready', 'copy_id' => $copy->getKey(), 'expires_at' => now()->addHours(48)]);
                    $copy->update(['status' => 'reserved']);
                    $this->event($actor, 'reservation_ready', null, $next->getKey(), ['copy_id' => $copy->getKey()]);
                } else {
                    $copy->update(['status' => 'available']);
                }
            }
            $this->event($actor, 'reservation_cancelled', null, $reservation->getKey());

            return $reservation;
        }, 3);
    }

    private function assertEligible(LibraryMembership $membership, int $locationId): void
    {
        if ($membership->status !== 'active' || ($membership->expires_on && $membership->expires_on->endOfDay()->isPast())) {
            throw ValidationException::withMessages(['member_number' => 'The library membership is inactive or expired.']);
        }
        if ((int) $membership->library_location_id !== $locationId) {
            throw ValidationException::withMessages(['member_number' => 'This membership belongs to a different library location.']);
        }
        $account = TenantMembership::query()->whereKey($membership->membership_id)->firstOrFail();
        if ($account->status !== 'active') {
            throw ValidationException::withMessages(['member_number' => 'The linked institution membership is inactive.']);
        }
    }

    private function assertOperationalScope(?int $campusId): void
    {
        $context = app(\App\Domain\Tenancy\TenantContext::class);
        $actorCampus = $context->membership()->campus_id;
        abort_if($actorCampus !== null && (int) $actorCampus !== (int) $campusId, 404);
    }

    /** @param array<string, mixed>|null $details */
    private function event(User $actor, string $type, ?int $loanId, ?int $reservationId, ?array $details = null): void
    {
        LibraryCirculationEvent::query()->create([
            'loan_id' => $loanId, 'reservation_id' => $reservationId,
            'actor_id' => $actor->getKey(), 'event_type' => $type, 'details' => $details,
            'occurred_at' => now(),
        ]);
    }

    private function expireReadyHolds(User $actor, int $locationId, int $resourceId): void
    {
        $expired = LibraryReservation::query()->where('status', 'ready')->where('expires_at', '<', now())
            ->where('resource_id', $resourceId)
            ->whereHas('libraryMembership', fn ($query) => $query->where('library_location_id', $locationId))
            ->lockForUpdate()->get();
        foreach ($expired as $reservation) {
            if ($reservation->copy_id) {
                $copy = LibraryCopy::query()->whereKey($reservation->copy_id)->lockForUpdate()->first();
                if ($copy && $copy->status === 'reserved') {
                    $next = LibraryReservation::query()->where('resource_id', $reservation->resource_id)->where('status', 'queued')
                        ->whereHas('libraryMembership', fn ($query) => $query->where('library_location_id', $copy->library_location_id))
                        ->orderBy('placed_at')->orderBy('id')->lockForUpdate()->first();
                    if ($next) {
                        $next->update(['status' => 'ready', 'copy_id' => $copy->getKey(), 'expires_at' => now()->addHours(48)]);
                        $copy->update(['status' => 'reserved']);
                        $this->event($actor, 'reservation_ready', null, $next->getKey(), ['copy_id' => $copy->getKey()]);
                    } else {
                        $copy->update(['status' => 'available']);
                    }
                }
            }
            $reservation->update(['status' => 'expired', 'copy_id' => null]);
            $this->event($actor, 'reservation_expired', null, $reservation->getKey());
        }
    }
}
