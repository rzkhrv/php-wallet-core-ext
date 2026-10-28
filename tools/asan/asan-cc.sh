#!/usr/bin/env bash
set -euo pipefail
exec "${CLANG:-clang}" -fsanitize=address -fno-omit-frame-pointer -g "$@"
