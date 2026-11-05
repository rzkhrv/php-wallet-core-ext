#!/usr/bin/env bash
# Record all Docker stdout/stderr even when the build fails.
set -uo pipefail
if [[ $# -lt 2 ]]; then
  echo 'usage: capture-build-log.sh LOG_FILE command [args ...]' >&2
  exit 2
fi
log_file="$1"
shift
mkdir -p -- "$(dirname -- "$log_file")" || exit 2
set +e
"$@" 2>&1 | tee -- "$log_file"
statuses=("${PIPESTATUS[@]}")
if [[ ${statuses[1]} -ne 0 ]]; then
  echo "ERROR: cannot write build log $log_file" >&2
  exit "${statuses[1]}"
fi
if [[ ${statuses[0]} -ne 0 ]]; then
  echo "ERROR: Docker command failed (exit ${statuses[0]}). Full log: $log_file" >&2
fi
exit "${statuses[0]}"
