#!/usr/bin/env bash
# One command: prepare upstream, generate bindings from its real C headers,
# build/use its PIC static archives, and link a Zend-loadable .so.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPSTREAM="${WALLET_CORE_DIR:-$ROOT/../wallet-core}"
OUT="${BUILD_DIR:-$ROOT/build}"
PHP_CONFIG="${PHP_CONFIG:-php-config}"
PHP_BINARY="${PHP_BINARY:-php}"
CLANG="${CLANG:-clang}"
CC="${CC:-clang}"
CXX="${CXX:-clang++}"
CORE_BUILD="${WALLET_CORE_BUILD_DIR:-$UPSTREAM/build}"
CORE_LIB="${WALLET_CORE_LIB:-$CORE_BUILD/libTrustWalletCore.a}"
RUST_LIB="${WALLET_CORE_RUST_LIB:-$UPSTREAM/rust/target/release/libwallet_core_rs.a}"

# The native-only stage runs before PHP is built; php-config is required only
# when generating and compiling the Zend extension.
if [[ "${1:-}" != --native-only ]]; then
  command -v "$PHP_CONFIG" >/dev/null || {
    echo 'ERROR: php-config missing; install PHP development headers matching your PHP interpreter' >&2
    exit 2
  }
fi
command -v "$CLANG" >/dev/null || {
  echo "ERROR: clang missing: $CLANG" >&2
  exit 2
}
command -v "$CC" >/dev/null || {
  echo "ERROR: C compiler missing: $CC" >&2
  exit 2
}
command -v "$CXX" >/dev/null || {
  echo "ERROR: C++ compiler missing: $CXX" >&2
  exit 2
}
[[ -d "$UPSTREAM/include/TrustWalletCore" ]] || {
  echo "ERROR: Wallet Core C headers not found: $UPSTREAM" >&2
  exit 2
}
[[ "$CORE_LIB" == *.a ]] || {
  echo "ERROR: WALLET_CORE_LIB must point to .a, not .so: $CORE_LIB" >&2
  exit 2
}
mkdir -p "$OUT"
# Remove stale binaries first: a failed build must never look successful.
rm -f "$OUT/walletcore.so" "$OUT/walletcore.o"

if [[ ! -f "$CORE_LIB" || ! -f "$RUST_LIB" ]]; then
  if [[ "${WALLET_CORE_AUTO_BUILD:-1}" != 1 ]]; then
    echo "ERROR: missing Wallet Core static archives: $CORE_LIB or $RUST_LIB" >&2
    echo 'Supply matching WALLET_CORE_LIB/WALLET_CORE_RUST_LIB/WALLET_CORE_BUILD_DIR, or enable WALLET_CORE_AUTO_BUILD=1.' >&2
    exit 2
  fi
  [[ -x "$UPSTREAM/tools/generate-files" ]] || {
    echo 'ERROR: upstream tools/generate-files missing' >&2
    exit 2
  }
  command -v cmake >/dev/null || {
    echo 'ERROR: cmake is needed to build Wallet Core' >&2
    exit 2
  }
  echo 'Generating the native upstream artifacts and PIC Rust staticlib ...'
  (
    cd "$UPSTREAM"
    # The Rust staticlib must be suitable for use in a shared PHP module.
    export RUSTFLAGS="${RUSTFLAGS:+$RUSTFLAGS }-C relocation-model=pic"
    # Both the upstream core and the downloaded protobuf dependencies must be
    # PIC to link into PHP's ELF shared module. The upstream installer builds
    # its own protoc/plugins and should not be silently skipped in Docker.
    export CFLAGS="${CFLAGS:+$CFLAGS }-fPIC"
    export CXXFLAGS="${CXXFLAGS:+$CXXFLAGS }-fPIC"
    if [[ "${WALLET_CORE_INSTALL_DEPS:-1}" == 1 && \
          ( ! -x build/local/bin/protoc || ! -x build/local/bin/protoc-gen-c-typedef ) ]]; then
      [[ -x tools/install-dependencies ]] || {
        echo 'ERROR: upstream tools/install-dependencies missing' >&2
        exit 2
      }
      echo 'Installing upstream native dependencies with PIC ...'
      ./tools/install-dependencies
    fi
    # Wallet Core's rust-bindgen copies its staticlib into build/local/lib/.
    # GNUInstallDirs uses lib64 on some distributions (e.g. Fedora and EL9).
    # Preserve that install tree and expose the path expected by upstream.
    if [[ ! -e build/local/lib && ! -L build/local/lib ]]; then
      if [[ -d build/local/lib64 ]]; then
        ln -s lib64 build/local/lib
      else
        mkdir -p build/local/lib
      fi
    fi
    [[ -d build/local/lib ]] || {
      echo 'ERROR: Wallet Core native library directory is not usable: build/local/lib' >&2
      exit 2
    }
    ./tools/generate-files native
    cmake -S . -B "$CORE_BUILD" \
      -DCMAKE_BUILD_TYPE=Release -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
      -DTW_UNIT_TESTS=OFF -DTW_BUILD_EXAMPLES=OFF \
      -DFLUTTER=OFF -DTW_COMPILE_JAVA=OFF \
      -DCMAKE_C_COMPILER="${CORE_CC:-clang}" -DCMAKE_CXX_COMPILER="${CORE_CXX:-clang++}"
    cmake --build "$CORE_BUILD" --target TrustWalletCore --parallel "${BUILD_JOBS:-2}"
  )
