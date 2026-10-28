#!/usr/bin/env python3
"""Exercise an exported .so in a separate, caller-selected PHP runtime image."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]


def command(image, artifacts, docker='docker', php='php'):
    if not image or image.startswith('-') or any(c.isspace() for c in image):
        raise ValueError('RUNTIME_IMAGE must be a nonempty Docker image reference')
    if not re.fullmatch(r'[A-Za-z0-9_./:-]+', php) or php.startswith('-'):
        raise ValueError('RUNTIME_PHP_BIN must name a PHP executable')
    path = Path(artifacts)
    if path.is_symlink():
        raise ValueError('artifacts cannot be symlinks')
    artifacts = path.resolve(strict=True)
    if not artifacts.is_dir():
        raise ValueError('artifact path is not a directory')
    if artifacts.is_symlink() or (artifacts / 'walletcore.so').is_symlink():
        raise ValueError('artifacts cannot be symlinks')
    info = json.loads((artifacts / 'build-info.json').read_text())
    so = artifacts / 'walletcore.so'
    if hashlib.sha256(so.read_bytes()).hexdigest() != info.get('walletcore_sha256'):
        raise ValueError('walletcore.so does not match build-info.json')
    platform = info.get('target_platform')
    if platform not in ('linux/amd64', 'linux/arm64'):
        raise ValueError('unsupported target platform in build-info.json')
    # The module and its metadata are mounted read-only. Never mount the
    # project's Docker socket or run a build in the application image.
    return [docker, 'run', '--rm', '--network', 'none', '--read-only',
            '--tmpfs', '/tmp', '--platform', platform,
            '--mount', f'type=bind,src={artifacts},dst=/work/extension/build,readonly',
            '--mount', f'type=bind,src={ROOT / "tests/php"},dst=/work/extension/tests/php,readonly',
            '--entrypoint', '/bin/sh', image, '-eu', '-c', '''
module=/work/extension/build/walletcore.so
root=/work/extension/tests/php
php="$1"
"$php" -n "$root/runtime_compat.php"
for test in runtime.php binding_safety.php integration.php; do
  "$php" -n -d "extension=$module" "$root/$test"
done
''', '--', php]


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True)
    parser.add_argument('--artifacts', required=True)
    parser.add_argument('--docker', default='docker')
    parser.add_argument('--php', default='php')
    args = parser.parse_args(argv)
    try:
        cmd = command(args.image, args.artifacts, args.docker, args.php)
        return subprocess.run(cmd, check=False).returncode
    except (OSError, ValueError, KeyError, json.JSONDecodeError) as error:
        print(f'ERROR: runtime verification: {error}', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
