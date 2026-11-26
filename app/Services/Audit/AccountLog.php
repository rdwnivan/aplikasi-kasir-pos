<?php

namespace App\Services\Audit;

use App\Enums\AccountEventType;
use App\Enums\Role;
use App\Models\AccountEvent;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * The one place account changes are written to `account_events` (DESIGN.md §15). Our own
 * Actions and controllers call record() inside their transaction; the vendor's 2FA and passkey
 * events arrive through the listeners below, after the change was saved.
 *
 * Never pass a secret in $details: only names, emails, roles, counts and flags.
 */
final class AccountLog
{
    /** @param array<string, mixed> $details */
    public static function record(User $user, AccountEventType $type, ?User $actor, array $details = []): AccountEvent
    {
        return AccountEvent::query()->create([
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'details' => $details === [] ? null : $details,
        ]);
    }

    /**
     * Old → new pairs for the attributes that changed, from a model about to be (or just) saved.
     *
     * @param  list<string>  $attributes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function changes(User $user, array $attributes): array
    {
        $changes = [];
        foreach ($attributes as $attribute) {
            if ($user->isDirty($attribute)) {
                $changes[$attribute] = [$user->getOriginal($attribute), $user->getAttribute($attribute)];
            }
        }

        return $changes;
    }

    /** @return list<string> Lines describing `details`, in Indonesian, for the history page. */
    public static function describe(AccountEvent $event): array
    {
        $details = $event->details ?? [];
        $lines = [];

        foreach (['name' => 'Nama', 'email' => 'Email', 'role' => 'Role'] as $key => $label) {
            $value = $details[$key] ?? null;
            if (is_array($value)) {
                [$old, $new] = $value;
                $lines[] = "{$label}: ".self::show($key, $old).' → '.self::show($key, $new);
            } elseif ($value !== null) {
                $lines[] = "{$label}: ".self::show($key, $value);
            }
        }
        if (array_key_exists('two_factor', $details)) {
            $lines[] = $details['two_factor'] ? '2FA dihapus' : 'Tanpa 2FA';
        }
        if (array_key_exists('passkeys', $details)) {
            $lines[] = "{$details['passkeys']} passkey dihapus";
        }
        if (isset($details['via'])) {
            $lines[] = match ($details['via']) {
                'settings' => 'Lewat Pengaturan → Keamanan',
                'forced' => 'Mengganti kata sandi sementara dari owner',
                'reset_link' => 'Lewat tautan lupa kata sandi',
                default => (string) $details['via'],
            };
        }

        return $lines;
    }

    private static function show(string $key, mixed $value): string
    {
        if ($key === 'role') {
            return Role::tryFrom((string) $value)?->label() ?? '–';
        }

        return (string) $value;
    }

    /** Wired in AppServiceProvider. The actor is whoever is logged in (normally the user). */
    public static function listen(): void
    {
        $self = fn (AccountEventType $type, mixed $user) => $user instanceof User
            ? self::record($user, $type, self::actor($user))
            : null;

        app('events')->listen(TwoFactorAuthenticationConfirmed::class, fn ($e) => $self(AccountEventType::TwoFactorEnabled, $e->user));
        app('events')->listen(TwoFactorAuthenticationDisabled::class, fn ($e) => $self(AccountEventType::TwoFactorDisabled, $e->user));
        app('events')->listen(RecoveryCodesGenerated::class, fn ($e) => $self(AccountEventType::RecoveryCodesRegenerated, $e->user));
        app('events')->listen(PasskeyRegistered::class, fn ($e) => $self(AccountEventType::PasskeyAdded, $e->user));
        app('events')->listen(PasskeyDeleted::class, fn ($e) => $self(AccountEventType::PasskeyRemoved, $e->user));
        app('events')->listen(PasswordReset::class, fn (PasswordReset $e) => $e->user instanceof User
            ? self::record($e->user, AccountEventType::PasswordChanged, $e->user, ['via' => 'reset_link'])
            : null);
    }

    private static function actor(User $user): User
    {
        $current = Auth::user();

        return $current instanceof User ? $current : $user;
    }
}
