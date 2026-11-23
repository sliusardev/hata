<?php

namespace Hata\Support\Translation;

/**
 * Maps literal English strings that vendor packages (Fortify, passkeys, Flux) pass to __() onto keys in the `hata::` translations (resources/lang/{locale}/*.php).
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
        'The provided password was incorrect.' => 'hata::auth.vendor.password_incorrect',
        'The provided two factor authentication code was invalid.' => 'hata::auth.vendor.two_factor_code_invalid',
        'The provided two factor recovery code was invalid.' => 'hata::auth.vendor.recovery_code_invalid',
        'Invalid credential format.' => 'hata::auth.vendor.invalid_credential_format',
        'Passkey registration session expired. Please try again.' => 'hata::auth.vendor.passkey_registration_expired',
        'Passkey verification session expired. Please try again.' => 'hata::auth.vendor.passkey_verification_expired',
        'Passkey not recognized. It may have been removed from your account.' => 'hata::auth.vendor.passkey_not_recognized',
        'Unable to register passkey. Please try again.' => 'hata::auth.vendor.passkey_register_failed_retry',
        'Unable to register this passkey.' => 'hata::auth.vendor.passkey_register_failed',
        'Unable to verify passkey. Please try again.' => 'hata::auth.vendor.passkey_verify_failed',

        'Character {current} of {total}' => 'hata::common.flux.character_count',
        'Choose file' => 'hata::common.flux.choose_file',
        'Choose files' => 'hata::common.flux.choose_files',
        'Clear input' => 'hata::common.flux.clear_input',
        'Close modal' => 'hata::common.flux.close_modal',
        'Copy to clipboard' => 'hata::common.flux.copy_to_clipboard',
        'files' => 'hata::common.flux.files',
        'No file chosen' => 'hata::common.flux.no_file_chosen',
        'of' => 'hata::common.flux.of',
        'results' => 'hata::common.flux.results',
        'Search...' => 'hata::common.flux.search',
        'Showing' => 'hata::common.flux.showing',
        'to' => 'hata::common.flux.to',
        'Toggle password visibility' => 'hata::common.flux.toggle_password',
        'Toggle sidebar' => 'hata::common.flux.toggle_sidebar',
    ];

    /**
     * Translate a vendor literal or return null when it is not Hata's to answer.
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
