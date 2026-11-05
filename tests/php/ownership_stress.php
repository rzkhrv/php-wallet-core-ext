<?php
/**
 * Ownership ordering and repeated native allocations under Clang ASan.
 * This is deliberately separate from the fast release test suite.
 * All key material is a public test vector; no network access is required.
 */
declare(strict_types=1);

if (!extension_loaded('walletcore')) {
    throw new RuntimeException('walletcore extension not loaded');
}

function ownershipAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$scalar = hex2bin(str_repeat('00', 31) . '01');
$iterations = (int)(getenv('WALLETCORE_OWNERSHIP_ITERATIONS') ?: '1000');
ownershipAssert($iterations >= 1 && $iterations <= 10000, 'Bad iteration count');

gc_enable();
for ($i = 0; $i < $iterations; ++$i) {
    $key = \WalletCore\PrivateKey::createWithData($scalar);
    ownershipAssert($key instanceof \WalletCore\PrivateKey, "No private key at {$i}");

    // Native copies and public keys must outlive their originating objects.
    $copyKey = \WalletCore\PrivateKey::createCopy($key);
    ownershipAssert($copyKey instanceof \WalletCore\PrivateKey,
        "No private key copy at {$i}");
    unset($key);
    ownershipAssert($copyKey->data() === $scalar, "Key copy invalid at {$i}");
    $pub = $copyKey->getPublicKeySecp256k1(true);
    ownershipAssert($pub instanceof \WalletCore\PublicKey,
        "No public key at {$i}");
    unset($copyKey);
    ownershipAssert(strlen($pub->data()) === 33,
        "Public key outlived source incorrectly at {$i}");
    unset($pub);

    // DataVector::Get returns an owned copy (upstream C header contract).
    $vector = \WalletCore\DataVector::create();
    ownershipAssert($vector instanceof \WalletCore\DataVector, "Vector allocation failed at {$i}");
    $bytes = "\x00\xff\x7f" . pack('V', $i);
    $vector->add($bytes);
    ownershipAssert($vector->size() === 1, "Vector size mismatch at {$i}");
    $copy = $vector->get(0);
    unset($vector);
    ownershipAssert($copy === $bytes, "Vector byte copy mismatch at {$i}");
    unset($copy);

    // The native IndexAt return ownership is audited by releasing the path
    // before the returned index. ASan will flag any invalid borrowed pointer.
    if ($i % 10 === 0 && method_exists(\WalletCore\DerivationPath::class, 'IndexAt')) {
        $path = \WalletCore\DerivationPath::createWithString("m/44'/60'/0'/0/0");
        ownershipAssert($path instanceof \WalletCore\DerivationPath, "Path allocation failed at {$i}");
        $index = $path->indexAt(0);
        ownershipAssert($index instanceof \WalletCore\DerivationPathIndex, "Index allocation failed at {$i}");
        unset($path);
        ownershipAssert($index->value() === 44 && $index->hardened(), "Path index invalid at {$i}");
        unset($index);
    }

    if ($i % 20 === 0) {
        gc_collect_cycles();
    }
}
gc_collect_cycles();
echo "Ownership stress PASS: {$iterations} independent object chains\n";
