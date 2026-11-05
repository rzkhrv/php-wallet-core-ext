# syntax=docker/dockerfile:1.6
ARG OS=debian
ARG OS_VERSION=13
ARG BASE_IMAGE=debian:13
FROM ${BASE_IMAGE} AS toolchain

ARG OS
ARG OS_VERSION
ARG TARGETPLATFORM
ARG TARGETARCH
ENV BUILD_TARGET_PLATFORM=${TARGETPLATFORM} \
    BUILD_OS=${OS} \
    BUILD_OS_VERSION=${OS_VERSION}

# Install distro prerequisites with the base image's /bin/sh (Alpine has
# no Bash until the installer has run). Changes to PHP/codegen do not affect it.
COPY tools/docker/install-dependencies.sh /usr/local/bin/install-dependencies.sh
RUN sh /usr/local/bin/install-dependencies.sh

ENV CC=clang \
    CXX=clang++ \
    CLANG=clang \
    CORE_CC=clang \
    CORE_CXX=clang++ \
    CARGO_HOME=/opt/cargo \
    RUSTUP_HOME=/opt/rustup \
    PATH=/opt/cargo/bin:/opt/php/bin:/opt/walletcore-ccache/bin:${PATH} \
    CCACHE_DIR=/var/cache/walletcore-ccache \
    CCACHE_MAXSIZE=4G \
    CFLAGS=-fPIC \
    CXXFLAGS=-fPIC \
    RUSTFLAGS="-C relocation-model=pic"

SHELL ["/bin/bash", "-o", "pipefail", "-c"]
# Docker default only; an upstream rust-toolchain(.toml) takes precedence.
ARG RUST_TOOLCHAIN=nightly-2025-12-11
# Cargo's Git checkouts (including move-language/move) and registry sources
# are shared across OS images (Cargo coordinates access with file locks);
# compiled Rust artifacts remain image-local.
RUN --mount=type=cache,id=walletcore-cbindgen-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/opt/cargo-cbindgen-target,sharing=locked \
    --mount=type=cache,id=walletcore-cargo-git-v1,target=/opt/cargo/git,sharing=shared \
    --mount=type=cache,id=walletcore-cargo-registry-v1,target=/opt/cargo/registry,sharing=shared \
    curl --proto '=https' --tlsv1.2 -fsSL https://sh.rustup.rs \
      | sh -s -- -y --no-modify-path --profile minimal --default-toolchain "${RUST_TOOLCHAIN}" \
    && CARGO_TARGET_DIR=/opt/cargo-cbindgen-target cargo install cbindgen --locked \
    && clang --version \
    && rustc --version

FROM toolchain AS upstream
ARG WALLET_CORE_REPO="https://github.com/trustwallet/wallet-core.git"
ARG WALLET_CORE_REF="4.8.4"
ARG WALLET_CORE_REFRESH=0
ENV WALLET_CORE_REF="${WALLET_CORE_REF}"
WORKDIR /work/extension
COPY tools/build/checkout-wallet-core.sh /work/extension/tools/build/checkout-wallet-core.sh
# Share Git objects across OS images; the checkout itself remains layer-local.
# Cache is shared across builds and serialized to protect concurrent fetches.
RUN --mount=type=cache,id=walletcore-git-v1,target=/var/cache/walletcore-git,sharing=locked \
    WALLET_CORE_GIT_CACHE=/var/cache/walletcore-git \
    WALLET_CORE_REFRESH="${WALLET_CORE_REFRESH}" \
    WALLET_CORE_DIR=/work/wallet-core \
    WALLET_CORE_REPO="${WALLET_CORE_REPO}" \
    WALLET_CORE_REF="${WALLET_CORE_REF}" \
    bash /work/extension/tools/build/checkout-wallet-core.sh

# rustup uses Wallet Core's own rust-toolchain(.toml) when present. Without
# an upstream pin it uses the standard installed toolchain; no local selection
# or dependency compatibility logic is applied to upstream.
# Native compilation is independent from the PHP bindings generator and
# stays cached when tools/codegen/generate.py, tests, or the Zend build change.
FROM upstream AS native
ARG TARGETARCH
ARG BUILD_JOBS=2
# Cargo's target directories are cache mounts, so the resulting staticlib
# must be copied out explicitly before RUN finishes.
ENV WALLET_CORE_DIR=/work/wallet-core \
    BUILD_DIR=/work/extension/build \
    WALLET_CORE_INSTALL_DEPS=1
