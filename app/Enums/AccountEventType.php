<?php

namespace App\Enums;

/** What happened to an account (DESIGN.md §15). Stored as the value; never rename one. */
enum AccountEventType: string
{
    // By an owner, on Pengguna.
    case Created = 'created';
    case Updated = 'updated';
    case Deactivated = 'deactivated';
    case Reactivated = 'reactivated';
    case PasswordReset = 'password_reset';
    case PinReset = 'pin_reset';
    case SignInFactorsReset = 'sign_in_factors_reset';

    // By the user, on their own account.
    case ProfileUpdated = 'profile_updated';
    case PasswordChanged = 'password_changed';
    case PinSet = 'pin_set';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case PasskeyAdded = 'passkey_added';
    case PasskeyRemoved = 'passkey_removed';

    // By the system.
    case DemoSignInReleased = 'demo_sign_in_released';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Akun dibuat',
            self::Updated => 'Akun diubah',
            self::Deactivated => 'Dinonaktifkan',
            self::Reactivated => 'Diaktifkan lagi',
            self::PasswordReset => 'Kata sandi direset',
            self::PinReset => 'PIN dihapus',
            self::SignInFactorsReset => '2FA & passkey direset',
            self::ProfileUpdated => 'Profil diubah',
            self::PasswordChanged => 'Mengganti kata sandi',
            self::PinSet => 'Mengatur PIN',
            self::TwoFactorEnabled => 'Mengaktifkan 2FA',
            self::TwoFactorDisabled => 'Menonaktifkan 2FA',
            self::RecoveryCodesRegenerated => 'Membuat kode pemulihan baru',
            self::PasskeyAdded => 'Menambah passkey',
            self::PasskeyRemoved => 'Menghapus passkey',
            self::DemoSignInReleased => '2FA & passkey akun demo dibersihkan',
        };
    }
}
