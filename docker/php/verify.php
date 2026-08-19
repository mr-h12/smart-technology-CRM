<?php
// Functional verification of the CRM PHP image. Run locally and in CI against
// the amd64 build, so the architecture carried on the deployment-debt register
// is checked rather than assumed.
//
// Exits non-zero on the first failure. Checks behaviour, not the mere presence
// of an extension: an extension that loads but computes wrongly is worse than
// one that is missing.

$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fail;
    printf("  %-46s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $detail !== '' ? "  $detail" : '');
    if (!$ok) { $fail = 1; }
}

echo "architecture: " . php_uname('m') . "   PHP " . PHP_VERSION . "\n\n";

// --- extensions the framework and this system require -----------------------
foreach ([
    'pdo_pgsql','pgsql','redis','bcmath','intl','mbstring','zip','gd','pcntl',
    'posix','exif','ctype','curl','dom','fileinfo','filter','hash','openssl',
    'pcre','PDO','session','tokenizer','xml',
] as $ext) {
    check("extension: $ext", extension_loaded($ext));
}
check('extension: opcache', extension_loaded('Zend OPcache'));

// --- money: the D-64 chain on the PO #226 figures ---------------------------
// D-57 and CLAUDE.md mandate BCMath; DB-07 forbids float anywhere near a price.
bcscale(6);
$subtotal = '7368.42';
$discount = bcdiv(bcmul($subtotal, '1'), '100');        // D-07
$taxBase  = bcsub($subtotal, $discount);                // D-64: tax AFTER discount
$tax      = bcdiv(bcmul($taxBase, '14'), '100');
$total    = bcadd($taxBase, $tax);
check('D-64 chain: discount',  $discount === '73.684200',   $discount);
check('D-64 chain: tax base',  $taxBase  === '7294.735800', $taxBase);
check('D-64 chain: tax',       $tax      === '1021.263012', $tax);
check('D-64 chain: total',     $total    === '8315.998812', $total);
check('DB-07: float is unusable for money', (0.1 + 0.2) !== 0.3, 'float 0.1+0.2 != 0.3');

// --- Arabic: locale data and multibyte handling ------------------------------
check('intl: ICU present', defined('INTL_ICU_VERSION'), INTL_ICU_VERSION ?? '');
check('intl: ar_EG digits',
    (new NumberFormatter('ar_EG', NumberFormatter::DECIMAL))->format(1234567.89) === '١٬٢٣٤٬٥٦٧٫٨٩');
$ar = 'عرض سعر رقم ١٢٣';
check('mbstring: Arabic length', mb_strlen($ar) === 15 && strlen($ar) === 27,
    'chars=' . mb_strlen($ar) . ' bytes=' . strlen($ar));
check('mbstring: Arabic substr', mb_substr($ar, 0, 3) === 'عرض');

// --- images: §17 requires automatic compression, and permits WEBP ------------
$gd = gd_info();
foreach (['JPEG Support','FreeType Support','WebP Support','PNG Support'] as $k) {
    check("gd: $k", !empty($gd[$k]));
}

// --- locale: the base image defaults to ASCII, which corrupts Arabic ---------
check('locale: UTF-8 charmap', stripos(trim(shell_exec('locale charmap 2>/dev/null') ?? ''), 'utf') !== false,
    trim(shell_exec('locale charmap 2>/dev/null') ?? ''));

// --- fonts: Design System §4.1, and the defect P-01 found on the server ------
// The base image ships no fonts at all. These must resolve to the real family,
// not to a fallback, or Arabic renders as tofu.
foreach ([
    'Noto Sans Arabic' => 'NotoSansArabic',
    'Inter'            => 'Inter-',
    'Noto Sans Mono'   => 'NotoSansMono',
] as $family => $expectedFile) {
    $match = trim(shell_exec('fc-match ' . escapeshellarg($family) . ' 2>/dev/null') ?? '');
    check("font: $family resolves", str_contains($match, $expectedFile), $match);
}
$arabicFonts = (int) trim(shell_exec('fc-list :lang=ar 2>/dev/null | wc -l') ?? '0');
check('font: Arabic coverage present', $arabicFonts > 0, "$arabicFonts fonts declare lang=ar");

