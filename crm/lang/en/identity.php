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

    'administration' => [
        // §3.12 rule 7 and §3.11's create-user cell. The message names no role:
        // telling the caller which roles they *may* assign is a description of
        // the permission matrix, and §3.12 rule 6 hides one of the answers.
        'role_not_assignable' => 'You may not assign that role.',
        'user_not_found' => 'No such user.',
        'email_already_taken' => 'That email address already belongs to an account.',
        'password_policy_not_met' => 'The password must be at least 8 characters and contain both letters and numbers.',
        'role_not_found' => 'That role does not exist.',
        'no_fields_submitted' => 'Provide at least one field to change.',
    ],

    'list_query' => [
        'above_maximum' => 'per_page may not exceed 100.',
        'not_a_positive_integer' => 'This value must be a whole number greater than zero.',
        'unknown_sort_field' => 'That sort field is not available on this resource.',
        'too_many_sort_fields' => 'This resource accepts a single sort field.',
        'unknown_filter' => 'That filter is not available on this resource.',
        'not_a_boolean' => 'This filter accepts true or false.',
        'not_a_role_slug' => 'That is not a valid role identifier.',
    ],

    'errors' => [
        'validation_failed' => 'Please correct the highlighted fields.',
        'invalid_request' => 'The request could not be understood.',
        'resource_not_found' => 'The requested resource was not found.',
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
