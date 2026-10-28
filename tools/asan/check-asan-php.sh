#!/usr/bin/env bash
# Isolate ASan PHP startup from the walletcore extension and report failures.
set -euo pipefail
PHP_ASAN_BIN="${PHP_ASAN_BIN:-/opt/php-asan/bin/php}"
PHP_CONFIG="${PHP_CONFIG:-/opt/php-asan/bin/php-config}"
[[ -x "$PHP_ASAN_BIN" && -x "$PHP_CONFIG" ]] || {
  echo 'ERROR: missing installed ASan PHP / php-config' >&2
  exit 2
}
# In diagnostic builds, high-mmap-ASLR/old compiler-rt combinations may
# crash before producing any ASan report. A non-PIE CLI avoids the randomly
# placed executable mapping without disabling ASLR globally.
if command -v readelf >/dev/null 2>&1; then
  elf_type="$(LC_ALL=C readelf -h "$PHP_ASAN_BIN" | awk '/Type:/{print $2; exit}')"
  if [[ "$elf_type" != EXEC ]]; then
    echo "ERROR: ASan PHP CLI is ELF $elf_type, expected EXEC (non-PIE)" >&2
    exit 2
  fi
  echo 'ASan PHP ELF: EXEC (non-PIE)'
fi
unset LD_PRELOAD
export USE_ZEND_ALLOC=0
# This smoke checks ASan startup; leak checks are done later during tests.
export ASAN_OPTIONS="detect_leaks=0:halt_on_error=1:abort_on_error=1:fast_unwind_on_malloc=0:symbolize=1:verbosity=1"
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
run_probe() {
  local description="$1"
  shift
  echo "ASan PHP probe: $description"
  if "$PHP_ASAN_BIN" "$@"; then
    return 0
  else
    local status="$?"
    echo "ERROR: instrumented PHP failed ($description), exit $status" >&2
    echo "ASan runtime: $ASAN_OPTIONS" >&2
    echo "PHP ELF information:" >&2
    command -v file >/dev/null 2>&1 && file "$PHP_ASAN_BIN" >&2 || true
    command -v ldd >/dev/null 2>&1 && ldd "$PHP_ASAN_BIN" >&2 || true
    echo 'FAILURE occurred without loading walletcore.so.' >&2
    return "$status"
  fi
}
# Repeated startup probes are intentionally independent processes; a single
# lucky run cannot qualify a flaky sanitizer harness as healthy.
for attempt in 1 2 3 4 5 6 7 8; do
  run_probe "startup stability $attempt/8" -n -r 'echo "ASan PHP alive\n";'
done
run_probe 'startup/version' -n -v
run_probe 'required functions' -n -r '
foreach (["json_decode", "preg_match", "hash", "gc_collect_cycles"] as $function) {
    if (!function_exists($function)) {
        fwrite(STDERR, "ERROR: sanitized PHP missing required function $function\n");
        exit(2);
    }
}
echo "ASan PHP ready: ", PHP_VERSION, "\n";
'
"$PHP_CONFIG" --version
