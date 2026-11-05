#!/usr/bin/env bash
# Only run PHP built *itself* with ASan. Do not interpose dlopen or preload ASan
# into Ubuntu's uninstrumented PHP: its RTLD_DEEPBIND and allocator semantics
# are incompatible with a reliable sanitizer test harness.
set -euo pipefail
[[ $# -gt 0 ]] || {
  echo 'usage: run-asan-php.sh -n ...' >&2
  exit 2
}
[[ "${ASAN_DETECT_LEAKS:-1}" == 0 || "${ASAN_DETECT_LEAKS:-1}" == 1 ]] || {
  echo 'ERROR: ASAN_DETECT_LEAKS must be 0 or 1' >&2
  exit 2
}
PHP_ASAN_BIN="${PHP_ASAN_BIN:-/opt/php-asan/bin/php}"
[[ -x "$PHP_ASAN_BIN" ]] || {
  echo "ERROR: missing instrumented PHP CLI: $PHP_ASAN_BIN" >&2
  exit 2
}
# The PHP CLI is ASan-linked at build time; LD_PRELOAD would introduce a second
# runtime and could conceal real reports via symbol interposition.
unset LD_PRELOAD
export USE_ZEND_ALLOC=0
export ASAN_OPTIONS="detect_leaks=${ASAN_DETECT_LEAKS:-1}:halt_on_error=1:abort_on_error=1:fast_unwind_on_malloc=0:symbolize=1"
export LSAN_OPTIONS='exitcode=23:print_suppressions=1'
# Prefer LLVM's generic symlink; distributions may ship only a
# versioned binary, so locate the one matching our selected Clang.
if command -v llvm-symbolizer >/dev/null 2>&1; then
  export ASAN_SYMBOLIZER_PATH="$(command -v llvm-symbolizer)"
else
  llvm_major="$("${CLANG:-clang}" -dumpversion 2>/dev/null | cut -d. -f1 || true)"
  if [[ "$llvm_major" =~ ^[0-9]+$ ]] && command -v "llvm-symbolizer-$llvm_major" >/dev/null 2>&1; then
    export ASAN_SYMBOLIZER_PATH="$(command -v "llvm-symbolizer-$llvm_major")"
  fi
fi
exec "$PHP_ASAN_BIN" "$@"
