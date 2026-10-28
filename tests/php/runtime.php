<?php
// Checks the generated native API and its Zend reflection, not fake methods.
if (!extension_loaded('walletcore')) {
    throw new RuntimeException('walletcore not loaded');
}
if (!class_exists('WalletCore\\PrivateKey')) {
    throw new RuntimeException('PrivateKey not registered');
}
if (!class_exists('WalletCore\\AnySigner')) {
    throw new RuntimeException('AnySigner not registered');
}
$method = new ReflectionMethod('WalletCore\\AnySigner', 'sign');
if (!$method->isStatic()) {
    throw new RuntimeException('Signer should be static');
}
if ($method->getName() !== 'sign') {
    throw new RuntimeException('Signer method casing is incorrect');
}
try {
    \WalletCore\AnySigner::sign(123, 60);
    throw new RuntimeException('Expected TypeError');
} catch (TypeError $e) {
    // Expected from Zend input validation.
}
$key = \WalletCore\PrivateKey::createWithData(hex2bin(str_repeat('01', 32)));
if (!$key || strlen($key->data()) !== 32) {
    throw new RuntimeException('Private key round trip failed');
}
// Calling through an ordinary PHP reference must behave like calling with
// the value itself; the binding must dereference a zval before type checking.
$privateKeyBytes = hex2bin(str_repeat('01', 32));
$bytesAlias =& $privateKeyBytes;
$aliasedKey = \WalletCore\PrivateKey::createWithData($bytesAlias);
if (!$aliasedKey || $aliasedKey->data() !== $privateKeyBytes) {
    throw new RuntimeException('Aliased PHP byte-string argument failed');
}
unset($aliasedKey, $bytesAlias, $privateKeyBytes, $key);

// Verify that methods whose names overlap other Wallet Core class names are
// assigned to their real native receiver. Only assert functions actually
// declared in the checked-out upstream version (no pinned Wallet Core API).
$report = json_decode(file_get_contents(__DIR__ . '/../../build/abi.json'), true, 512, JSON_THROW_ON_ERROR);
$receiverMethods = [
    'TWDerivationPathIndexAt' => ['DerivationPath', 'indexAt'],
    'TWStoredKeyEncryptionParameters' => ['StoredKey', 'encryptionParameters'],
];
foreach ($receiverMethods as $native => [$owner, $methodName]) {
    if (!isset($report['functions'][$native])) {
        continue;
    }
    if ($report['functions'][$native]['status'] !== 'generated') {
        throw new RuntimeException("Binding missing for $native");
    }
    $reflection = new ReflectionMethod("WalletCore\\$owner", $methodName);
    if ($reflection->isStatic()) {
        throw new RuntimeException("$native should use the native object receiver");
    }
}
if (isset($report['functions']['TWDerivationPathIndexAt'])) {
    $path = \WalletCore\DerivationPath::createWithString("m/44'/60'/0'/0/0");
    if (!$path) {
        throw new RuntimeException('Cannot construct derivation path');
    }
    $indexNumber = 0;
    $indexAlias =& $indexNumber;
    $index = $path->indexAt($indexAlias);
    if (!$index || $index->value() !== 44 || !$index->hardened()) {
        throw new RuntimeException('Derivation path index round trip failed');
    }
    unset($index, $path);
}
echo "Native type and ownership smoke checks passed\n";
