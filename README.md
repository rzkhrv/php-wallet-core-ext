# Wallet Core PHP extension

Native PHP bindings for the [Trust Wallet Core C API](https://github.com/trustwallet/wallet-core). The Docker build compiles PHP, Wallet Core, and the extension from source.

## 1. Build the extension

Install Docker with Buildx and GNU Make. Python 3 is needed for the runtime test and publishing commands.

| Argument | Default | Purpose |
|---|---|---|
| `OS` | `debian` | Base distribution: `debian`, `alpine`, `ubuntu`, `fedora`, `rockylinux`, or `almalinux` |
| `OS_VERSION` | `13` | Release of the selected distribution |
| `PLATFORM` | `linux/amd64` | Target platform: `linux/amd64` or `linux/arm64` |
| `PHP_VERSION` | `8.5.12` | Exact PHP source version |
| `PHP_ZTS` | `0` | `0` for NTS, `1` for ZTS |
| `WALLET_CORE_REF` | `4.8.4` | Wallet Core tag, commit, or branch |
| `BUILD_JOBS` | `2` | Parallel compilation jobs |

For example, build for PHP 8.5 NTS on Alpine 3.24:

```sh
make build OS=alpine OS_VERSION=3.24 PLATFORM=linux/amd64 \
  PHP_VERSION=8.5.12 PHP_ZTS=0 WALLET_CORE_REF=4.8.7
```

The example writes to `build/alpine3.24-amd64-php8.5.12-nts-wc4.8.7/`. Its `artifacts/` directory contains `walletcore.so`, build metadata (`build-info.json`), a binding report (`abi.json`), and the generated PHP stub (`walletcore.stub.php`).

## 2. Run the tests

The build verifies the extension before exporting it. Use `make test` to run the native and PHP checks for the same profile, and `make runtime-test` to check the exported binary against your application image. That image needs PHP CLI and the shared libraries listed in `build-info.json`:

```sh
make test OS=alpine OS_VERSION=3.24 PLATFORM=linux/amd64 \
  PHP_VERSION=8.5.12 PHP_ZTS=0 WALLET_CORE_REF=4.8.7

make runtime-test OS=alpine OS_VERSION=3.24 PLATFORM=linux/amd64 \
  PHP_VERSION=8.5.12 PHP_ZTS=0 WALLET_CORE_REF=4.8.7 \
  RUNTIME_IMAGE=your-php-image:tag
```

The user configured in the runtime image must be able to read the mounted artifacts directory. Test logs are under `logs/` in the profile directory. AddressSanitizer checks are available with `make test-asan` for Debian builds.

## 3. Publish to GHCR (optional)

To share the verified artifacts, log in with a GitHub personal access token (classic) with `write:packages`, then publish to your package:

```sh
docker login ghcr.io -u YOUR_GITHUB_USERNAME

make publish OCI_IMAGE=ghcr.io/your-owner/walletcore-php \
  OS=alpine OS_VERSION=3.24 PLATFORM=linux/amd64 \
  PHP_VERSION=8.5.12 PHP_ZTS=0 WALLET_CORE_REF=4.8.7
```

This publishes the existing build under the tag `4.8.7-php8.5-alpine3.24-nts`. For a multi-architecture tag, build each platform first and pass the complete platform list as `OCI_PLATFORMS='linux/amd64 linux/arm64'`.

## 4. Install in your Docker project

For an existing `php:8.5-fpm-alpine3.24` application on Linux AMD64, add the following to its PHP runtime stage before switching to a non-root user. The example uses an already published image. Substitute your own image and tag if you published in step 3.

```dockerfile
COPY --from=ghcr.io/rzkhrv/php-wallet-core-ext:4.8.7-php8.5-alpine3.24-nts /walletcore.so /usr/local/lib/walletcore.so
RUN apk add --no-cache libstdc++ libgcc \
 && echo 'extension=/usr/local/lib/walletcore.so' > "$PHP_INI_DIR/conf.d/30-walletcore.ini" \
 && php --ri walletcore
```

To install your local build, put `artifacts/walletcore.so` in the application's Docker build context and replace the first line with `COPY walletcore.so /usr/local/lib/walletcore.so`.

For a different runtime, use an artifact that matches its CPU architecture, PHP module API, NTS/ZTS, and debug mode. Check `build-info.json` for the libc and required shared libraries.

## 5. Use from PHP

Derive a compressed public key from a fixed test key:

```php
<?php

use WalletCore\PrivateKey;

$key = PrivateKey::createWithData(hex2bin(str_repeat('00', 31) . '01'));
if ($key === null) {
    throw new RuntimeException('Invalid private key');
}
echo bin2hex($key->getPublicKeySecp256k1(true)->data()), PHP_EOL;
```

### IDE completion

`walletcore.stub.php` declares the generated `WalletCore` classes, constants, and method signatures. Use the stub from the same build as your `walletcore.so` so your editor sees the API available at runtime.

Copy the stub from your local build's `artifacts/` directory or extract `/walletcore.stub.php` from the same OCI image used in step 4. Put it in a directory indexed by your IDE, such as `stubs/` in your application. Keep it out of Composer autoload and avoid loading it with `require` or `include`. PHP gets the actual class implementations from the loaded extension.

## 6. License

[Apache License 2.0](LICENSE). Trust Wallet Core and third-party dependencies retain their own licenses.