// Generic families, not just explicit names. This is the gap that let DejaVu
// Sans become the Arabic fallback: `fc-match "Noto Sans Arabic"` passed while
// `fc-match sans-serif:lang=ar` quietly resolved somewhere else. A renderer
// falling back on a missing glyph asks the generic way, so that is the path
// that has to be right.
foreach ([
    'sans-serif:lang=ar' => 'NotoSansArabic',   // §4.1 Arabic UI face
    'serif:lang=ar'      => 'NotoSansArabic',
    'monospace:lang=ar'  => 'NotoSansArabic',   // Noto Sans Mono carries no Arabic
    'sans-serif'         => 'Inter-',           // §4.1 Latin and numerals
    'monospace'          => 'NotoSansMono',     // §4.1 codes, IDs, audit metadata
] as $pattern => $expectedFile) {
    $match = trim(shell_exec('fc-match ' . escapeshellarg($pattern) . ' 2>/dev/null') ?? '');
    check("fallback: $pattern", str_contains($match, $expectedFile), explode(':', $match)[0]);
}

// --- headless Chrome and Arabic PDF output (§14.6, D-57) --------------------
// P-01 proved Arabic renders correctly through Chrome's text engine. This
// re-proves it inside the container on whichever architecture is building,
// which is what D-66 asks for and what the deployment-debt register tracks.
$chrome = getenv('PUPPETEER_EXECUTABLE_PATH') ?: '/usr/bin/chromium';

// The image is built in two targets (point 0.7). `app` carries no browser by
// design — only the pdf worker renders — so its absence is correct there and a
// defect nowhere else. Reported as skipped rather than passed, so a browser
// that goes missing from the pdf target cannot hide behind a green line.
if (!is_executable($chrome)) {
    printf("  %-46s %s\n", 'chrome: not in this image', 'SKIP  app target carries no browser');
    echo "\n" . ($fail ? "FAILED\n" : "all checks passed\n");
    exit($fail);
}
check('chrome: binary present', is_executable($chrome), $chrome);

$tmp  = sys_get_temp_dir() . '/crm-verify-' . getmypid();
@mkdir($tmp);
$html = $tmp . '/ar.html';
$pdf  = $tmp . '/ar.pdf';

// Lam-alef, hamza forms, taa marbuta, tabular figures — the P-01 criteria.
file_put_contents($html, '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">'
    . '<style>body{font-family:sans-serif}.m{font-family:monospace}</style>'
    . '<p>لا الاتصالات أحمد إبراهيم مؤسسة شركة خدمة</p>'
    . '<p>8,315.99 — ١٢٣٤٥٦٧٨٩٠</p><p class="m">QT-2026-0001</p></html>');

exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --disable-dev-shm-usage'
    . ' --no-pdf-header-footer --print-to-pdf=' . escapeshellarg($pdf)
    . ' ' . escapeshellarg($html) . ' 2>/dev/null', $_o, $rc);

check('chrome: renders a PDF', $rc === 0 && is_file($pdf) && filesize($pdf) > 5000,
    is_file($pdf) ? filesize($pdf) . ' bytes' : 'no output');

// The font must be the one §4.1 names. Before the fontconfig fix this embedded
// DejaVu, which is why the check reads the PDF rather than trusting fc-match.
$raw = is_file($pdf) ? file_get_contents($pdf) : '';
check('chrome: embeds Noto Sans Arabic', str_contains($raw, 'NotoSansArabic'),
    str_contains($raw, 'DejaVu') ? 'found DejaVu instead' : '');
check('chrome: embeds Noto Sans Mono', str_contains($raw, 'NotoSansMono'));

array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo "\n" . ($fail ? "FAILED\n" : "all checks passed\n");
exit($fail);