fi
for file in "$CORE_LIB" "$RUST_LIB"; do
  [[ -f "$file" ]] || {
    echo "ERROR: required .a was not produced: $file" >&2
    exit 2
  }
done

# Docker's native stage stops here. It is cached independently of the PHP
# generator so changes to generated bindings never rebuild the C++/Rust core.
if [[ "${1:-}" == --native-only ]]; then
  echo 'Native Wallet Core archives ready (PHP generation deferred).'
  exit 0
fi

# The generated C ABI is taken from precisely the checked-out core version.
python3 "$ROOT/tools/codegen/generate.py" --headers "$UPSTREAM/include/TrustWalletCore" \
  --output "$OUT" --clang "$CLANG" --version "${WALLET_CORE_REF:-dev}"
# php-config supplies the installed PHP's C headers and feature macros, with
# no baked-in minor PHP version. shell splitting is intentional for flags.
read -r -a php_includes <<< "$("$PHP_CONFIG" --includes)"
# The Protobuf cleanup experiment is compiled only in the ASan diagnostic
# extension. Release builds must not globally shut down Protobuf, since other
# native extensions or embedded PHP lifecycles may share that runtime.
asan_extra_defines=()
if [[ "${WALLETCORE_SANITIZE:-}" == address ]]; then
  asan_extra_defines=(-DWC_ASAN_PROTOBUF_CLEANUP=1)
fi
# On failure report the FIRST errors, not just Clang's last warnings and
# "too many errors" message. Keep the full log inside the build container;
# only a successful build can export dist/ artifacts.
COMPILE_LOG="$OUT/walletcore-compile.log"
if ! "$CC" -std=c11 -fPIC -O2 -Wall -Wextra -Wno-unused-parameter \
    -ferror-limit=0 -fdiagnostics-color=never \
    -D_GNU_SOURCE -DCOMPILE_DL_WALLETCORE=1 \
    "${asan_extra_defines[@]}" "${php_includes[@]}" -I"$UPSTREAM/include" \
    -c "$OUT/walletcore.c" -o "$OUT/walletcore.o" >"$COMPILE_LOG" 2>&1; then
  echo 'ERROR: generated Wallet Core C bindings did not compile.' >&2
  echo 'First compiler errors with source context:' >&2
  if ! grep -n -m 15 -B 2 -A 4 'error:' "$COMPILE_LOG" >&2; then
    tail -n 100 "$COMPILE_LOG" >&2
  fi
  echo 'The full diagnostics are in build/walletcore-compile.log inside Docker.' >&2
  exit 1
fi
# Successful builds should still surface nontrivial compiler warnings.
if [[ -s "$COMPILE_LOG" ]]; then
  cat "$COMPILE_LOG" >&2
fi
# Link the Protobuf shutdown bridge into ASan only. It is deliberately linked
# against the SAME Protobuf headers/archive used to build Wallet Core.
extra_objects=""
if [[ "${WALLETCORE_SANITIZE:-}" == address ]]; then
  proto_include="$UPSTREAM/build/local/include"
  [[ -f "$proto_include/google/protobuf/message_lite.h" ]] || {
    echo "ERROR: Protobuf header unavailable for ASan cleanup: $proto_include" >&2
    exit 2
  }
  "$CXX" -fPIC -g -O1 -I"$proto_include" \
    -c "$ROOT/tools/asan/asan-protobuf-cleanup.cpp" -o "$OUT/asan-protobuf-cleanup.o"
  extra_objects="$OUT/asan-protobuf-cleanup.o"
fi
# Always invoke through Bash: ZIP extraction / host checkouts may drop +x,
# and the Docker build should not depend on source file execute bits.
CXX="$CXX" WC_EXTRA_OBJECTS="$extra_objects" bash "$ROOT/tools/build/link-static.sh" \
  "$OUT/walletcore.so" "$OUT/walletcore.o" "$CORE_LIB" "$CORE_BUILD" "$RUST_LIB"
"$PHP_BINARY" -n -d "extension=$OUT/walletcore.so" -r \
  'if (!extension_loaded("walletcore")) { exit(1); } echo "walletcore loaded on ", PHP_VERSION, "\n";'
