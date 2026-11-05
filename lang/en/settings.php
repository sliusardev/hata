<?php

return [

    'heading' => 'Settings',
    'description' => 'Manage your profile and account settings',

    'nav' => [
        'profile' => 'Profile',
        'language' => 'Language',
        'security' => 'Security',
        'appearance' => 'Appearance',
    ],

    'profile' => [
        'title' => 'Profile settings',
        'heading' => 'Profile',
        'description' => 'Update your name and email address',
        'saved' => 'Profile updated.',
    ],

    'language' => [
        'title' => 'Language settings',
        'heading' => 'Language',
        'description' => 'Choose the language of the interface',
        'label' => 'Interface language',
        'saved' => 'Language updated.',
    ],

    'appearance' => [
        'title' => 'Appearance settings',
        'heading' => 'Appearance',
        'description' => 'Update the appearance settings for your account',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],

    'security' => [
        'title' => 'Security settings',
        'password_heading' => 'Update password',
        'password_description' => 'Ensure your account is using a long, random password to stay secure',
        'password_updated' => 'Password updated.',
    ],

    'two_factor' => [
        'heading' => 'Two-factor authentication',
        'description' => 'Manage your two-factor authentication settings',
        'enabled_text' => 'You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.',
        'disabled_text' => 'When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.',
        'enable' => 'Enable 2FA',
        'disable' => 'Disable 2FA',
        'setup' => [
            'enabled_title' => 'Two-factor authentication enabled',
            'enabled_description' => 'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
            'verify_title' => 'Verify authentication code',
            'verify_description' => 'Enter the 6-digit code from your authenticator app.',
            'enable_title' => 'Enable two-factor authentication',
            'enable_description' => 'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.',
            'manual_code' => 'or, enter the code manually',
            'code_label' => 'OTP code',
            'failed' => 'Failed to fetch setup data.',
        ],
    ],

    'recovery_codes' => [
        'heading' => '2FA recovery codes',
        'description' => 'Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.',
        'view' => 'View recovery codes',
        'hide' => 'Hide recovery codes',
        'regenerate' => 'Regenerate codes',
        'list_label' => 'Recovery codes',
        'usage_note' => 'Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.',
        'load_failed' => 'Failed to load recovery codes.',
    ],

    'passkeys' => [
        'heading' => 'Passkeys',
        'description' => 'Manage your passkeys for passwordless sign-in',
        'added' => 'Added :time',
        'last_used' => 'Last used :time',
        'empty_title' => 'No passkeys yet',
        'empty_description' => 'Add a passkey to sign in without a password',
        'remove' => 'Remove passkey',
        'remove_confirm' => 'Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.',
        'not_supported' => 'Passkeys are not supported in this browser.',
        'add' => 'Add passkey',
        'name_label' => 'Passkey name',
        'name_placeholder' => 'e.g., MacBook Pro, iPhone',
        'name_help' => 'Give this passkey a name to help you identify it later.',
        'default_name' => ':browser on :os',
        'register' => 'Register passkey',
        'registering' => 'Registering...',
    ],

    'delete_account' => [
        'heading' => 'Delete account',
        'description' => 'Delete your account and all of its resources',
        'button' => 'Delete account',
        'confirm_title' => 'Are you sure you want to delete your account?',
        'confirm_description' => 'Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
    ],

];
