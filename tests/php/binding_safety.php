<?php
/**
 * ABI-wide Zend input-validation smoke test.
 *
 * Every generated method with parameters receives an invalid first argument.
 * The wrapper MUST reject it before entering native Wallet Core code. For
 * zero-argument instance methods, an uninitialized receiver MUST be rejected.
 * This does NOT prove correctness for valid calls or leak freedom.
 */
declare(strict_types=1);

if (!extension_loaded('walletcore')) {
    throw new RuntimeException('walletcore extension must be loaded');
}
$abiPath = dirname(__DIR__, 2) . '/build/abi.json';
$report = json_decode((string)file_get_contents($abiPath), true, 512, JSON_THROW_ON_ERROR);
if (!empty($report['header_errors'])) {
    throw new RuntimeException('ABI report contains failed headers');
}

$checkedTypes = 0;
$checkedBounds = 0;
$checkedReceivers = 0;
$checkedNullable = 0;
$generated = 0;

/** Invokes a method using a synthetic, NOT natively initialized receiver. */
function wcSafetyInvoke(ReflectionMethod $method, array $args): mixed
{
    $receiver = $method->isStatic() ? null : new ($method->getDeclaringClass()->getName())();
    return $method->invokeArgs($receiver, $args);
}

/** Verify an exception is thrown before native invocation. */
function wcSafetyExpect(string $context, string $exception, callable $callback, ?string $message = null): void
{
    try {
        $callback();
    } catch (Throwable $thrown) {
        if (!($thrown instanceof $exception)) {
            throw new RuntimeException("$context: expected $exception, got " . get_class($thrown) . ': ' . $thrown->getMessage(), 0, $thrown);
        }
        if ($message !== null && !str_contains($thrown->getMessage(), $message)) {
            throw new RuntimeException("$context: wrong error message: " . $thrown->getMessage(), 0, $thrown);
        }
        return;
    }
    throw new RuntimeException("$context: malformed call reached native code or returned successfully");
}

foreach ($report['functions'] as $native => $info) {
    if ($info['status'] !== 'generated') {
        continue;
    }
    $generated++;
    $class = 'WalletCore\\' . $info['owner'];
    $methodName = $info['method'];
    if (!method_exists($class, $methodName)) {
        throw new RuntimeException("Missing generated $class::$methodName ($native)");
    }
    $method = new ReflectionMethod($class, $methodName);
    if ($method->getName() !== $methodName || !preg_match('/^[a-z]/', $methodName)) {
        throw new RuntimeException("Non-canonical PHP method spelling for $native: $methodName");
    }
    if ($method->isStatic() === (bool)$info['instance']) {
        throw new RuntimeException("Incorrect static/instance registration for $native");
    }
    $params = $info['params']; // ABI already excludes a native instance receiver.
    $count = count($params);
    if ($method->getNumberOfRequiredParameters() !== $count || $method->getNumberOfParameters() !== $count) {
        throw new RuntimeException("Wrong PHP arity for $native");
    }
    if ($count === 0) {
        if (!$method->isStatic()) {
            wcSafetyExpect($native . ' uninitialized receiver', Exception::class,
                static fn() => wcSafetyInvoke($method, []), 'Native object was released');
            $checkedReceivers++;
        }
        // No-arg static methods may allocate a key or touch crypto state;
        // they are NOT invoked without valid test vectors here.
        continue;
    }

    // Passing a generic object is invalid for every supported C parameter
    // kind (native objects require an exact WalletCore class).
    $args = array_fill(0, $count, null);
    $args[0] = new stdClass();
    wcSafetyExpect($native . ' invalid first argument', TypeError::class,
        static fn() => wcSafetyInvoke($method, $args));
    $checkedTypes++;

    // Numeric range checks are generated before the native function call.
    // All remaining arguments can therefore be deliberately invalid/null.
    $firstType = preg_replace('/\b(?:const|volatile|_Nonnull|_Nullable)\b/', '', $params[0]['type']);
    $firstType = trim((string)$firstType);
    if (preg_match('/^(?:uint(?:8|16|32|64)_t|size_t)$/', $firstType)) {
        $args[0] = -1;
        wcSafetyExpect($native . ' negative unsigned argument', ValueError::class,
            static fn() => wcSafetyInvoke($method, $args));
        $checkedBounds++;
    } elseif (in_array($firstType, ['int8_t', 'int16_t', 'int32_t'], true)) {
        $max = ['int8_t' => 127, 'int16_t' => 32767, 'int32_t' => 2147483647][$firstType];
        if (PHP_INT_MAX > $max) {
            $args[0] = PHP_INT_MAX;
            wcSafetyExpect($native . ' oversized signed argument', ValueError::class,
                static fn() => wcSafetyInvoke($method, $args));
            $checkedBounds++;
        }
    }
}

// Nullable means an actual PHP null may be passed, NOT a fake Zend wrapper
// with a null native pointer. These calls deliberately use a fake StoredKey
// receiver, so *both* paths must throw before any native C call takes place.
foreach (['TWStoredKeyAccountForCoin' => ['accountForCoin', 1],
          'TWStoredKeyAccountForCoinDerivation' => ['accountForCoinDerivation', 2]] as $native => [$method, $index]) {
    $info = $report['functions'][$native] ?? null;
    if ($info === null || $info['status'] !== 'generated') {
        continue; // Other upstream versions may omit these C symbols.
    }
    $stored = new \WalletCore\StoredKey();
    $uninitializedWallet = new \WalletCore\HDWallet();
    $args = $method === 'accountForCoin'
        ? [\WalletCore\CoinType::Bitcoin, $uninitializedWallet]
        : [\WalletCore\CoinType::Bitcoin, 0, $uninitializedWallet];
    wcSafetyExpect($native . ' uninitialized nullable object', Exception::class,
        static fn() => $stored->{$method}(...$args), 'Native argument object is not initialized');
    $args[$index] = null;
    wcSafetyExpect($native . ' actual nullable null', Exception::class,
        static fn() => $stored->{$method}(...$args), 'Native object was released');
    $checkedNullable++;
}

if ($generated === 0 || ($checkedTypes + $checkedReceivers) === 0) {
    throw new RuntimeException('ABI safety audit did not exercise any methods');
}
printf("Binding safety PASS: %d generated methods; %d invalid types, %d ranges, %d empty receivers, %d nullable pointers\n",
    $generated, $checkedTypes, $checkedBounds, $checkedReceivers, $checkedNullable);
