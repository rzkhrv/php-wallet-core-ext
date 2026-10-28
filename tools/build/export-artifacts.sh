#!/usr/bin/env bash
# Export only the installable binary, IDE stub and reproducibility metadata.
# The generated C source remains available inside Docker's build directory.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
UPSTREAM="${WALLET_CORE_DIR:-/work/wallet-core}"
OUT="${BUILD_DIR:-$ROOT/build}"
EXPORT="${EXPORT_DIR:-/work/export}"
for name in walletcore.so walletcore.stub.php abi.json; do
  [[ -s "$OUT/$name" ]] || {
    echo "ERROR: missing export artifact: $OUT/$name" >&2
    exit 2
  }
done
[[ -d "$UPSTREAM/.git" ]] || {
  echo "ERROR: missing upstream Git checkout: $UPSTREAM" >&2
  exit 2
}
mkdir -p "$EXPORT"
cp "$OUT/walletcore.so" "$OUT/walletcore.stub.php" "$OUT/abi.json" "$EXPORT/"
python3 - "$EXPORT" "$UPSTREAM" <<'PY'
import hashlib
import json
import os
import platform
import re
import shlex
import subprocess
import sys
from pathlib import Path

export = Path(sys.argv[1])
upstream = Path(sys.argv[2])
def output(args):
    return subprocess.check_output(args, text=True).strip()


php_config = os.environ.get('PHP_CONFIG') or 'php-config'
php_binary = os.environ.get('PHP_BINARY') or 'php'


def php_constant(name):
    return output([php_binary, '-n', '-r', f'echo (int) {name};'])


def os_release():
    release = {}
    for line in Path('/etc/os-release').read_text().splitlines():
        if '=' in line and not line.startswith('#'):
            name, value = line.split('=', 1)
            release[name] = shlex.split(value)[0] if value else ''
    return release


release = os_release()
release_id, actual_os_version = release['ID'], release['VERSION_ID']
# The maintained Rocky base image reports ID=rocky, whereas OS=rockylinux
# identifies the Docker image family. Keep the public target name stable.
os_id = {'rocky': 'rockylinux'}.get(release_id, release_id)
os_version = os.environ.get('BUILD_OS_VERSION', actual_os_version)
if os_id not in ('debian', 'alpine', 'ubuntu', 'fedora', 'rockylinux', 'almalinux'):
    raise RuntimeError(f'Unsupported distribution for release: {release_id}')
if os.environ.get('BUILD_OS') and os.environ['BUILD_OS'] != os_id:
    raise RuntimeError('BUILD_OS does not match actual /etc/os-release')
if os_id in ('alpine', 'rockylinux', 'almalinux'):
    if not (actual_os_version == os_version
            or actual_os_version.startswith(os_version + '.')):
        raise RuntimeError('BUILD_OS_VERSION does not match actual release family')
elif os_version != actual_os_version:
    raise RuntimeError('BUILD_OS_VERSION does not match actual OS release')
php_zts = php_constant('PHP_ZTS')
php_debug = php_constant('PHP_DEBUG')
if os.environ.get('BUILD_PHP_ZTS') and os.environ['BUILD_PHP_ZTS'] != php_zts:
    raise RuntimeError('PHP_ZTS build argument does not match PHP runtime')
architecture = platform.machine()
platform_id = {'x86_64': 'linux/amd64', 'aarch64': 'linux/arm64'}.get(architecture)
if not platform_id:
    raise RuntimeError(f'Unsupported architecture: {architecture}')
if (os.environ.get('BUILD_TARGET_PLATFORM')
        and os.environ['BUILD_TARGET_PLATFORM'] != platform_id):
    raise RuntimeError('Buildx target platform does not match binary architecture')


# --phpapi is a downstream (e.g. Debian) addition, not a portable php-config
# option. Read the module ABI from the headers selected for compilation.
include_dir = Path(output([php_config, '--include-dir']))
zend_modules = include_dir / 'Zend' / 'zend_modules.h'
try:
    header = zend_modules.read_text(encoding='utf-8')
except OSError as error:
    raise RuntimeError(f'PHP development header unavailable: {zend_modules}') from error
matches = re.findall(r'^\s*#\s*define\s+ZEND_MODULE_API_NO\s+([0-9]+)\s*(?:/\*.*)?$',
                     header, flags=re.MULTILINE)
if len(matches) != 1:
    raise RuntimeError(f'Cannot determine PHP Zend module API from {zend_modules}')
php_api = matches[0]

so = export / 'walletcore.so'
info = {
    'wallet_core_commit': output(['git', '-C', str(upstream), 'rev-parse', 'HEAD']),
    'wallet_core_ref': os.environ.get('WALLET_CORE_REF', ''),
    'wallet_core_repository': output(['git', '-C', str(upstream), 'remote', 'get-url', 'origin']),
    'php_version': output([php_config, '--version']),
    'php_extension_dir': output([php_config, '--extension-dir']),
    'php_api': php_api,
    'php_extension_build': Path(output([php_config, '--extension-dir'])).name,
    'target_architecture': architecture,
    'target_platform': platform_id,
    'os_id': os_id,
    'os_release_id': release_id,
    'os_version': os_version,
    'os_release_version': actual_os_version,
    'libc': 'musl' if os_id == 'alpine' else 'glibc',
    'php_zts': php_zts == '1',
    'php_debug': php_debug == '1',
    'target_system': platform.system(),
    'walletcore_sha256': hashlib.sha256(so.read_bytes()).hexdigest(),
}
# Document ELF DT_NEEDED dependencies for inspection before installation.
elf = output(['readelf', '-W', '-d', str(so)])
info['needed_libraries'] = re.findall(r'Shared library: \[([^\]]+)\]', elf)
# These values are required by compat-matrix.py. Fail during export rather
# than publishing build-info.json which the matrix must later reject.
clang_lines = output([os.environ.get('CLANG') or 'clang', '--version']).splitlines()
if not clang_lines:
    raise RuntimeError('clang --version returned no output')
info['clang_version'] = clang_lines[0]
# Use rustup's active toolchain for this checkout (including an upstream pin).
info['rustc_version'] = subprocess.check_output(
    ['rustc', '--version'], cwd=upstream / 'rust', text=True).strip()
if not info['rustc_version']:
    raise RuntimeError('rustc --version returned no output')
(export / 'build-info.json').write_text(json.dumps(info, indent=2, sort_keys=True) + '\n')
PY
printf 'Exporting tested artifacts from %s to the Docker output stage\n' "$EXPORT"
ls -lh "$EXPORT"
