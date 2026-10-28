#!/usr/bin/env bash
set -euo pipefail
exec "${CORE_CXX:-clang++}" -fsanitize=address -fno-omit-frame-pointer -g "$@"