COPY tools/build/build.sh /work/extension/tools/build/build.sh
# Ccache is safe for concurrent writers; Rust target directories are not, so
# they use locked mounts. Separate per distro/release/architecture to avoid
# mixing libc, compilers, native build scripts and architecture outputs.
RUN --mount=type=cache,id=walletcore-ccache-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/var/cache/walletcore-ccache,sharing=shared \
    --mount=type=cache,id=walletcore-rust-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/work/wallet-core/rust/target,sharing=locked \
    --mount=type=cache,id=walletcore-codegen-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/work/wallet-core/codegen-v2/target,sharing=locked \
    --mount=type=cache,id=walletcore-cargo-git-v1,target=/opt/cargo/git,sharing=shared \
    --mount=type=cache,id=walletcore-cargo-registry-v1,target=/opt/cargo/registry,sharing=shared \
    bash tools/build/build.sh --native-only \
    && mkdir -p /work/wallet-core/build/local/lib \
    && cp /work/wallet-core/rust/target/release/libwallet_core_rs.a \
          /work/wallet-core/build/local/lib/libwallet_core_rs.a \
    && test -s /work/wallet-core/build/local/lib/libwallet_core_rs.a \
    && ccache --show-stats

# PHP source is independent of Wallet Core. Build it directly from toolchain
# so switching Wallet Core refs never recompiles the same PHP/Zend ABI.
FROM toolchain AS php_toolchain
ARG PHP_VERSION=8.5.12
ARG PHP_ZTS=0
ARG BUILD_JOBS=2
ENV BUILD_PHP_ZTS=${PHP_ZTS}
COPY tools/build/build-php.sh /work/extension/tools/build/build-php.sh
RUN --mount=type=cache,id=walletcore-ccache-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/var/cache/walletcore-ccache,sharing=shared \
    --mount=type=cache,id=php-src-git-v1,target=/var/cache/php-git,sharing=locked \
    PHP_BUILD_WORKDIR=/tmp/walletcore-php-build \
    PHP_GIT_CACHE=/var/cache/php-git \
    PHP_VERSION="$PHP_VERSION" PHP_ZTS="$PHP_ZTS" BUILD_JOBS="$BUILD_JOBS" \
      bash /work/extension/tools/build/build-php.sh \
    && ccache --show-stats \
    && test "$(php-config --version)" = "$PHP_VERSION" \
    && php -n -r 'if (PHP_VERSION !== $argv[1] || (int) PHP_ZTS !== (int) $argv[2]) { exit(2); }' \
       "$PHP_VERSION" "$PHP_ZTS"

FROM native AS verified
ENV WALLET_CORE_RUST_LIB=/work/wallet-core/build/local/lib/libwallet_core_rs.a
ARG BUILD_JOBS=2
ARG PHP_ZTS=0
ENV BUILD_PHP_ZTS=${PHP_ZTS}
COPY --from=php_toolchain /opt/php /opt/php
WORKDIR /work/extension
COPY . /work/extension/

RUN bash tools/build/build.sh \
    && python3 -m unittest discover -s tests/unit -p 'test_*.py' -v \
    && php -n -d extension=/work/extension/build/walletcore.so tests/php/runtime.php \
    && php -n -d extension=/work/extension/build/walletcore.so tests/php/binding_safety.php \
    && php -n -d extension=/work/extension/build/walletcore.so tests/php/integration.php \
    && bash tools/build/export-artifacts.sh

# Opt-in AddressSanitizer build. Start with the cached release-native stage
# ONLY for its build-time tools (protoc/plugins) and downloaded source trees.
# protoc is a host executable: do NOT instrument and execute it during the
# dependency bootstrap. Wallet Core/C++/protobuf/TrezorCrypto are rebuilt in
# an isolated CMake build directory with ASan; release .a are not linked.
# Normal "make" and "make test" do NOT execute this branch.
FROM native AS asan_native
ENV WALLET_CORE_RUST_LIB=/work/wallet-core/build/local/lib/libwallet_core_rs.a
ARG BUILD_JOBS=2
ARG PHP_ZTS=0
ENV BUILD_PHP_ZTS=${PHP_ZTS}
COPY --from=php_toolchain /opt/php /opt/php
# Use compiler-rt from the same Clang that builds the diagnostic module;
# fail if the runtime for the target architecture is missing.
RUN set -eu; \
    case "$(uname -m)" in \
      x86_64|amd64) asan_arch=x86_64 ;; \
      aarch64|arm64) asan_arch=aarch64 ;; \
      *) echo 'ERROR: unsupported ASan architecture' >&2; exit 2 ;; \
    esac; \
    runtime="$("$CLANG" -print-file-name="libclang_rt.asan-${asan_arch}.so")"; \
    if [ ! -f "$runtime" ]; then \
      echo "ERROR: Clang ASan runtime unavailable for $CLANG: $runtime" >&2; \
      exit 2; \
    fi; \
    echo "Found Clang ASan runtime: $runtime"
ENV WALLET_CORE_DIR=/work/wallet-core \
    BUILD_DIR=/work/extension/build \
    WALLET_CORE_BUILD_DIR=/work/wallet-core/build-asan \
    WALLET_CORE_INSTALL_DEPS=0 \
    WALLETCORE_SANITIZE=address \
    CFLAGS="-fPIC -O1 -g -fsanitize=address -fno-omit-frame-pointer" \
    CXXFLAGS="-fPIC -O1 -g -fsanitize=address -fno-omit-frame-pointer"
