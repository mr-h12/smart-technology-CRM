<?php

declare(strict_types=1);

return [

    'refusal' => [
        'invalid_credentials' => 'These credentials do not match our records.',
        // §10.1, word for word. Changing this string changes a documented
        // acceptance criterion, and `AuthenticationTest` reads it back out of
        // the master documentation rather than trusting this line.
        'account_suspended' => 'Account suspended, please contact administration.',
        'account_locked' => 'This account is locked after too many failed sign-in attempts. Contact administration.',
        'session_invalid' => 'Your session is no longer valid. Please sign in again.',
        'permission_denied' => 'You do not have permission to perform this action.',
        'unauthorized_action' => 'The action :ability is not permitted for your role.',
    ],

    'password' => [
        'current_password_incorrect' => 'Your current password is not correct.',
        'password_policy_not_met' => 'The password must be at least 8 characters and contain both letters and numbers.',
        'password_unchanged' => 'The new password must be different from the current one.',
    ],

    'errors' => [
        'validation_failed' => 'Please correct the highlighted fields.',
        'rate_limited' => 'Too many attempts. Please try again shortly.',
    ],

    'lockout_mail' => [
        'subject' => 'A CRM account has been locked',
        'intro' => 'The account of :name (:email) was locked after too many failed sign-in attempts.',
        'until' => 'The lock lifts at :until.',
        'origin' => 'The last attempt came from :ip.',
        'unknown_ip' => 'an unknown address',
    ],

];
