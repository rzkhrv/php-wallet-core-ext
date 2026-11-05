#!/usr/bin/env bash
# Run one sanitized test, keep its raw stderr/stdout and symbolize unloaded
# walletcore.so frames while the binary is still available in the Docker RUN.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [[ $# != 1 || "$1" != tests/php/*.php || ! -f "$ROOT/$1" ]]; then
  echo 'usage: asan-trace-test.sh tests/{runtime,binding_safety,integration,ownership_stress}.php' >&2
  exit 2
fi
case_name="$1"
log="$(mktemp /tmp/walletcore-asan-case.XXXXXXXX)" || exit 2
trap 'rm -f -- "$log"' EXIT
printf '\n========== ASAN CASE: %s ==========\n' "$case_name"
printf 'ASAN_DETECT_LEAKS=%s\n' "${ASAN_DETECT_LEAKS:-1}"
set +e
bash "$ROOT/tools/asan/run-asan-php.sh" -n \
  -d "extension=$ROOT/build/walletcore.so" \
  -d "auto_prepend_file=$ROOT/tools/asan/asan-module-maps.php" \
  "$ROOT/$case_name" 2>&1 | tee "$log"
pipeline_status=("${PIPESTATUS[@]}")
status=${pipeline_status[0]}
tee_status=${pipeline_status[1]}
printf 'ASAN_CASE_EXIT_CODE=%s script=%s\n' "$status" "$case_name"
# This does not replace the raw stacks; it enriches them. LeakSanitizer often
# reports <unknown module> at exit because PHP already unloaded walletcore.so.
if grep -q '<unknown module>' "$log"; then
  python3 "$ROOT/tools/asan/symbolize-asan-trace.py" "$log" || \
    echo 'WC_ASAN_SYMBOLIZER_ERROR: unable to enrich native stack (raw report retained)' >&2
fi
if [[ "$tee_status" != 0 ]]; then
  echo "ERROR: failed to capture case output (tee exit $tee_status)" >&2
  exit "$tee_status"
fi
exit "$status"
