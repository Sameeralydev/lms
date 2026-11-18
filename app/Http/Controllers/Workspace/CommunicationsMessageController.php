<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommunicationsConversation;
use App\Models\CommunicationsConversationMember;
use App\Models\CommunicationsMessage;
use App\Models\CommunicationsNotification;
use App\Models\CommunicationsNotificationPreference;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CommunicationsMessageController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, Tenant $tenant): Response
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.messages.view'), 403);

        $threads = CommunicationsConversationMember::query()
            ->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id)
            ->with(['conversation.members.user:id,name', 'conversation.latestMessage.author:id,name'])
            ->orderByDesc('updated_at')->paginate(20)->withQueryString()
            ->through(function (CommunicationsConversationMember $participant) use ($membership): array {
                $conversation = $participant->conversation;
                $other = $conversation->members->firstWhere('user_id', '!=', $membership->user_id);
                $latest = $conversation->latestMessage;

                return [
                    'id' => (int) $conversation->getKey(),
                    'peer' => $other?->user?->name ?? 'Institution member',
                    'preview' => $latest?->body,
                    'updated_at' => $latest?->created_at?->toIso8601String(),
                    'unread' => $conversation->messages()->where('sender_user_id', '!=', $membership->user_id)
                        ->when($participant->last_read_at, fn ($query) => $query->where('created_at', '>', $participant->last_read_at))
                        ->count(),
                ];
            });

        $recipients = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('status', 'active')
            ->where('user_id', '!=', $membership->user_id)
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.key', 'communications.messages.view'))
            ->when($membership->campus_id !== null, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('campus_id')->orWhere('campus_id', $membership->campus_id)))
            ->with(['user:id,name', 'roles:id,name'])
            ->orderBy('user_id')->limit(200)->get()
            ->unique('user_id')->map(static fn (TenantMembership $recipient): array => [
                'id' => (int) $recipient->user_id,
                'name' => $recipient->user?->name ?? 'Institution member',
                'roles' => $recipient->roles->pluck('name')->values()->all(),
            ])->values()->all();

        $selectedId = $request->integer('conversation');
        $selected = null;
        if ($selectedId > 0) {
            $participant = CommunicationsConversationMember::query()
                ->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id)
                ->where('conversation_id', $selectedId)
                ->with(['conversation.members.user:id,name'])
                ->firstOrFail();
            $participant->forceFill(['last_read_at' => now()])->save();
            CommunicationsNotification::query()->where('tenant_id', $tenant->getKey())->where('user_id', $membership->user_id)
                ->where('type', 'message')->where('context_id', $selectedId)->whereNull('read_at')->update(['read_at' => now()]);
            $other = $participant->conversation->members->firstWhere('user_id', '!=', $membership->user_id);
            $messages = CommunicationsMessage::query()->where('conversation_id', $participant->conversation->getKey())
                ->with('author:id,name')->latest()->limit(100)->get()->reverse()->values();
            $selected = [
                'id' => (int) $participant->conversation->getKey(),
                'peer' => $other?->user?->name ?? 'Institution member',
                'messages' => $messages->map(static fn ($message): array => [
                    'id' => (int) $message->getKey(), 'body' => $message->body,
                    'sender' => $message->author?->name ?? 'Institution member',
                    'mine' => (int) $message->sender_user_id === (int) $membership->user_id,
                    'sent_at' => $message->created_at?->toIso8601String(),
                ])->all(),
            ];
        }

        return Inertia::render('workspace/communications/messages', [
            'tenant' => ['slug' => $tenant->slug],
            'threads' => $threads,
            'recipients' => $recipients,
            'selected' => $selected,
            'canSend' => $membership->hasPermission('communications.messages.send'),
        ]);
    }

    public function start(Request $request, Tenant $tenant, AuditRecorder $audit): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.messages.send'), 403);
        $validated = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'different:'.$membership->user_id],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $recipient = $this->eligibleMember($tenant, $membership, (int) $validated['recipient_user_id']);
        $one = min((int) $membership->user_id, (int) $recipient->user_id);
        $two = max((int) $membership->user_id, (int) $recipient->user_id);

        $conversation = DB::transaction(function () use ($audit, $membership, $one, $recipient, $request, $tenant, $two, $validated): CommunicationsConversation {
            $conversation = CommunicationsConversation::query()->firstOrCreate([
                'tenant_id' => $tenant->getKey(), 'member_one_user_id' => $one, 'member_two_user_id' => $two,
            ]);
            foreach ([$one, $two] as $userId) {
                CommunicationsConversationMember::query()->firstOrCreate([
                    'tenant_id' => $tenant->getKey(), 'conversation_id' => $conversation->getKey(), 'user_id' => $userId,
                ]);
            }
            $message = $conversation->messages()->create(['sender_user_id' => $membership->user_id, 'body' => $validated['body']]);
            CommunicationsConversationMember::query()->where('tenant_id', $tenant->getKey())
                ->where('conversation_id', $conversation->getKey())->where('user_id', $membership->user_id)
                ->update(['last_read_at' => now(), 'updated_at' => now()]);
            CommunicationsConversationMember::query()->where('tenant_id', $tenant->getKey())
                ->where('conversation_id', $conversation->getKey())->update(['updated_at' => now()]);
            $conversation->touch();
            if (CommunicationsNotificationPreference::allows((int) $tenant->getKey(), (int) $recipient->user_id, 'message')) {
                CommunicationsNotification::query()->create([
                    'user_id' => $recipient->user_id, 'type' => 'message', 'reference_id' => $message->getKey(), 'context_id' => $conversation->getKey(),
                    'title' => 'New message from '.$request->user()->name,
                    'summary' => mb_substr($validated['body'], 0, 500),
                ]);
            }
            $audit->record('communications.message.sent', $request->user(), (int) $tenant->getKey(), $conversation, [
                'conversation_id' => $conversation->getKey(), 'recipient_user_id' => $recipient->user_id,
            ]);

            return $conversation;
        });

        return redirect()->route('tenant.communications.messages.index', ['tenant' => $tenant->slug, 'conversation' => $conversation->getKey()])->with('status', 'Message sent.');
    }

    public function reply(Request $request, Tenant $tenant, int $conversationId, AuditRecorder $audit): RedirectResponse
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('communications.messages.send'), 403);
        $validated = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $participant = CommunicationsConversationMember::query()->where('tenant_id', $tenant->getKey())
            ->where('user_id', $membership->user_id)->where('conversation_id', $conversationId)->firstOrFail();
        $conversation = CommunicationsConversation::query()->whereKey($conversationId)->firstOrFail();
        $otherId = (int) ($conversation->member_one_user_id === $membership->user_id ? $conversation->member_two_user_id : $conversation->member_one_user_id);
        $this->eligibleMember($tenant, $membership, $otherId);

        DB::transaction(function () use ($audit, $conversation, $membership, $participant, $request, $tenant, $validated, $otherId): void {
            $message = $conversation->messages()->create(['sender_user_id' => $membership->user_id, 'body' => $validated['body']]);
            $participant->forceFill(['last_read_at' => now()])->save();
            CommunicationsConversationMember::query()->where('tenant_id', $tenant->getKey())
                ->where('conversation_id', $conversation->getKey())->update(['updated_at' => now()]);
            $conversation->touch();
            if (CommunicationsNotificationPreference::allows((int) $tenant->getKey(), $otherId, 'message')) {
                CommunicationsNotification::query()->create([
                    'user_id' => $otherId, 'type' => 'message', 'reference_id' => $message->getKey(), 'context_id' => $conversation->getKey(),
                    'title' => 'New message from '.$request->user()->name,
                    'summary' => mb_substr($validated['body'], 0, 500),
                ]);
            }
            $audit->record('communications.message.sent', $request->user(), (int) $tenant->getKey(), $conversation, [
                'conversation_id' => $conversation->getKey(), 'recipient_user_id' => $otherId,
            ]);
        });

        return redirect()->route('tenant.communications.messages.index', ['tenant' => $tenant->slug, 'conversation' => $conversation->getKey()])->with('status', 'Message sent.');
    }

    private function eligibleMember(Tenant $tenant, TenantMembership $actor, int $userId): TenantMembership
    {
        $recipient = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $userId)
            ->where('status', 'active')->firstOrFail();
        abort_unless($recipient->hasPermission('communications.messages.view'), 404);
        if ($actor->campus_id !== null && $recipient->campus_id !== null && (int) $actor->campus_id !== (int) $recipient->campus_id) {
            abort(404);
        }

        return $recipient;
    }
}
