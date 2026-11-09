<?php

return [

    'title' => 'Site settings',
    'description' => 'Settings for the whole site. Saved values override the ones from the .env file.',

    'general' => [
        'heading' => 'General',
        'site_name' => 'Site name',
        'site_name_description' => 'Shown in the sidebar, browser tab titles and emails.',
        'default_locale' => 'Default language',
        'default_locale_description' => 'For guests and users who have not chosen a language.',
        'timezone' => 'Timezone',
        'timezone_description' => 'Dates in the admin panel are shown in this timezone.',
    ],

    'access' => [
        'heading' => 'Access',
        'registration' => 'Public registration',
        'registration_description' => 'Visitors can create an account on the sign-up page.',
        'default_role' => 'Role of new users',
        'default_role_description' => 'Given to users who register themselves.',
        'no_role' => 'No role',
        'role_unavailable' => 'unavailable to you',
        'admin_access_warning' => 'This role opens the admin panel: everyone who registers will get into it.',
    ],

    'mail' => [
        'heading' => 'Email',
        'from_address' => 'Sender address',
        'from_name' => 'Sender name',
        'mailer' => 'Sending method',
        'mailer_server' => 'Server settings (.env)',
        'mailer_smtp' => 'SMTP server',
        'host' => 'SMTP host',
        'port' => 'Port',
        'scheme' => 'Encryption',
        'scheme_starttls' => 'STARTTLS (587)',
        'scheme_ssl' => 'SSL/TLS (465)',
        'username' => 'Username',
        'password' => 'Password',
        'password_keep' => 'Leave empty to keep the saved password.',
        'send_test' => 'Send a test email',
        'send_test_hint' => 'The test email goes to your address and uses the saved settings, so save your changes first.',
        'test_subject' => 'Test email from :site',
        'test_body' => 'This is a test email from :site. If you are reading it, sending email works.',
        'test_sent' => 'Test email sent to :email.',
        'test_failed' => 'Could not send the email: :error',
    ],

    'logo' => [
        'heading' => 'Logo',
        'description' => 'Shown next to the site name in the admin panel and on the sign-in pages. Without an upload, the default Hata mark is used. If you upload only one logo, it is used in both themes.',
        'light' => 'Light theme',
        'dark' => 'Dark theme',
        'default' => 'Default logo',
        'uses_other' => 'Uses the other theme’s logo',
        'upload' => 'Upload a logo',
        'uploading' => 'Uploading…',
        'remove' => 'Remove',
        'requirements' => 'PNG, JPG, WebP or SVG, up to 1 MB. A logo is saved as soon as it is uploaded. Best: transparent background, height at least 64 px.',
        'uploaded' => 'Logo uploaded.',
        'removed' => 'Logo removed.',
    ],

    'unsaved' => [
        'heading' => 'Unsaved changes',
        'body' => 'The “:tab” tab has unsaved changes. If you switch tabs, they will be lost.',
        'discard' => 'Switch without saving',
        'leave_body' => 'This page has unsaved changes. If you leave it, they will be lost.',
        'leave' => 'Leave without saving',
    ],

    'messages' => [
        'saved' => 'Site settings saved.',
        'role_unavailable' => 'You cannot choose this role: it has permissions you do not have.',
    ],

];
