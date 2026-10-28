# Wallet Core PHP extension

Native PHP extension generated from the [Trust Wallet Core C API](https://github.com/trustwallet/wallet-core). Wallet Core and PHP are compiled from source inside Docker; no PHP FFI or modifications to Wallet Core are required.

## Build

Requires Docker with Buildx, GNU Make and Python 3 on the host. The build starts from a Linux distribution image, not a prebuilt PHP image.

```bash
make build OS=debian OS_VERSION=13 PLATFORM=linux/amd64 \
  PHP_VERSION=8.4.27 PHP_ZTS=0 WALLET_CORE_REF=4.8.4
```

| Option | Default | Description |
|---|---|---|
| `OS` | `debian` | `debian`, `alpine`, `ubuntu`, `fedora`, `rockylinux`, `almalinux` |
| `OS_VERSION` | `13` | Distribution release / image tag |
| `PLATFORM` | `linux/amd64` | `linux/amd64` or `linux/arm64` |
| `PHP_VERSION` | `8.5.12` | Exact PHP source release |
| `PHP_ZTS` | `0` | `0` = NTS, `1` = ZTS |
| `WALLET_CORE_REF` | `4.8.4` | Wallet Core tag, commit or branch |
| `BUILD_JOBS` | `2` | Parallel compilation jobs |

Use `make update` to refresh the selected Wallet Core ref and rebuild. Non-native architectures require a native Buildx node or working QEMU emulation. For Rocky Linux, the base image is `rockylinux/rockylinux:<version>`; `OS_IMAGE` can override the image selection.

## Build matrix

`make matrix` builds every combination of targets, PHP versions and Wallet Core refs:

```bash
make matrix \
  MATRIX_TARGETS='debian:13:amd64:0 debian:13:arm64:0 alpine:3.24:arm64:1' \
  MATRIX_PHP_VERSIONS='8.4.27 8.5.12' \
  MATRIX_WALLET_CORE_REFS='4.7.0 4.8.4'
```

Each `MATRIX_TARGETS` entry is `OS:OS_VERSION:ARCH:PHP_ZTS`, where `ARCH` is `amd64` or `arm64`. The example runs 12 builds. By default, `make matrix` uses the selected single-build profile, `PHP_VERSION` and `WALLET_CORE_REF`. Supply `MATRIX_*` variables to build multiple combinations.

For a single-version cross-OS check:

```bash
make matrix \
  MATRIX_TARGETS='ubuntu:24.04:amd64:0 fedora:44:amd64:0 rockylinux:9:amd64:0' \
  MATRIX_PHP_VERSIONS=8.4.27 MATRIX_WALLET_CORE_REFS=4.8.4
```

All profiles use the same verified build and tests. Failures do not stop the remaining cells or overwrite previously successful artifacts. The latest report is `build/matrix.json`.

## Artifacts and tests

Each profile writes to `build/<os><release>-<arch>-php<version>-<nts|zts>-wc<ref>/`:

- `artifacts/`: `walletcore.so`, `walletcore.stub.php`, `abi.json`, `build-info.json`
- `logs/`: `build.log`, `test.log`, `asan.log`, `runtime.log` (when applicable)

```bash
make test OS=debian OS_VERSION=13 PHP_VERSION=8.4.27
make test-asan OS=debian OS_VERSION=13 PHP_VERSION=8.4.27
make runtime-test OS=debian OS_VERSION=13 PHP_VERSION=8.4.27 \
  RUNTIME_IMAGE=php:8.4-cli-trixie
make clean      # remove the selected build profile
make clean-all  # remove all build profiles and the matrix report
```

`make build` and `make test` run native and PHP verification in the build environment; `test-asan` is supported on Debian. `runtime-test` checks an existing `.so` against a separately selected PHP CLI image without changing that artifact. For full leak detection on ARM64, prefer native hardware: LeakSanitizer cannot always run under QEMU.

BuildKit reuses Git source and compiler caches (Cargo and `ccache`) across compatible rebuilds; cache mounts can be reclaimed by Docker. OS package installation is handled by `tools/docker/install-dependencies.sh`.

## Publish OCI artifacts (GHCR)

Publish **existing verified** `make build`/`make matrix` outputs, without recompiling PHP or Wallet Core. Authenticate once to `ghcr.io` with a GitHub token (classic) granting `write:packages`:

```bash
docker login ghcr.io -u YOUR_GITHUB_USERNAME
```

Publish the two profiles required by PHP-FPM (Alpine NTS) and FrankenPHP (Debian ZTS):

```bash
make publish OCI_IMAGE=ghcr.io/owner/walletcore-php OS=alpine OS_VERSION=3.24 PHP_VERSION=8.4.27 PHP_ZTS=0 WALLET_CORE_REF=4.8.4
make publish OCI_IMAGE=ghcr.io/owner/walletcore-php OS=debian OS_VERSION=13 PHP_VERSION=8.4.27 PHP_ZTS=1 WALLET_CORE_REF=4.8.4
```

This publishes `4.8.4-php8.4-alpine3.24-nts` and `4.8.4-php8.4-debian13-zts`. By default only `PLATFORM` (normally AMD64) is published. For a multi-arch tag, **first build both architectures**, then pass `OCI_PLATFORMS='linux/amd64 linux/arm64'` to each `make publish`. Publication validates the ELF architecture, `abi.json`, build metadata and SHA-256 before pushing. The generic tag is updated only after all requested architecture images are uploaded. A later single-platform publication replaces the generic manifest with that single architecture; specify the complete platform set when updating a multi-arch release.

Downstream Dockerfiles install the appropriate prebuilt library with:

```dockerfile
COPY --from=ghcr.io/owner/walletcore-php:4.8.4-php8.4-alpine3.24-nts /walletcore.so /tmp/walletcore.so
RUN install -m 0644 /tmp/walletcore.so "$(php -r 'echo ini_get("extension_dir");')/walletcore.so" \
 && echo 'extension=walletcore.so' > /usr/local/etc/php/conf.d/30-walletcore.ini \
 && php --ri walletcore >/dev/null
```

For Debian ZTS, use the `debian13-zts` tag. The target PHP must match the artifact’s **CPU architecture, libc, Zend module API, NTS/ZTS and debug mode**. Install any libraries listed in `/build-info.json` and verify with `php --ri walletcore` in the actual application image. Pin the OCI image by digest for immutable deployments. No registry credentials are stored in this repository.

Use `walletcore.stub.php` for IDE completion; never add it to Composer autoload.

## PHP example

```php
<?php

use WalletCore\PrivateKey;

$key = PrivateKey::createWithData(hex2bin(str_repeat('01', 32)));
if ($key === null) {
    throw new RuntimeException('Invalid private key');
}
echo bin2hex($key->data()), PHP_EOL;
```

The generated API and any unsupported C signatures are documented in `walletcore.stub.php` and `abi.json` inside the build artifacts.

## License

Copyright 2026 rzkhrv.

Licensed under the [Apache License, Version 2.0](LICENSE).

Trust Wallet Core and third-party dependencies remain subject
to their respective licenses.
