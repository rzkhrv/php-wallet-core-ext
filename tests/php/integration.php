<?php
/**
 * Basic smoke tests of the generated PHP-to-Wallet-Core C ABI bridge.
 * No coin-specific serialization, address vectors, or transaction logic.
 */
declare(strict_types=1);

function wcAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function wcTest(string $label, callable $callback): void
{
    $callback();
    echo "PASS {$label}\n";
}

wcTest('module and generated ABI coverage', static function (): void {
    wcAssert(extension_loaded('walletcore'), 'extension not loaded');
    $report = json_decode((string)file_get_contents(__DIR__ . '/../../build/abi.json'),
        true, 512, JSON_THROW_ON_ERROR);
    wcAssert(($report['header_errors'] ?? null) === [], 'unparsed C API headers');
    $counts = array_count_values(array_column($report['functions'], 'status'));
    wcAssert(($counts['generated'] ?? 0) > 0, 'no generated methods');
    printf("  ABI: %d generated, %d excluded; %d headers\n",
        $counts['generated'] ?? 0, $counts['excluded'] ?? 0, $report['parsed_header_count']);
});

// Known valid test key exercises data marshalling, owned native objects and
// return-value conversion. No wallet/network/transaction semantics are tested.
$keyBytes = hex2bin(str_repeat('00', 31) . '01');
wcTest('native private key, copy, public key, and release', static function () use ($keyBytes): void {
    $key = \WalletCore\PrivateKey::createWithData($keyBytes);
    wcAssert($key instanceof \WalletCore\PrivateKey, 'could not construct private key');
    wcAssert($key->data() === $keyBytes, 'binary argument/return corruption');
    $copy = \WalletCore\PrivateKey::createCopy($key);
    wcAssert($copy instanceof \WalletCore\PrivateKey, 'copy failed');
    unset($key);
    gc_collect_cycles();
    wcAssert($copy->data() === $keyBytes, 'copy outlived source incorrectly');
    $public = $copy->getPublicKeySecp256k1(true);
    wcAssert($public instanceof \WalletCore\PublicKey, 'public key creation failed');
    wcAssert(strlen($public->data()) === 33, 'unexpected public key data size');
    unset($copy);
    gc_collect_cycles();
    wcAssert(strlen($public->data()) === 33, 'public key outlived source incorrectly');
});

wcTest('native sign/verify round-trip', static function () use ($keyBytes): void {
    $key = \WalletCore\PrivateKey::createWithData($keyBytes);
    $pub = $key->getPublicKeySecp256k1(true);
    $digest = hash('sha256', 'walletcore-php-bridge-smoke', true);
    $signature = $key->signAsDER($digest);
    wcAssert(is_string($signature) && $signature !== '', 'empty native signature');
    wcAssert($pub->verifyAsDER($signature, $digest), 'native signature rejected');
    wcAssert(!$pub->verifyAsDER($signature, hash('sha256', 'different', true)),
        'native signature accepted for different digest');
});

echo "Native Wallet Core bridge smoke checks passed\n";
