<?php

// Account screens: server-side messages (validation, mails). UI copy is resources/js/lib/strings-account.ts.
return [
    'profile' => [
        'saved' => 'Profile saved.',
        'saved_pending' => 'Profile saved. Check :email to confirm the new email.',
        'name_required' => 'Enter your name',
        'name_max' => 'Use at most 120 characters for your name',
        'timezone_invalid' => 'Pick a timezone from the list',
        'email_invalid' => 'Enter an email like name@example.com',
    ],
    'password' => [
        'mismatch' => 'The two new passwords don\'t match',
    ],
    'email' => [
        'password_wrong' => 'Enter your current password to change your email',
        'taken' => 'That email can\'t be used. Try another one.',
        'resent' => 'A new link is on its way to :email.',
        'throttled' => 'Too many links sent. Wait a few minutes and try again.',
        'kept' => 'Kept your current email.',
        'none_pending' => 'There is no email change waiting.',
        'link_invalid' => 'This email change link is invalid or has expired. Request a new one from your account.',
        'link_taken' => 'That email is now used by another account. Your email was not changed.',
        'changed' => 'Email changed. Log in with :email from now on.',
    ],
    'mail' => [
        'verify' => [
            'subject' => 'Confirm your new Bridge email',
            'intro' => 'Someone asked to use this address for a Bridge account. Confirm it to make it the login email.',
            'action' => 'Confirm new email',
            'expires' => 'The link works for :minutes minutes.',
            'ignore' => 'If you did not ask for this, ignore this email: nothing changes.',
        ],
        'requested' => [
            'subject' => 'Your Bridge email is about to change',
            'intro' => 'Someone asked to change your Bridge login email to :new.',
            'until' => 'Until the link sent to that address is opened, you keep logging in with this email.',
            'not_you' => 'If this wasn\'t you, change your password now.',
        ],
        'confirmed' => [
            'subject' => 'Your Bridge email was changed',
            'intro' => 'Your Bridge login email is now :new.',
            'sessions' => 'Other devices were logged out.',
            'not_you' => 'If this wasn\'t you, reset your password and contact support.',
        ],
    ],
];
