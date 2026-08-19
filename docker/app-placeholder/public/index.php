<?php
/**
 * Placeholder document root — replaced by the real Laravel application in
 * Module 0 step 1. It exists so point 0.6 can prove the request path end to
 * end: browser → nginx over TLS → php-fpm → PHP → PostgreSQL and Redis.
 *
 * It is NOT /health. ST-08 requires a health endpoint reporting each service
 * explicitly, and that belongs to the application, not to a placeholder.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$checks = [];

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=5432;dbname=%s', getenv('DB_HOST') ?: 'postgres', getenv('DB_DATABASE')),
        getenv('DB_USERNAME'), getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $checks['postgres'] = [
        'ok'       => true,
        'version'  => explode(' ', (string) $pdo->query('show server_version')->fetchColumn())[0],
        'timezone' => $pdo->query('show timezone')->fetchColumn(),   // DB-08
        'encoding' => $pdo->query("select current_setting('server_encoding')")->fetchColumn(),
        'arabic'   => $pdo->query("select 'عرض سعر — ١٢٣'::text")->fetchColumn(),
    ];
} catch (Throwable $e) {
    $checks['postgres'] = ['ok' => false, 'error' => $e->getMessage()];
}

try {
    $r = new Redis();
    $r->connect(getenv('REDIS_HOST') ?: 'redis', 6379, 2.0);
    $r->auth((string) getenv('REDIS_PASSWORD'));
    $checks['redis'] = ['ok' => $r->ping() !== false, 'version' => phpversion('redis')];
} catch (Throwable $e) {
    $checks['redis'] = ['ok' => false, 'error' => $e->getMessage()];
}

$checks['php'] = [
    'version'  => PHP_VERSION,
    'arch'     => php_uname('m'),
    'user'     => function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown',
    'locale'   => setlocale(LC_ALL, 0),
    'timezone' => date_default_timezone_get(),
    'tls'      => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443',
];

// The money chain, over the wire this time (D-64 on the PO #226 figures).
bcscale(6);
$discount = bcdiv(bcmul('7368.42', '1'), '100');
$taxBase  = bcsub('7368.42', $discount);
$checks['money'] = ['tax_base' => $taxBase, 'total' => bcadd($taxBase, bcdiv(bcmul($taxBase, '14'), '100'))];

$ok = ($checks['postgres']['ok'] ?? false) && ($checks['redis']['ok'] ?? false);
http_response_code($ok ? 200 : 503);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
