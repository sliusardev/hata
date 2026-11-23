<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The texts of the authentication pages. Laravel's own lines (failed, password,
    | throttle) are in resources/lang-framework.
    |
    */

    'login' => [
        'title' => 'Log in',
        'heading' => 'Log in to your account',
        'description' => 'Enter your email and password below to log in',
        'forgot_password' => 'Forgot your password?',
        'remember_me' => 'Remember me',
        'submit' => 'Log in',
        'no_account' => 'Don\'t have an account?',
        'sign_up' => 'Sign up',
    ],

    'register' => [
        'title' => 'Register',
        'heading' => 'Create an account',
        'description' => 'Enter your details below to create your account',
        'name_placeholder' => 'Full name',
        'submit' => 'Create account',
        'have_account' => 'Already have an account?',
        'log_in' => 'Log in',
    ],

    'forgot_password' => [
        'title' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'submit' => 'Email password reset link',
        'return_to' => 'Or, return to',
        'log_in' => 'log in',
    ],

    'reset_password' => [
        'title' => 'Reset password',
        'description' => 'Please enter your new password below',
        'submit' => 'Reset password',
    ],

    'confirm_password' => [
        'title' => 'Confirm password',
        'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
        'with_passkey' => 'Confirm with passkey',
        'confirming' => 'Confirming...',
        'separator' => 'Or confirm with password',
    ],

    'two_factor' => [
        'title' => 'Two-factor authentication',
        'code_heading' => 'Authentication code',
        'code_description' => 'Enter the authentication code provided by your authenticator application.',
        'code_label' => 'OTP code',
        'recovery_heading' => 'Recovery code',
        'recovery_description' => 'Please confirm access to your account by entering one of your emergency recovery codes.',
        'or_you_can' => 'or you can',
        'use_recovery_code' => 'login using a recovery code',
        'use_authentication_code' => 'login using an authentication code',
    ],

    'passkeys' => [
        'sign_in' => 'Sign in with a passkey',
        'authenticating' => 'Authenticating...',
        'separator' => 'Or continue with email',
    ],

    /*
    | Messages that Fortify and laravel/passkeys pass to __() as literal
    | English strings. They are mapped to these keys by
    | Hata\Support\Translation\VendorTranslations.
    */
    'vendor' => [
        'password_incorrect' => 'The provided password was incorrect.',
        'two_factor_code_invalid' => 'The provided two factor authentication code was invalid.',
        'recovery_code_invalid' => 'The provided two factor recovery code was invalid.',
        'invalid_credential_format' => 'Invalid credential format.',
        'passkey_registration_expired' => 'Passkey registration session expired. Please try again.',
        'passkey_verification_expired' => 'Passkey verification session expired. Please try again.',
        'passkey_not_recognized' => 'Passkey not recognized. It may have been removed from your account.',
        'passkey_register_failed_retry' => 'Unable to register passkey. Please try again.',
        'passkey_register_failed' => 'Unable to register this passkey.',
        'passkey_verify_failed' => 'Unable to verify passkey. Please try again.',
    ],

];
