<?php

declare(strict_types=1);

// F-26 · 1.1: the SPA calls /api/v1 from its own origin (D-67), on the LAN and
// behind Cloudflare alike (D-59), so no other origin is granted access. Every
// other key keeps the framework's default.
return [
    'allowed_origins' => [],
];
