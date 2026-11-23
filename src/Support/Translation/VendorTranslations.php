<?php

namespace Hata\Support\Translation;

/**
 * Maps literal English strings that vendor packages (Fortify, passkeys, Flux) pass to __() onto keys in lang/{locale}/*.php.
 *
 * The project keeps translations in PHP files only (no lang/*.json), so these literals
 * would otherwise never be translated. Registered via Lang::handleMissingKeysUsing().
 */
class VendorTranslations
{
    /**
     * @var array<string, string>
     */
    public const array KEYS = [
        'The provided password was incorrect.' => 'auth.vendor.password_incorrect',
        'The provided two factor authentication code was invalid.' => 'auth.vendor.two_factor_code_invalid',
        'The provided two factor recovery code was invalid.' => 'auth.vendor.recovery_code_invalid',
        'Invalid credential format.' => 'auth.vendor.invalid_credential_format',
        'Passkey registration session expired. Please try again.' => 'auth.vendor.passkey_registration_expired',
        'Passkey verification session expired. Please try again.' => 'auth.vendor.passkey_verification_expired',
        'Passkey not recognized. It may have been removed from your account.' => 'auth.vendor.passkey_not_recognized',
        'Unable to register passkey. Please try again.' => 'auth.vendor.passkey_register_failed_retry',
        'Unable to register this passkey.' => 'auth.vendor.passkey_register_failed',
        'Unable to verify passkey. Please try again.' => 'auth.vendor.passkey_verify_failed',

        'Character {current} of {total}' => 'common.flux.character_count',
        'Choose file' => 'common.flux.choose_file',
        'Choose files' => 'common.flux.choose_files',
        'Clear input' => 'common.flux.clear_input',
        'Close modal' => 'common.flux.close_modal',
        'Copy to clipboard' => 'common.flux.copy_to_clipboard',
        'files' => 'common.flux.files',
        'No file chosen' => 'common.flux.no_file_chosen',
        'of' => 'common.flux.of',
        'results' => 'common.flux.results',
        'Search...' => 'common.flux.search',
        'Showing' => 'common.flux.showing',
        'to' => 'common.flux.to',
        'Toggle password visibility' => 'common.flux.toggle_password',
        'Toggle sidebar' => 'common.flux.toggle_sidebar',
    ];

    /**
     * Translate a vendor literal, or return null when it is not mapped.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function translate(string $key, array $replace, ?string $locale): ?string
    {
        if (! array_key_exists($key, self::KEYS)) {
            return null;
        }

        $translated = __(self::KEYS[$key], $replace, $locale);

        return is_string($translated) ? $translated : null;
    }
}
