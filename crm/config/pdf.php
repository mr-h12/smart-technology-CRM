<?php

declare(strict_types=1);

/*
 * Module 9, Point 2.1 — where the `pdf` image keeps its browser.
 *
 * `CHROME_PATH` is set by `docker/php/Dockerfile`'s pdf target; the module
 * path is `npm root -g` there, where that target installs Puppeteer.
 */
return [
    'chrome_path' => env('CHROME_PATH', '/usr/bin/chromium'),
    'node_modules_path' => env('PDF_NODE_MODULES_PATH', '/usr/local/lib/node_modules'),
    'timeout_seconds' => 60,
];
