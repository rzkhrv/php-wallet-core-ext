#!/usr/bin/env python3
"""Publish previously verified PHP extension artifacts as OCI copy-only images."""
import argparse
import json
from pathlib import Path
import re
import shutil
import struct
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools' / 'matrix'))
from matrix import case_dir, inspect_artifacts  # noqa: E402

IMAGE_PATTERN = re.compile(r'ghcr\.io/[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)+')
VERSION_PATTERN = re.compile(r'[0-9]+\.[0-9]+\.[0-9]+')
OS_PATTERN = re.compile(r'(debian|alpine|ubuntu|fedora|rockylinux|almalinux)')
PLATFORMS = {'linux/amd64': 62, 'linux/arm64': 183}
DOCKERFILE = ROOT / 'docker' / 'Dockerfile.oci'


def validate_elf(path, machine):
    """Fail closed if the file is not a 64-bit, little-endian ELF shared object."""
    with path.open('rb') as binary:
        header = binary.read(64)
    if (len(header) < 64 or header[:4] != b'\x7fELF'
            or header[4] != 2 or header[5] != 1 or header[6] != 1):
        raise ValueError(f'not a 64-bit little-endian ELF library: {path}')
    e_type, e_machine = struct.unpack_from('<HH', header, 16)
    if e_type != 3 or e_machine != machine:
        raise ValueError(f'ELF type/architecture mismatch: {path}')


def release_tag(core, php, os_id, os_version, zts):
    minor = '.'.join(php.split('.')[:2])
    return f'{core}-php{minor}-{os_id}{os_version}-{"zts" if zts == "1" else "nts"}'


def prepare(args):
    """Validate *all* profiles before the first registry write."""
    if not IMAGE_PATTERN.fullmatch(args.image):
        raise ValueError('OCI_IMAGE must be a lowercase ghcr.io/owner/repository path without tag')
    if not VERSION_PATTERN.fullmatch(args.php) or not VERSION_PATTERN.fullmatch(args.core):
        raise ValueError('publishing requires exact PHP and Wallet Core X.Y.Z versions')
    if not OS_PATTERN.fullmatch(args.os) or not re.fullmatch(r'[0-9]+(?:\.[0-9]+)*', args.os_version):
        raise ValueError('invalid OS / OS_VERSION')
    if args.zts not in ('0', '1'):
        raise ValueError('PHP_ZTS must be 0 or 1')
    platforms = args.platforms.split()
    if not platforms or len(platforms) != len(set(platforms)) or any(
            platform not in PLATFORMS for platform in platforms):
        raise ValueError('OCI_PLATFORMS must contain unique linux/amd64 and/or linux/arm64')

    allowed = (ROOT / 'build').resolve()
    if (ROOT / 'build').is_symlink():
        raise ValueError('build/ cannot be a symlink')
    build_root = Path(args.build_root)
    if not build_root.is_absolute():
        build_root = ROOT / build_root
    resolved = build_root.resolve()
    if resolved != allowed and allowed not in resolved.parents:
        raise ValueError('BUILD_ROOT must be under project build/')
    # Reject paths that pass through a symlink, even when they resolve within build/.
    current = build_root
    while current != ROOT.parent and current != current.parent:
        if current.is_symlink():
            raise ValueError(f'BUILD_ROOT cannot contain symlinks: {current}')
        current = current.parent

    result = []
    for platform in platforms:
        artifacts = case_dir(build_root, args.php, args.core, args.os, args.os_version,
                             platform, args.zts) / 'artifacts'
        if artifacts.is_symlink() or artifacts.parent.is_symlink():
            raise ValueError(f'symlinked artifact path: {artifacts}')
        if not artifacts.is_dir():
            raise ValueError(f'missing verified artifacts: {artifacts}')
        for name in ('walletcore.so', 'build-info.json', 'abi.json', 'walletcore.stub.php'):
            if (artifacts / name).is_symlink():
                raise ValueError(f'symlinked artifact: {artifacts / name}')
        inspect_artifacts(artifacts, args.php, args.core, args.os, args.os_version,
                          platform, args.zts)
        validate_elf(artifacts / 'walletcore.so', PLATFORMS[platform])
        result.append((platform, artifacts))
    return release_tag(args.core, args.php, args.os, args.os_version, args.zts), result


def publish(args, tag, profiles):
    # Tag arch-specific images first, and only then change the canonical tag.
    # The latter remains intact if upload of any architecture fails.
    with tempfile.TemporaryDirectory(prefix='walletcore-oci-') as workspace:
        context = Path(workspace)
        for platform, artifacts in profiles:
            for name in ('walletcore.so', 'build-info.json', 'abi.json', 'walletcore.stub.php'):
                shutil.copyfile(artifacts / name, context / name)
            shutil.copyfile(DOCKERFILE, context / 'Dockerfile')
            arch = platform.split('/')[1]
            image = f'{args.image}:{tag}-{arch}'
            print(f'Publishing {platform}: {image}', flush=True)
            subprocess.run([args.docker, 'buildx', 'build', '--platform', platform,
                            '--file', str(context / 'Dockerfile'), '--tag', image,
                            '--push', str(context)], check=True)
        sources = [f'{args.image}:{tag}-{platform.split("/")[1]}'
                   for platform, _ in profiles]
        canonical = f'{args.image}:{tag}'
        print(f'Publishing release tag: {canonical}', flush=True)
        subprocess.run([args.docker, 'buildx', 'imagetools', 'create',
                        '--tag', canonical, *sources], check=True)
        print(f'Published {canonical} ({", ".join(p for p, _ in profiles)})', flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True)
    parser.add_argument('--os', required=True)
    parser.add_argument('--os-version', required=True)
    parser.add_argument('--php', required=True)
    parser.add_argument('--zts', required=True)
    parser.add_argument('--core', required=True)
    parser.add_argument('--platforms', required=True)
    parser.add_argument('--build-root', default='build')
    parser.add_argument('--docker', default='docker')
    args = parser.parse_args(argv)
    try:
        tag, profiles = prepare(args)
        publish(args, tag, profiles)
        return 0
    except (OSError, ValueError, json.JSONDecodeError, subprocess.CalledProcessError) as error:
        print(f'ERROR: OCI publication: {error}', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
