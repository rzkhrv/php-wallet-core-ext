#!/usr/bin/env bash
# Host-only Docker build log capture. The work still runs entirely in Docker.
# Unlike an artifact COPY/--output, this file survives a failed Docker RUN.
set -uo pipefail
if [[ $# -lt 2 ]]; then
  echo 'usage: capture-asan-trace.sh TRACE_FILE docker buildx build ...' >&2
  exit 2
fi
trace_file="$1"
shift
mkdir -p -- "$(dirname -- "$trace_file")" || exit 2
# Never conceal the Docker exit code behind tee. Capture ALL output (stdout,
# stderr, native stderr, ASan/LSan reports, symbolized frames and test names).
set +e
(
  printf '%s\n' '========== WALLETCORE ASAN TRACE =========='
  printf 'started_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf 'wallet_core_ref=%s\n' "${WALLET_CORE_REF:-unspecified}"
  printf 'asan_detect_leaks=%s\n' "${ASAN_DETECT_LEAKS:-1}"
  printf 'trace_file=%s\n' "$trace_file"
  printf 'docker_command='; printf '%q ' "$@"; printf '\n'
  printf '%s\n' '========== BUILD OUTPUT =========='
  "$@"
  result=$?
  printf '\n%s\n' '========== BUILD RESULT =========='
  printf 'docker_exit_code=%s\n' "$result"
  printf 'finished_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  exit "$result"
) 2>&1 | tee -- "$trace_file"
pipeline_status=("${PIPESTATUS[@]}")
status=${pipeline_status[0]}
tee_status=${pipeline_status[1]}
if [[ "$tee_status" != 0 ]]; then
  echo "ERROR: cannot write ASan trace to $trace_file (tee exit $tee_status)" >&2
  exit "$tee_status"
fi
if [[ "$status" != 0 ]]; then
  printf 'ASan build failed (exit %s). Full diagnostic file: %s\n' "$status" "$trace_file" >&2
else
  printf 'ASan build passed. Full diagnostic file: %s\n' "$trace_file"
fi
exit "$status"