COPY tools/build/build.sh /work/extension/tools/build/build.sh
COPY --chmod=755 tools/asan/build-asan.sh tools/asan/asan-cc.sh tools/asan/asan-cxx.sh \
    tools/asan/asan-php.sh tools/asan/run-asan-php.sh /work/extension/tools/asan/
RUN --mount=type=cache,id=walletcore-ccache-asan-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/var/cache/walletcore-ccache,sharing=shared \
    --mount=type=cache,id=walletcore-rust-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/work/wallet-core/rust/target,sharing=locked \
    --mount=type=cache,id=walletcore-codegen-${OS}-${OS_VERSION}-${TARGETARCH}-v1,target=/work/wallet-core/codegen-v2/target,sharing=locked \
    --mount=type=cache,id=walletcore-cargo-git-v1,target=/opt/cargo/git,sharing=shared \
    --mount=type=cache,id=walletcore-cargo-registry-v1,target=/opt/cargo/registry,sharing=shared \
    bash tools/asan/build-asan.sh --native-only

# Build the PHP *interpreter* with ASan, using its own matching php-config.
# Zend automatically avoids RTLD_DEEPBIND when compiled with ASan; no dlopen
# shim, mixed sanitizer runtimes, or LD_PRELOAD hacks are needed.
FROM asan_native AS asan_php
ARG BUILD_JOBS=2
ARG PHP_ASAN_VERSION=""
# The instrumented-PHP flow is tested on glibc; do not silently claim
# sanitizer support on musl until that runtime is verified.
RUN if [[ "$BUILD_OS" != debian ]]; then \
      echo 'ERROR: test-asan currently supports Debian only (Alpine release builds are independent)' >&2; exit 2; \
    fi
COPY tools/asan/build-asan-php.sh tools/asan/check-asan-php.sh /work/extension/tools/asan/
# A tiny ASan executable validates the runtime BEFORE the long PHP compile.
RUN printf 'int main(void) { return 0; }\n' | \
    "$CLANG" -x c - -fsanitize=address -no-pie -o /tmp/asan-runtime-smoke \
    && ASAN_OPTIONS=detect_leaks=0 /tmp/asan-runtime-smoke
# ASan uses the exact release-PHP version; a mismatched explicit override
# must not silently produce a module for another Zend ABI.
RUN release_php_version="$(php -n -r 'echo PHP_VERSION;')" \
    && if [[ -n "$PHP_ASAN_VERSION" && "$PHP_ASAN_VERSION" != "$release_php_version" ]]; then \
         echo "ERROR: PHP_ASAN_VERSION=$PHP_ASAN_VERSION differs from selected PHP_VERSION=$release_php_version" >&2; exit 2; \
       fi \
    && BUILD_JOBS="$BUILD_JOBS" PHP_ASAN_VERSION="$release_php_version" \
       bash /work/extension/tools/asan/build-asan-php.sh
ENV PHP_CONFIG=/opt/php-asan/bin/php-config \
    PHP_ASAN_BIN=/opt/php-asan/bin/php
# A failure here must NOT discard the cached PHP compilation layer above.
RUN bash /work/extension/tools/asan/check-asan-php.sh

FROM asan_php AS asan_verified
ARG BUILD_JOBS=2
ARG ASAN_DETECT_LEAKS=1
WORKDIR /work/extension
COPY . /work/extension/
# COPY may reset executable bits inherited from the previous Docker stage.
RUN chmod +x tools/asan/asan-cc.sh tools/asan/asan-cxx.sh tools/asan/asan-php.sh \
    && bash tools/asan/build-asan.sh \
    && python3 -m unittest discover -s tests/unit -p 'test_*.py' -v \
    && echo 'ASan/LSan baseline: instrumented PHP without walletcore.so' \
    && ASAN_DETECT_LEAKS="${ASAN_DETECT_LEAKS}" bash tools/asan/run-asan-php.sh \
       -n -r 'echo "Instrumented PHP baseline passed\n";' \
    && ASAN_DETECT_LEAKS="${ASAN_DETECT_LEAKS}" bash tools/asan/asan-trace-test.sh \
       tests/php/runtime.php \
    && ASAN_DETECT_LEAKS="${ASAN_DETECT_LEAKS}" bash tools/asan/asan-trace-test.sh \
       tests/php/binding_safety.php \
    && ASAN_DETECT_LEAKS="${ASAN_DETECT_LEAKS}" bash tools/asan/asan-trace-test.sh \
       tests/php/integration.php \
    && ASAN_DETECT_LEAKS="${ASAN_DETECT_LEAKS}" bash tools/asan/asan-trace-test.sh \
       tests/php/ownership_stress.php

FROM scratch AS artifacts
COPY --from=verified /work/export/ /
