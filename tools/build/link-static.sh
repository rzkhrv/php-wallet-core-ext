#!/usr/bin/env bash
# Link the Zend module from PIC .o + all static Wallet Core archives.
# Linux ELF linker grouping resolves dependencies that cycle between .a files.
set -euo pipefail
if [[ $# -ne 5 ]]; then
  echo 'usage: link-static.sh OUTPUT_SO PHP_OBJECT CORE_A CORE_BUILD_DIR RUST_A' >&2
  exit 2
fi
OUTPUT="$1" OBJECT="$2" CORE_LIB="$3" CORE_BUILD="$4" RUST_LIB="$5"
CXX="${CXX:-clang++}"
command -v "$CXX" >/dev/null || {
  echo "ERROR: C++ linker missing: $CXX" >&2
  exit 2
}
for path in "$OBJECT" "$CORE_LIB" "$RUST_LIB"; do
  [[ -f "$path" ]] || {
    echo "ERROR: static link input is missing: $path" >&2
    exit 2
  }
done
[[ "$CORE_LIB" == *.a ]] || {
  echo 'ERROR: Wallet Core library must be a static .a archive' >&2
  exit 2
}
[[ -d "$CORE_BUILD" ]] || {
  echo "ERROR: Wallet Core build directory is missing: $CORE_BUILD" >&2
  exit 2
}
# Collect the archives actually produced by this upstream checkout.  Never
# take archives from a separate build/local or tests installation: mixing ABI
# versions can silently corrupt memory and pulling duplicates bloats the .so.
archives=("$CORE_LIB" "$RUST_LIB")
while IFS= read -r -d '' archive; do
  [[ "$archive" == "$CORE_LIB" || "$archive" == "$RUST_LIB" ]] && continue
  archives+=("$archive")
done < <(find "$CORE_BUILD" -type f -name '*.a' \
  ! -path '*/local/*' ! -path '*/tests/*' ! -path '*/test/*' -print0 | sort -z)
# For unusual upstream builds, add extra .a files with a colon-separated list.
if [[ -n "${WALLET_CORE_EXTRA_ARCHIVES:-}" ]]; then
  IFS=: read -ra extras <<< "$WALLET_CORE_EXTRA_ARCHIVES"
  for archive in "${extras[@]}"; do
    [[ -f "$archive" && "$archive" == *.a ]] || {
      echo "ERROR: invalid extra archive: $archive" >&2
      exit 2
    }
    archives+=("$archive")
  done
fi
# Neither -rpath nor a libTrustWalletCore.so is required: C++ and Rust are
# linked into this extension.  Linker flags can be extended for platform deps.
read -r -a extra_flags <<< "${WALLET_CORE_LINK_FLAGS:-}"
# The Rust tw_coin_registry::REGISTRY is a lazy_static HashMap with process-
# lifetime ownership. PHP normally dlcloses the extension at shutdown, before
# LSan runs, removing the global root while its heap allocations still exist.
# Keep the ASan-only DSO mapped through leak checking. Unlike a suppression,
# this preserves detection of genuinely lost allocations (and the .so remains
# fully instrumented). Never change the release extension's unload behavior.
if [[ "${WALLETCORE_SANITIZE:-}" == address ]]; then
  [[ "$(uname -s)" == Linux ]] || {
    echo 'ERROR: ASan NODELETE lifecycle requires Linux ELF' >&2
    exit 2
  }
  extra_flags+=(-Wl,-z,nodelete)
  echo 'ASan lifecycle: keeping Rust lazy_static registry rooted with ELF NODELETE'
fi
read -r -a extra_cxx_flags <<< "${WALLET_CORE_CXXFLAGS:-}"
read -r -a extra_objects <<< "${WC_EXTRA_OBJECTS:-}"
for object in "${extra_objects[@]}"; do
  [[ -f "$object" && "$object" == *.o ]] || {
    echo "ERROR: invalid additional object: $object" >&2
    exit 2
  }
done
if [[ "$(uname -s)" == 'Linux' ]]; then
  lib_group=(-Wl,--start-group "${archives[@]}" -Wl,--end-group)
  visibility=(-Wl,--exclude-libs,ALL)
else
  lib_group=("${archives[@]}")
  visibility=()
fi
rm -f "$OUTPUT"
printf 'Linking walletcore.so from %d static archives:\n' "${#archives[@]}"
printf '  %s\n' "${archives[@]}"
"$CXX" -shared -fPIC "${extra_cxx_flags[@]}" "$OBJECT" "${extra_objects[@]}" \
  "${lib_group[@]}" "${visibility[@]}" \
  -ldl -lpthread -lm -lz "${extra_flags[@]}" \
  -o "$OUTPUT"
if [[ "${WALLETCORE_SANITIZE:-}" == address ]]; then
  command -v readelf >/dev/null || {
    echo 'ERROR: readelf is required to verify ASan ELF NODELETE' >&2
    exit 2
  }
  # Old binutils may print hundreds of Rust DWARF-form warnings while
  # inspecting a newer Rust-produced .so. The ELF dynamic table remains
  # readable: check readelf's status and DT_FLAGS_1 without that noise.
  if ! dynamic_table="$(readelf -W -d "$OUTPUT" 2>/dev/null)"; then
    echo 'ERROR: cannot inspect ASan extension ELF dynamic table' >&2
    exit 2
  fi
  if [[ "$dynamic_table" != *NODELETE* ]]; then
    echo 'ERROR: ASan extension is missing ELF NODELETE; registry will appear leaked after dlclose' >&2
    exit 2
  fi
fi
# A shared object may legitimately leave PHP/Zend symbols unresolved until
# dlopen, but not Wallet Core C ABI symbols that are in the static archives.
if command -v nm >/dev/null && [[ "$(uname -s)" == 'Linux' ]]; then
  missing=$(nm -D --undefined-only "$OUTPUT" | awk '{print $NF}' | grep -E '^TW[A-Za-z_0-9]+$' || true)
  if [[ -n "$missing" ]]; then
    rm -f "$OUTPUT"
    printf 'ERROR: Wallet Core functions were not linked:\n%s\n' "$missing" >&2
    exit 2
  fi
fi
