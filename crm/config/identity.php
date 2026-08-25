<?php

declare(strict_types=1);

/*
| Module 1's tunable numbers.
|
| §3.12 rule 5 and `AP-08` say limits are configuration rather than code
| constants, and `OpenAPI §10` says the same of rate limits: "the concrete
| limits are configurable system settings, not client constants". Module 2
| moves these into the `settings` table, where an administrator can change them
| without a deployment; this file is the interim that keeps them out of the
| classes in the meantime.
|
| What is deliberately NOT here: `LockoutPolicy::MAX_ATTEMPTS` (5) and
| `IdleTimeout::HOURS` (8). Those two are stated outright by `SEC-03` and `D-29`
| — they are the requirement, not a setting, and an environment variable that
| can turn a documented security rule off is a defect with a config file for a
| costume.
*/

return [

    /*
    | How long an account stays locked after `SEC-03`'s fifth failure.
    |
    | ⚠️ **Nothing in the documentation gives this number.** `SEC-03` and §9
    | Flow 0 both stop at "locked", and neither says whether the lock lifts on
    | its own or who clears it. Point 1.2's approved schema has a `locked_until`
    | column, which presupposes an expiry, so a value is required and 30 minutes
    | is the interim. **This is flagged for the owner to decide**, and it is a
    | setting rather than a constant precisely because the answer is not ours.
    */
    'lockout_minutes' => (int) env('IDENTITY_LOCKOUT_MINUTES', 30),

    /*
    | `SEC-11` — "Rate limiting on login and the API".
    |
    | Keyed per IP *and* per submitted address, so one office behind one NAT
    | address cannot be locked out of the product by one person's bad morning,
    | while a single address cannot be attacked from a rotating IP either.
    |
    | Ten a minute sits above `SEC-03`'s five — a real person hitting the
    | lockout must reach it and be told they are locked, rather than being
    | throttled first and never seeing the lock at all.
    */
    /*
    | `SEC-04`'s emailed verification code — §9 Flow 0 step 2.
    |
    | ⚠️ **`SEC-04` gives neither number.** It says "mandatory email
    | verification for password changes" and stops; §9 Flow 0 says "verification
    | code by email" and stops. So both values below are the owner's instruction
    | of 2026-08-25, held as configuration for exactly the reason `D-75` records
    | for `lockout_minutes`: an undocumented limit is a setting, and Module 2
    | moves it into the `settings` table.
    |
    | **15 minutes** is long enough to open a mail client, find the message and
    | retype six digits, and short enough that a code left in a shared inbox
    | goes stale within a coffee break.
    |
    | **5 attempts** is the number that makes six digits defensible: with it, a
    | challenge absorbs five guesses out of a million before it is destroyed and
    | the caller must request another — which is itself rate-limited below. It
    | matches `SEC-03`'s five, not because that requirement reaches this control,
    | but because two different "how many tries" numbers in one login flow is a
    | thing people get wrong at the keyboard.
    */
    'password_challenge' => [
        'ttl_minutes' => (int) env('IDENTITY_CHALLENGE_TTL_MINUTES', 15),
        'max_verification_attempts' => (int) env('IDENTITY_CHALLENGE_MAX_ATTEMPTS', 5),
    ],

    'rate_limit' => [
        'login' => [
            'attempts' => (int) env('IDENTITY_LOGIN_RATE_ATTEMPTS', 10),
            'decay_minutes' => (int) env('IDENTITY_LOGIN_RATE_DECAY_MINUTES', 1),
        ],

        /*
        | `SEC-11` on the challenge endpoint, keyed **per account** rather than
        | per IP: the caller is already authenticated, so the account is the
        | thing worth protecting, and an IP key would let one person exhaust the
        | office's allowance for everybody behind the same NAT address.
        |
        | Three per fifteen minutes — the owner's instruction of 2026-08-25.
        | It bounds how much mail one session can make the server send, which is
        | the abuse this endpoint actually offers.
        */
        'password_challenge' => [
            'attempts' => (int) env('IDENTITY_CHALLENGE_RATE_ATTEMPTS', 3),
            'decay_minutes' => (int) env('IDENTITY_CHALLENGE_RATE_DECAY_MINUTES', 15),
        ],
    ],

];
