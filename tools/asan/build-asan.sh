#!/usr/bin/env bash
# Instrumented wrapper around the unchanged release builder. Keeping this
# separate prevents ASan changes from invalidating the release-native cache.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# ASan must instrument Wallet Core libraries, not the build-time protoc tool.
# Docker's native stage installs an ordinary protoc and the protobuf plugins.
# Reuse those tools; never recompile executables with ASan during bootstrap.
if [[ "${1:-}" == --native-only ]]; then
  upstream="${WALLET_CORE_DIR:-$ROOT/../wallet-core}"
  for tool in protoc protoc-gen-c-typedef; do
    if [[ ! -x "$upstream/build/local/bin/$tool" ]]; then
      echo "ERROR: missing uninstrumented build tool: $upstream/build/local/bin/$tool" >&2
      echo 'The ASan Docker stage must inherit FROM native, not upstream.' >&2
      exit 2
    fi
  done
  if [[ -z "${WALLET_CORE_BUILD_DIR:-}" || "${WALLET_CORE_BUILD_DIR}" == "$upstream/build" ]]; then
    echo 'ERROR: ASan requires a distinct WALLET_CORE_BUILD_DIR (e.g. build-asan)' >&2
    exit 2
  fi
  if [[ "${WALLET_CORE_INSTALL_DEPS:-}" != 0 ]]; then
    echo 'ERROR: ASan must use the existing uninstrumented protoc (WALLET_CORE_INSTALL_DEPS=0)' >&2
    exit 2
  fi
fi
export CC="$ROOT/tools/asan/asan-cc.sh"
export CXX="$ROOT/tools/asan/asan-cxx.sh"
export PHP_BINARY="$ROOT/tools/asan/asan-php.sh"
exec bash "$ROOT/tools/build/build.sh" "$@"
