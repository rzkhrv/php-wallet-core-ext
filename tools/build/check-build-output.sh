#!/usr/bin/env bash
# Validate host output destinations before creating or deleting any directories.
set -euo pipefail
[[ $# -eq 7 ]] || {
  echo 'usage: check-build-output.sh BUILD_ROOT PHP_VERSION WALLET_CORE_REF OS OS_VERSION PLATFORM PHP_ZTS' >&2
  exit 2
}
build_root="$1"
php_version="$2"
core_ref="$3"
os="$4"
os_version="$5"
platform="$6"
php_zts="$7"
[[ "$os" == debian || "$os" == alpine || "$os" == ubuntu || "$os" == fedora || "$os" == rockylinux || "$os" == almalinux ]] || {
  echo "ERROR: unsupported OS: $os (expected debian, alpine, ubuntu, fedora, rockylinux, almalinux)" >&2
  exit 2
}
[[ "$os_version" =~ ^[0-9]+(\.[0-9]+)*$ ]] || {
  echo "ERROR: invalid OS_VERSION: $os_version" >&2
  exit 2
}
[[ "$platform" == linux/amd64 || "$platform" == linux/arm64 ]] || {
  echo "ERROR: unsupported PLATFORM: $platform" >&2
  exit 2
}
[[ "$php_zts" == 0 || "$php_zts" == 1 ]] || {
  echo "ERROR: PHP_ZTS must be 0 or 1: $php_zts" >&2
  exit 2
}

[[ "$php_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
  echo "ERROR: invalid PHP_VERSION: $php_version" >&2
  exit 2
}
[[ "$core_ref" =~ ^[A-Za-z0-9][A-Za-z0-9._/+\-]*$ ]] || {
  echo "ERROR: invalid WALLET_CORE_REF: $core_ref" >&2
  exit 2
}
root="$(pwd -P)"
expected="$root/build"
# BusyBox realpath has no GNU -m option. Python resolves even absent paths
# and follows existing symlinks, which is essential for safe clean targets.
resolve_path() {
  python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$1"
}
resolved="$(resolve_path "$build_root")"
if [[ "$resolved" != "$expected" && "$resolved" != "$expected/"* ]]; then
  echo "ERROR: BUILD_ROOT must be build/ or a descendant: $build_root" >&2
  exit 2
fi
abi_variant=nts
[[ "$php_zts" == 1 ]] && abi_variant=zts
case_name="${os}${os_version}-${platform#linux/}-php${php_version}-${abi_variant}-wc${core_ref//\//%2F}"
case_dir="$build_root/$case_name"
# The host profile has one flat name; validate the *actual* path used by
# Makefile, not the previous nested layout. Also reject symlinked parents of
# a custom BUILD_ROOT, including those resolving to paths inside build/.
relative_root="${build_root#build}"
if [[ "$build_root" == build || "$build_root" == build/* ]]; then
  profile_path="$expected"
  IFS='/' read -r -a root_parts <<< "${relative_root#/}"
  for part in "${root_parts[@]}"; do
    [[ -n "$part" ]] || continue
    profile_path="$profile_path/$part"
    if [[ -L "$profile_path" ]]; then
      echo "ERROR: build output path cannot be a symlink: $profile_path" >&2
      exit 2
    fi
  done
fi
for path in "$expected" "$resolved" "$case_dir" "$case_dir/artifacts" "$case_dir/logs"; do
  if [[ -L "$path" ]]; then
    echo "ERROR: build output path cannot be a symlink: $path" >&2
    exit 2
  fi
done
case_resolved="$(resolve_path "$case_dir")"
if [[ "$case_resolved" != "$expected/"* ]]; then
  echo "ERROR: build case would escape build/: $case_dir" >&2
  exit 2
fi
