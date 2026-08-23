<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Test user password (DEV-08, D-28)
    |---------------------------------------------------------------------------
    |
    | The password UserSeeder gives the eight test personas. Declared here and
    | not read with env() inside the seeder, because **env() returns null once
    | config:cache has run** — measured, not assumed: with the key present in
    | .env, `env('SEED_TEST_USER_PASSWORD')` gave the value before caching and
    | NULL after. Production runs cached (Point 8.4), so a seeder reading env()
    | directly would refuse a password that was correctly set.
    |
    | There is no default. `.env.example` declares the key empty, the same rule
    | DB_PASSWORD and REDIS_PASSWORD already follow, and UserSeeder refuses to
    | run without it rather than shipping a literal.
    |
    */

    'test_user_password' => env('SEED_TEST_USER_PASSWORD'),

];
