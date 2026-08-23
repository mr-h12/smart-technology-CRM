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
    'rate_limit' => [
        'login' => [
            'attempts' => (int) env('IDENTITY_LOGIN_RATE_ATTEMPTS', 10),
            'decay_minutes' => (int) env('IDENTITY_LOGIN_RATE_DECAY_MINUTES', 1),
        ],
    ],

];
