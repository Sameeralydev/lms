<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Library\CirculationWorkflow;
use App\Domain\Tenancy\TenantContext;
use App\Models\Campus;
use App\Models\LibraryCopy;
use App\Models\LibraryLoan;
use App\Models\LibraryLocation;
use App\Models\LibraryMembership;
use App\Models\LibraryReservation;
use App\Models\LibraryResource;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LibraryController
{
    public function index(Request $request, Tenant $tenant, TenantContext $context): Response
    {
        $actor = $context->membership();
        abort_unless($actor->hasPermission('library.view') || $actor->hasPermission('library.member.view-own'), 403);
        $canManage = $actor->hasPermission('library.manage');
        $canManageInventory = $actor->hasPermission('library.inventory.manage') || $canManage;
        $canCirculate = $actor->hasPermission('library.circulate');
        $canManageMembers = $actor->hasPermission('library.members.manage');
        $ownMembership = LibraryMembership::query()->where('membership_id', $actor->getKey())->first();
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'category' => ['nullable', 'string', 'max:120']]);

        $resourceQuery = LibraryResource::query()->where('status', 'active')
            ->withCount(['copies as copies_total' => fn (Builder $q) => $this->scopeCopies($q, $actor->campus_id),
                'copies as copies_available' => fn (Builder $q) => $this->scopeCopies($q, $actor->campus_id)->where('status', 'available')]);
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $resourceQuery->where(fn (Builder $q) => $q->where('title', 'like', '%'.$term.'%')->orWhere('authors', 'like', '%'.$term.'%')->orWhere('isbn', 'like', '%'.$term.'%'));
        }
        if (! empty($filters['category'])) $resourceQuery->where('category', $filters['category']);

        $loanQuery = LibraryLoan::query()->with(['copy.resource', 'libraryMembership.account.user', 'copy.location'])->where('status', 'active');
        $reservationQuery = LibraryReservation::query()->with(['resource', 'libraryMembership.account.user', 'copy'])->whereIn('status', ['queued', 'ready']);
        $historyQuery = \App\Models\LibraryCirculationEvent::query()->with(['actor:id,name', 'loan.copy.resource', 'loan.libraryMembership', 'reservation.resource', 'reservation.libraryMembership'])
            ->where(fn (Builder $q) => $q->whereNotNull('loan_id')->orWhereNotNull('reservation_id'));
        if (! $canCirculate) {
            if ($ownMembership) {
                $loanQuery->where('library_membership_id', $ownMembership->getKey());
                $reservationQuery->where('library_membership_id', $ownMembership->getKey());
                $historyQuery->where(fn (Builder $q) => $q->whereHas('loan', fn (Builder $loan) => $loan->where('library_membership_id', $ownMembership->getKey()))
                    ->orWhereHas('reservation', fn (Builder $hold) => $hold->where('library_membership_id', $ownMembership->getKey())));
            } else {
                $loanQuery->whereRaw('1 = 0');
                $reservationQuery->whereRaw('1 = 0');
                $historyQuery->whereRaw('1 = 0');
            }
        } else {
            $loanQuery->whereHas('copy.location', fn (Builder $q) => $q->when($actor->campus_id !== null, fn (Builder $scoped) => $scoped->where('campus_id', $actor->campus_id)));
            $reservationQuery->whereHas('libraryMembership.location', fn (Builder $q) => $q->when($actor->campus_id !== null, fn (Builder $scoped) => $scoped->where('campus_id', $actor->campus_id)));
            $historyQuery->where(fn (Builder $q) => $q->whereHas('loan.copy.location', fn (Builder $location) => $location->when($actor->campus_id !== null, fn (Builder $scoped) => $scoped->where('campus_id', $actor->campus_id)))
                ->orWhereHas('reservation.libraryMembership.location', fn (Builder $location) => $location->when($actor->campus_id !== null, fn (Builder $scoped) => $scoped->where('campus_id', $actor->campus_id))));
        }

        return Inertia::render('workspace/library/index', [
            'tenant' => ['slug' => $tenant->slug],
            'resources' => $resourceQuery->orderBy('title')->paginate(20)->withQueryString()->through(fn (LibraryResource $item): array => [
                'id' => (int) $item->getKey(), 'title' => $item->title, 'subtitle' => $item->subtitle, 'authors' => $item->authors, 'isbn' => $item->isbn,
                'publisher' => $item->publisher, 'edition' => $item->edition, 'language' => $item->language,
                'category' => $item->category, 'subject' => $item->subject, 'classification' => $item->classification,
                'description' => $item->description, 'publication_year' => $item->publication_year,
                'total_copies' => (int) $item->copies_total, 'available_copies' => (int) $item->copies_available,
            ]),
            'resourceOptions' => $canManageInventory ? LibraryResource::query()->where('status', 'active')->orderBy('title')->limit(500)->get(['id', 'title'])->map(fn (LibraryResource $item): array => ['id' => (int) $item->getKey(), 'title' => $item->title])->values() : [],
            'loans' => $loanQuery->orderBy('due_at')->limit(100)->get()->map(fn (LibraryLoan $loan): array => [
                'id' => (int) $loan->getKey(), 'copy_id' => (int) $loan->copy_id, 'accession_number' => $loan->copy?->accession_number,
                'title' => $loan->copy?->resource?->title, 'member_number' => $loan->libraryMembership?->member_number,
                'borrower' => $loan->libraryMembership?->account?->user?->name, 'due_at' => $loan->due_at?->toDateString(),
                'overdue' => $loan->due_at?->isPast() ?? false, 'renewal_count' => (int) $loan->renewal_count,
            ])->values(),
            'reservations' => $canCirculate ? $reservationQuery->orderBy('placed_at')->limit(100)->get()->map(fn (LibraryReservation $hold): array => [
                'id' => (int) $hold->getKey(), 'title' => $hold->resource?->title, 'member_number' => $hold->libraryMembership?->member_number,
                'borrower' => $hold->libraryMembership?->account?->user?->name, 'status' => $hold->status,
                'expires_at' => $hold->expires_at?->toDateTimeString(),
            ])->values() : $reservationQuery->orderBy('placed_at')->limit(100)->get()->map(fn (LibraryReservation $hold): array => [
                'id' => (int) $hold->getKey(), 'title' => $hold->resource?->title, 'member_number' => $hold->libraryMembership?->member_number,
                'borrower' => $hold->libraryMembership?->account?->user?->name, 'status' => $hold->status,
                'expires_at' => $hold->expires_at?->toDateTimeString(),
            ])->values(),
            'history' => $historyQuery->orderByDesc('occurred_at')->limit(50)->get()->map(fn (\App\Models\LibraryCirculationEvent $event): array => [
                'event' => $event->event_type, 'at' => $event->occurred_at?->toDateTimeString(), 'actor' => $event->actor?->name,
                'title' => $event->loan?->copy?->resource?->title ?? $event->reservation?->resource?->title,
                'member_number' => $event->loan?->libraryMembership?->member_number ?? $event->reservation?->libraryMembership?->member_number,
            ])->values(),
            'copies' => $canManageInventory ? LibraryCopy::query()->with(['resource:id,title', 'location:id,name,campus_id'])
                ->when($actor->campus_id !== null, fn (Builder $q) => $q->whereHas('location', fn (Builder $l) => $l->where('campus_id', $actor->campus_id)))
                ->orderBy('accession_number')->limit(250)->get()->map(fn (LibraryCopy $copy): array => [
                    'id' => (int) $copy->getKey(), 'accession_number' => $copy->accession_number, 'title' => $copy->resource?->title,
                    'location' => $copy->location?->name, 'condition' => $copy->condition, 'status' => $copy->status,
                ])->values() : [],
            'locations' => LibraryLocation::query()->where('status', 'active')->when($actor->campus_id !== null, fn (Builder $q) => $q->where('campus_id', $actor->campus_id))->orderBy('name')->get()->map(fn (LibraryLocation $location): array => [
                'id' => (int) $location->getKey(), 'name' => $location->name, 'campus_id' => $location->campus_id,
                'loan_days' => (int) $location->loan_days, 'max_active_loans' => (int) $location->max_active_loans,
                'renewal_limit' => (int) $location->renewal_limit, 'reservation_limit' => (int) $location->reservation_limit,
            ])->values(),
            'members' => $canManageMembers ? LibraryMembership::query()->with('account.user')->when($actor->campus_id !== null, fn (Builder $q) => $q->whereHas('location', fn (Builder $l) => $l->where('campus_id', $actor->campus_id)))->orderBy('member_number')->limit(200)->get()->map(fn (LibraryMembership $member): array => [
                'id' => (int) $member->getKey(), 'member_number' => $member->member_number, 'name' => $member->account?->user?->name,
                'status' => $member->status, 'location_id' => (int) $member->library_location_id,
            ])->values() : [],
            'eligibleAccounts' => $canManageMembers ? TenantMembership::query()->where('status', 'active')
                ->whereNotIn('id', LibraryMembership::query()->select('membership_id'))
                ->when($actor->campus_id !== null, fn (Builder $q) => $q->where(function (Builder $scope) use ($actor): void { $scope->whereNull('campus_id')->orWhere('campus_id', $actor->campus_id); }))
                ->with('user:id,name')->orderBy('id')->limit(250)->get()->map(fn (TenantMembership $account): array => ['id' => (int) $account->getKey(), 'name' => $account->user?->name ?? 'Institution member'])->values() : [],
            'campuses' => $actor->campus_id === null ? Campus::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn (Campus $campus): array => ['id' => (int) $campus->getKey(), 'name' => $campus->name])->values() : [],
            'categories' => LibraryResource::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'filters' => ['q' => $filters['q'] ?? '', 'category' => $filters['category'] ?? ''],
            'permissions' => ['manage' => $canManage, 'manageInventory' => $canManageInventory, 'circulate' => $canCirculate, 'manageMembers' => $canManageMembers, 'managePolicy' => $actor->hasPermission('library.policy.manage'), 'ownMembershipId' => $ownMembership?->getKey()],
            'campusScope' => $actor->campus_id,
            'stats' => [
                'titles' => (clone $resourceQuery)->count(),
                'active_loans' => (clone $loanQuery)->count(),
                'overdue_loans' => (clone $loanQuery)->where('due_at', '<', now())->count(),
                'reservations' => $canCirculate ? (clone $reservationQuery)->count() : (clone $reservationQuery)->where('library_membership_id', $ownMembership?->getKey())->count(),
            ],
        ]);
    }

    public function storeLocation(Request $request, TenantContext $context): RedirectResponse
    {
        $actor = $context->membership();
        abort_unless($actor->hasPermission('library.policy.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'code' => ['required', 'alpha_dash:ascii', 'max:40'],
            'campus_id' => ['nullable', 'integer'], 'loan_days' => ['required', 'integer', 'between:1,365'],
            'max_active_loans' => ['required', 'integer', 'between:1,50'], 'renewal_limit' => ['required', 'integer', 'between:0,20'],
            'reservation_limit' => ['required', 'integer', 'between:0,50'], 'allow_renewals' => ['required', 'boolean'],
        ]);
        if ($actor->campus_id !== null) $data['campus_id'] = $actor->campus_id;
        $data['institution_id'] = $actor->institution_id;
        $data['status'] = 'active';
        LibraryLocation::query()->create($data);

        return back()->with('status', 'Library location and circulation policy created.');
    }

    public function updateLocation(Request $request, int $locationId, TenantContext $context): RedirectResponse
    {
        $actor = $context->membership();
        abort_unless($actor->hasPermission('library.policy.manage'), 403);
        $location = LibraryLocation::query()->whereKey($locationId)->firstOrFail();
        $this->assertLocationScope((int) $location->getKey(), $actor->campus_id);
        $data = $request->validate([
            'loan_days' => ['required', 'integer', 'between:1,365'], 'max_active_loans' => ['required', 'integer', 'between:1,50'],
            'renewal_limit' => ['required', 'integer', 'between:0,20'], 'reservation_limit' => ['required', 'integer', 'between:0,50'],
            'allow_renewals' => ['required', 'boolean'],
        ]);
        $location->update($data);

        return back()->with('status', 'Library policy updated.');
    }

    public function storeResource(Request $request, TenantContext $context): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('library.manage'), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:240'], 'subtitle' => ['nullable', 'string', 'max:240'],
            'authors' => ['nullable', 'string', 'max:500'], 'isbn' => ['nullable', 'string', 'max:20'],
            'publisher' => ['nullable', 'string', 'max:180'], 'publication_year' => ['nullable', 'integer', 'between:1000,2200'],
            'edition' => ['nullable', 'string', 'max:80'], 'language' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:120'], 'subject' => ['nullable', 'string', 'max:160'],
            'classification' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['status'] = 'active';
        LibraryResource::query()->create($data);

        return back()->with('status', 'Catalog resource added.');
    }

    public function updateResource(Request $request, int $resourceId, TenantContext $context): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('library.manage'), 403);
        abort_if($context->membership()->campus_id !== null, 403, 'Catalog changes require institution-wide library permission.');
        $resource = LibraryResource::query()->whereKey($resourceId)->firstOrFail();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:240'], 'subtitle' => ['nullable', 'string', 'max:240'],
            'authors' => ['nullable', 'string', 'max:500'], 'isbn' => ['nullable', 'string', 'max:20'],
            'publisher' => ['nullable', 'string', 'max:180'], 'publication_year' => ['nullable', 'integer', 'between:1000,2200'],
            'edition' => ['nullable', 'string', 'max:80'], 'language' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:120'], 'subject' => ['nullable', 'string', 'max:160'],
            'classification' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $resource->update($data);

        return back()->with('status', 'Catalog record updated.');
    }

    public function resourceStatus(Request $request, int $resourceId, TenantContext $context): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('library.manage'), 403);
        abort_if($context->membership()->campus_id !== null, 403, 'Catalog changes require institution-wide library permission.');
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'archived'])]]);
        $resource = LibraryResource::query()->whereKey($resourceId)->firstOrFail();
        if ($data['status'] === 'archived') {
            abort_if($resource->copies()->whereIn('status', ['issued', 'reserved'])->exists(), 422, 'A title with an active loan or reservation cannot be archived.');
            abort_if(LibraryLoan::query()->where('status', 'active')->whereHas('copy', fn (Builder $copy) => $copy->where('resource_id', $resource->getKey()))->exists(), 422, 'A title with active loans cannot be archived.');
            abort_if(LibraryReservation::query()->where('resource_id', $resource->getKey())->whereIn('status', ['queued', 'ready'])->exists(), 422, 'A title with an active reservation cannot be archived.');
        }
        $resource->update($data);

        return back()->with('status', 'Catalog status updated.');
    }

    public function storeCopy(Request $request, TenantContext $context): RedirectResponse
    {
        $actor = $context->membership();
        abort_unless($actor->hasPermission('library.inventory.manage') || $actor->hasPermission('library.manage'), 403);
        $data = $request->validate([
            'resource_id' => ['required', 'integer'], 'library_location_id' => ['required', 'integer'],
            'accession_number' => ['required', 'string', 'max:64'], 'barcode' => ['nullable', 'string', 'max:80'],
            'shelf_location' => ['nullable', 'string', 'max:80'], 'condition' => ['required', Rule::in(['new', 'good', 'worn', 'damaged'])],
            'acquired_on' => ['nullable', 'date'],
        ]);
        $location = LibraryLocation::query()->whereKey($data['library_location_id'])->where('status', 'active')->firstOrFail();
        abort_if($actor->campus_id !== null && (int) $location->campus_id !== (int) $actor->campus_id, 403);
        LibraryResource::query()->whereKey($data['resource_id'])->firstOrFail();
        $data['status'] = 'available';
        LibraryCopy::query()->create($data);

        return back()->with('status', 'Physical copy registered.');
    }

    public function updateCopy(Request $request, int $copyId, TenantContext $context): RedirectResponse
    {
        $actor = $context->membership();
        abort_unless($actor->hasPermission('library.inventory.manage') || $actor->hasPermission('library.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['available', 'lost', 'damaged', 'maintenance', 'withdrawn'])], 'condition' => ['required', Rule::in(['new', 'good', 'worn', 'damaged'])]]);
        $copy = LibraryCopy::query()->whereKey($copyId)->firstOrFail();
        $this->assertLocationScope((int) $copy->library_location_id, $actor->campus_id);
        abort_if(in_array($copy->status, ['issued', 'reserved'], true), 422, 'A checked-out or reserved copy must be returned or released before inventory adjustment.');
        $from = ['status' => $copy->status, 'condition' => $copy->condition];
        $copy->update($data);
        \App\Models\LibraryCirculationEvent::query()->create([
            'actor_id' => request()->user()->getKey(), 'event_type' => 'inventory_adjustment',
            'details' => ['copy_id' => $copy->getKey(), 'from' => $from, 'to' => $data], 'occurred_at' => now(),
        ]);

        return back()->with('status', 'Copy inventory status updated and recorded.');
    }

    public function storeMember(Request $request, TenantContext $context): RedirectResponse
    {
        $actor = $context->membership();
        abort_unless($actor->hasPermission('library.members.manage'), 403);
        $data = $request->validate([
            'membership_id' => ['required', 'integer'], 'library_location_id' => ['required', 'integer'],
            'member_number' => ['required', 'string', 'max:64'], 'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $location = LibraryLocation::query()->whereKey($data['library_location_id'])->firstOrFail();
        abort_if($actor->campus_id !== null && (int) $location->campus_id !== (int) $actor->campus_id, 403);
        $account = TenantMembership::query()->whereKey($data['membership_id'])->where('status', 'active')->firstOrFail();
        abort_if($account->campus_id !== null && (int) $account->campus_id !== (int) $location->campus_id, 422, 'Choose a library at the member’s campus.');
        $data['status'] = 'active';
        LibraryMembership::query()->create($data);

        return back()->with('status', 'Library membership activated.');
    }

    public function setMemberStatus(Request $request, int $libraryMembershipId, TenantContext $context): RedirectResponse
    {
        $actor = $context->membership();
        abort_unless($actor->hasPermission('library.members.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'expired'])]]);
        $member = LibraryMembership::query()->whereKey($libraryMembershipId)->firstOrFail();
        $this->assertLocationScope($member->library_location_id, $actor->campus_id);
        $member->update($data);

        return back()->with('status', 'Library membership status updated.');
    }

    public function checkout(Request $request, CirculationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['accession_number' => ['required', 'string', 'max:64'], 'member_number' => ['required', 'string', 'max:64']]);
        $workflow->checkout($data['accession_number'], $data['member_number'], $request->user());

        return back()->with('status', 'Checkout recorded.');
    }

    public function return(Request $request, int $loanId, CirculationWorkflow $workflow): RedirectResponse
    {
        $data = $request->validate(['condition' => ['nullable', Rule::in(['new', 'good', 'worn', 'damaged'])]]);
        $workflow->returnCopy($loanId, $request->user(), $data['condition'] ?? null);

        return back()->with('status', 'Return recorded.');
    }

    public function renew(Request $request, int $loanId, TenantContext $context, CirculationWorkflow $workflow): RedirectResponse
    {
        $loan = LibraryLoan::query()->whereKey($loanId)->firstOrFail();
        $isOwn = (int) $loan->libraryMembership()->value('membership_id') === (int) $context->membership()->getKey();
        abort_unless($isOwn || $context->membership()->hasPermission('library.circulate'), 403);
        $workflow->renew($loanId, $request->user());

        return back()->with('status', 'Loan renewed.');
    }

    public function reserve(Request $request, int $resourceId, TenantContext $context, CirculationWorkflow $workflow): RedirectResponse
    {
        abort_unless($context->membership()->hasPermission('library.member.view-own'), 403);
        $member = LibraryMembership::query()->where('membership_id', $context->membership()->getKey())->firstOrFail();
        $workflow->reserve($resourceId, (int) $member->getKey(), $request->user());

        return back()->with('status', 'Reservation placed in the queue.');
    }

    public function cancelReservation(Request $request, int $reservationId, TenantContext $context, CirculationWorkflow $workflow): RedirectResponse
    {
        $reservation = LibraryReservation::query()->whereKey($reservationId)->firstOrFail();
        $isOwn = (int) $reservation->libraryMembership()->value('membership_id') === (int) $context->membership()->getKey();
        abort_unless($isOwn || $context->membership()->hasPermission('library.circulate'), 403);
        $workflow->cancelReservation($reservationId, $request->user());

        return back()->with('status', 'Reservation cancelled.');
    }

    private function assertLocationScope(int $locationId, ?int $campusId): void
    {
        abort_if($campusId !== null && ! LibraryLocation::query()->whereKey($locationId)->where('campus_id', $campusId)->exists(), 404);
    }

    private function scopeCopies(Builder $query, ?int $campusId): Builder
    {
        return $query->whereHas('location', fn (Builder $location) => $location->when($campusId !== null, fn (Builder $scoped) => $scoped->where('campus_id', $campusId)));
    }
}
