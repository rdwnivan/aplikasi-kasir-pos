<?php

namespace App\Http\Controllers\Users;

use App\Enums\AccountEventType;
use App\Http\Controllers\Controller;
use App\Models\AccountEvent;
use App\Models\User;
use App\Services\Audit\AccountLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pengguna → Riwayat akun: the account audit log, newest first (DESIGN.md §15). */
class AccountEventController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_column(AccountEventType::cases(), 'value'))],
        ]);

        $events = AccountEvent::query()
            ->with(['user:id,name', 'actor:id,name'])
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AccountEvent $event) => [
                'id' => $event->id,
                'at' => $event->created_at->toIso8601String(),
                'type' => $event->type->value,
                'label' => $event->type->label(),
                'user' => ['id' => $event->user_id, 'name' => $event->user?->name],
                'actor' => $event->actor === null ? null : ['id' => $event->actor->id, 'name' => $event->actor->name],
                'by_self' => $event->actor_id === $event->user_id,
                'changes' => AccountLog::describe($event),
            ]);

        return Inertia::render('users/events', [
            'events' => $events,
            'filters' => ['user' => $filters['user'] ?? null, 'type' => $filters['type'] ?? null],
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'types' => array_map(fn (AccountEventType $type) => ['value' => $type->value, 'label' => $type->label()], AccountEventType::cases()),
            'since' => AccountEvent::query()->min('created_at'),
        ]);
    }
}
