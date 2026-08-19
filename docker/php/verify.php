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

echo "\n" . ($fail ? "FAILED\n" : "all checks passed\n");
exit($fail);
