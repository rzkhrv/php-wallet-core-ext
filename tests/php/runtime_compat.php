<?php
/** Check the deployed PHP CLI ABI before loading the native module. */
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/build';
$info = json_decode((string)file_get_contents($root . '/build-info.json'), true, 512, JSON_THROW_ON_ERROR);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expect(hash_file('sha256', $root . '/walletcore.so') === ($info['walletcore_sha256'] ?? null), 'module SHA-256 mismatch');
$built = explode('.', (string)($info['php_version'] ?? ''));
$expect(count($built) === 3 && preg_match('/^[0-9]+$/', $built[0]) === 1 && preg_match('/^[0-9]+$/', $built[1]) === 1
    && (int)$built[0] === PHP_MAJOR_VERSION && (int)$built[1] === PHP_MINOR_VERSION,
    'PHP major/minor version mismatch');
$expect((bool)($info['php_zts'] ?? null) === (bool)PHP_ZTS, 'PHP ZTS/NTS mismatch');
$expect((bool)($info['php_debug'] ?? null) === (bool)PHP_DEBUG, 'PHP debug ABI mismatch');
$architecture = match (php_uname('m')) {
    'x86_64', 'amd64' => 'linux/amd64',
    'aarch64', 'arm64' => 'linux/arm64',
    default => throw new RuntimeException('Unsupported PHP runtime architecture'),
};
$expect($architecture === ($info['target_platform'] ?? null), 'Runtime architecture mismatch');

ob_start();
phpinfo(INFO_GENERAL);
$general = (string)ob_get_clean();
$expect(preg_match('/^PHP API\s*=>\s*([0-9]+)\s*$/m', $general, $match) === 1,
    'Runtime PHP API unavailable');
$expect($match[1] === (string)($info['php_api'] ?? ''), 'Zend module API mismatch');
// Official PHP Alpine images use musl, and other supported Linux images use glibc.
// This check is not a substitute for the real extension loader and DT_NEEDED resolution.
$libc = is_file('/etc/alpine-release') ? 'musl' : 'glibc';
$expect($libc === ($info['libc'] ?? null), 'Runtime libc family mismatch');
printf("Runtime ABI: PHP %s, API %s, %s, %s, %s\n", PHP_VERSION, $match[1],
    PHP_ZTS ? 'ZTS' : 'NTS', $architecture, $libc);
