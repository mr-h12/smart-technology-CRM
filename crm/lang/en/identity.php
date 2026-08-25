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
        // SEC-04. Deliberately the same sentence for a wrong code, a missing
        // challenge and an exhausted one — telling them apart is an oracle, and
        // the action is identical in all three.
        'invalid_verification_code' => 'That verification code is not valid. Request a new one and try again.',
        'expired_verification_code' => 'That verification code has expired. Request a new one and try again.',
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

    'impersonation' => [
        // SEC-10. None of these names a role or an account: a refusal that
        // explained itself would describe the permission matrix, and one of the
        // answers is an account §3.12 rule 6 hides.
        'impersonation_forbidden' => 'Only the system administrator may sign in as another user.',
        'target_not_found' => 'No such user.',
        'target_suspended' => 'That account is deactivated and cannot be used.',
        'target_is_self' => 'You are already signed in as that user.',
        'already_impersonating' => 'Leave the current session before starting another one.',
        'not_impersonating' => 'This session is not an impersonation.',
    ],

    'role_administration' => [
        // §3.11 and §3.12 rules 3 and 5. None of these names a role: a refusal
        // that explained itself would describe the permission matrix, which is
        // the thing the caller was just told they may not read.
        'role_not_found' => 'That role does not exist.',
        'role_is_immutable' => 'The permissions of that role are fixed by the system and cannot be changed.',
        'permission_not_found' => 'One of the submitted permissions does not exist.',
        'grant_forbidden' => 'That permission can never be granted to any role.',
        // §3.1's eight. The message says what the caller may do instead,
        // because "cannot be changed" with no alternative is the kind of
        // refusal people work around by creating a duplicate.
        'system_role_cannot_be_edited' => 'That role is part of the system and its name cannot be changed. Create a new role instead.',
        'system_role_cannot_be_deleted' => 'That role is part of the system and cannot be archived.',
        'role_has_assigned_users' => 'People are still assigned to that role. Move them to another role first.',
        'slug_already_taken' => 'Another role already uses that identifier.',
        'name_already_taken' => 'Another role already uses that name.',
        'name_ar_already_taken' => 'Another role already uses that Arabic name.',
        'no_fields_submitted' => 'Provide at least one field to change.',
        'permission_ids_required' => 'Send permission_ids, using an empty list to revoke every permission.',
    ],

    'session' => [
        // SEC-05. Neither message names another account: `session_not_found`
        // answers the same way for an id that never existed, one that belongs
        // to somebody else, and a Login As row §3.1 hides.
        'session_not_found' => 'That device is not signed in.',
        'session_is_current' => 'This is the device you are using. Sign out to end this session.',
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

    'challenge_mail' => [
        'subject' => 'Your password change verification code',
        'greeting' => 'Hello :name,',
        'intro' => 'Use this code to confirm your password change:',
        'expires' => 'The code stops working at :until.',
        'ignore' => 'If you did not ask to change your password, ignore this message and tell your administrator.',
    ],

    'lockout_mail' => [
        'subject' => 'A CRM account has been locked',
        'intro' => 'The account of :name (:email) was locked after too many failed sign-in attempts.',
        'until' => 'The lock lifts at :until.',
        'origin' => 'The last attempt came from :ip.',
        'unknown_ip' => 'an unknown address',
    ],

];
