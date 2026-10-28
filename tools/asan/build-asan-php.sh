#!/usr/bin/env bash
# Build a matching ASan-instrumented PHP CLI for the diagnostic target.
# This is deliberately NOT used for the release image or on the host.
set -euo pipefail

PHP_ASAN_PREFIX="${PHP_ASAN_PREFIX:-/opt/php-asan}"
# PHP_VERSION may contain a distro package suffix (e.g. 8.1.2-1ubuntu2.28).
# Use the three numeric version components compiled into the PHP interpreter;
# source tarball names contain only the upstream X.Y.Z version.
PHP_ASAN_VERSION="${PHP_ASAN_VERSION:-$(php -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, ".", PHP_RELEASE_VERSION;')}"
BUILD_JOBS="${BUILD_JOBS:-2}"

if [[ ! "$PHP_ASAN_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "ERROR: invalid PHP_ASAN_VERSION: $PHP_ASAN_VERSION" >&2
  exit 2
fi
if [[ -x "$PHP_ASAN_PREFIX/bin/php" ]]; then
  echo "ERROR: refusing to overwrite existing ASan PHP at $PHP_ASAN_PREFIX" >&2
  exit 2
fi

# Match the version of PHP used in the ordinary Docker build rather than
# hardcoding a PHP release. The build must use its *own* matching headers.
workdir="$(mktemp -d /tmp/php-asan-src.XXXXXXXX)"
trap 'rm -rf "$workdir"' EXIT
# PHP git tags use php-X.Y.Z, unlike Ubuntu package revision strings.
# Use the same Git host as the already working Wallet Core checkout, rather
# than downloading old release tarballs from php.net (which can fail TLS EOF).
PHP_ASAN_REPO="${PHP_ASAN_REPO:-https://github.com/php/php-src.git}"
tag="php-${PHP_ASAN_VERSION}"
source="$workdir/php-src"
echo "Checking out PHP ${PHP_ASAN_VERSION} source from Git tag ${tag}"
# A shallow tag checkout limits transfer size. Retry transient network errors,
# but never relax TLS verification or silently fall back to another version.
for attempt in 1 2 3; do
  if git clone --quiet --depth 1 --single-branch --branch "$tag" \
      "$PHP_ASAN_REPO" "$source"; then
    break
  fi
  rm -rf -- "$source"
  if [[ "$attempt" == 3 ]]; then
    echo "ERROR: unable to fetch PHP release tag $tag from $PHP_ASAN_REPO" >&2
    exit 3
  fi
  echo "PHP source checkout failed (attempt $attempt/3); retrying..." >&2
  sleep "$attempt"
done
# Fail closed when a repository supplies the wrong tag/branch.
actual_tag="$(git -C "$source" describe --tags --exact-match 2>/dev/null || true)"
if [[ "$actual_tag" != "$tag" ]]; then
  echo "ERROR: expected Git tag $tag, found ${actual_tag:-none}" >&2
  exit 2
fi
echo "PHP source commit: $(git -C "$source" rev-parse HEAD)"
[[ -x "$source/buildconf" ]] || {
  echo "ERROR: PHP Git checkout has no executable buildconf for $tag" >&2
  exit 2
}
# PHP has an upstream sanitizer guard in Zend/zend_portability.h: it deliberately
# excludes RTLD_DEEPBIND when PHP itself is compiled under ASan.
grep -q 'defined(__SANITIZE_ADDRESS__)' "$source/Zend/zend_portability.h" || {
  echo 'ERROR: this PHP source lacks the sanitizer-aware Zend dlopen path' >&2
  exit 2
}

cd "$source"
# Git checkouts do not ship the generated ./configure found in release tarballs.
./buildconf --force
[[ -x ./configure ]] || {
  echo "ERROR: PHP buildconf did not generate configure for $tag" >&2
  exit 2
}
export CC="${PHP_ASAN_CC:-${CLANG:-clang}}"
export CXX="${PHP_ASAN_CXX:-${CORE_CXX:-clang++}}"
# Let PHP's OWN configure enable ASan, not just compiler flags: PHP 8.1
# additionally defines ZEND_TRACK_ARENA_ALLOC when this switch is enabled.
# Without it the Zend arena allocator is not built for sanitizer tracing.
export CFLAGS='-O1 -g -fno-omit-frame-pointer'
export CXXFLAGS='-O1 -g -fno-omit-frame-pointer'
# Old Clang ASan runtimes can fail before main() with high-entropy ASLR if
# the executable itself is PIE. Keep ASan instrumentation, but link the
# diagnostic PHP *executable* at a stable, non-PIE base. PHP extensions stay
# PIC shared objects; the release build remains unaffected.
export LDFLAGS='-fsanitize=address -no-pie'
# A debug CLI with no external SAPI. Disable PCRE JIT in the diagnostic binary
# so sanitizer checks only native allocations rather than generated JIT code.
configure_args=(--prefix="$PHP_ASAN_PREFIX" --disable-all --enable-cli
  --disable-cgi --disable-phpdbg --enable-debug
  --enable-address-sanitizer --without-pcre-jit
  --without-pear)
if [[ "$(php -n -r 'echo (int) PHP_ZTS;')" == 1 ]]; then
  configure_args+=(--enable-zts)
fi
./configure "${configure_args[@]}"
make --jobs="$BUILD_JOBS"
make install
# The runtime probe intentionally runs in a SEPARATE Docker layer. A crash in
# PHP must not invalidate the expensive compiler/install layer.
