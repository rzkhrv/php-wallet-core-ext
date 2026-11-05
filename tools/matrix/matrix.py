#!/usr/bin/env python3
"""Build a validated OS/architecture/ZTS × PHP × Wallet Core matrix."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[2]

def case_dir(root, php, ref, os_id="debian", os_version="13",
             platform="linux/amd64", zts="0"):
    # Keep upstream branch refs within one readable folder.
    abi = 'zts' if zts == '1' else 'nts'
    return root / (f'{os_id}{os_version}-{platform.split("/")[1]}'
                   f'-php{php}-{abi}-wc{ref.replace("/", "%2F")}')


def versions(name, default, pattern):
    values = os.environ.get(name, default).split()
    if not values or len(set(values)) != len(values):
        raise ValueError(f"{name}: supply unique, nonempty whitespace-separated values")
    for value in values:
        if not re.fullmatch(pattern, value):
            raise ValueError(f"{name}: unsafe/invalid value {value!r}")
    return values


def inspect_artifacts(directory, php, upstream, os_id=None, os_version=None,
                      platform=None, zts=None):
    required = ["walletcore.so", "walletcore.stub.php", "abi.json", "build-info.json"]
    for name in required:
        if not (directory / name).is_file() or not (directory / name).stat().st_size:
            raise ValueError(f"missing or empty artifact: {name}")
    info = json.loads((directory / "build-info.json").read_text())
    abi = json.loads((directory / "abi.json").read_text())
    if info.get("php_version") != php or info.get("wallet_core_ref") != upstream:
        raise ValueError("build-info.json does not match the requested PHP/Wallet Core revisions")
    if os_id is not None:
        expected = {
            'os_id': os_id,
            'os_version': os_version,
            'target_platform': platform,
            'php_zts': zts == '1',
            'libc': 'musl' if os_id == 'alpine' else 'glibc',
        }
        for key, value in expected.items():
            if info.get(key) != value:
                raise ValueError(f'build-info.json target mismatch: {key}')
        expected_arch = {'linux/amd64': 'x86_64', 'linux/arm64': 'aarch64'}[platform]
        if info.get('target_architecture') != expected_arch:
            raise ValueError('target architecture differs from requested platform')
        if info.get('php_debug') is not False:
            raise ValueError('release artifact must use non-debug PHP')
        if not isinstance(info.get('needed_libraries'), list):
            raise ValueError('missing ELF dynamic dependencies')
    if not re.fullmatch(r"[0-9]+", str(info.get("php_api", ""))):
        raise ValueError("missing/invalid Zend module API")
    for field in ("wallet_core_repository", "php_extension_dir", "target_architecture", "target_system", "clang_version", "rustc_version"):
        if not isinstance(info.get(field), str) or not info[field].strip():
            raise ValueError(f"build-info.json lacks required provenance: {field}")
    commit = info.get("wallet_core_commit", "")
    if not isinstance(commit, str) or not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("missing/invalid Wallet Core commit")
    actual_hash = hashlib.sha256((directory / "walletcore.so").read_bytes()).hexdigest()
    if info.get("walletcore_sha256") != actual_hash:
        raise ValueError("walletcore.so SHA-256 differs from build-info.json")
    functions = abi.get("functions")
    if not isinstance(functions, dict) or not functions:
        raise ValueError("missing ABI functions")
    if abi.get("header_errors"):
        raise ValueError("ABI contains header errors")
    if abi.get("parsed_functions") != len(functions):
        raise ValueError("ABI parsed_functions does not match enumerated C functions")
    counts = {"generated": 0, "excluded": 0}
    for item in functions.values():
        status = item.get("status") if isinstance(item, dict) else None
        if status not in counts:
            raise ValueError(f"invalid ABI function status: {status!r}")
        counts[status] += 1
    return {"wallet_core_commit": commit, "php_api": str(info["php_api"]),
            "rustc_version": info["rustc_version"], "clang_version": info["clang_version"],
            "sha256": actual_hash, **counts}



def matrix_targets(raw):
    """Parse unique OS:release:architecture:ZTS profiles."""
    targets = raw.split()
    if not targets or len(set(targets)) != len(targets):
        raise ValueError('MATRIX_TARGETS must contain unique, nonempty profiles')
    profiles = []
    for target in targets:
        match = re.fullmatch(
            r'(debian|alpine|ubuntu|fedora|rockylinux|almalinux):'
            r'([0-9]+(?:\.[0-9]+)*):(amd64|arm64):([01])', target)
        if not match:
            raise ValueError(f'invalid MATRIX_TARGETS profile: {target!r}')
        os_id, release, arch, zts = match.groups()
        profiles.append((os_id, release, 'linux/' + arch, zts))
    return profiles


def main():
    try:
        os_id = os.environ.get('OS', 'debian')
        os_version = os.environ.get('OS_VERSION', '13')
        platform = os.environ.get('PLATFORM', 'linux/amd64')
        zts = os.environ.get('PHP_ZTS', '0')
        default_profile = f'{os_id}:{os_version}:{platform.removeprefix("linux/")}:{zts}'
        profiles = matrix_targets(os.environ.get('MATRIX_TARGETS', default_profile))
        phps = versions('MATRIX_PHP_VERSIONS', os.environ.get('PHP_VERSION', '8.5.12'), r'[0-9]+\.[0-9]+\.[0-9]+')
        cores = versions('MATRIX_WALLET_CORE_REFS', os.environ.get('WALLET_CORE_REF', '4.8.4'), r'[A-Za-z0-9][A-Za-z0-9._/+\-]*')
        build_root = Path(os.environ.get('BUILD_ROOT', 'build'))
        if not build_root.is_absolute():
            build_root = ROOT / build_root
        build_root = build_root.resolve()
        allowed = (ROOT / 'build').resolve()
        if (ROOT / 'build').is_symlink() or (build_root != allowed and allowed not in build_root.parents):
            raise ValueError('BUILD_ROOT must remain inside the project build/ directory')
        summary = build_root / 'matrix.json'
        if summary.is_symlink() or summary.resolve() != summary:
            raise ValueError('matrix report cannot be a symlink')
        cells = [(name, release, php, ref, arch, thread_mode)
                 for name, release, arch, thread_mode in profiles
                 for php in phps for ref in cores]
        build_root.mkdir(parents=True, exist_ok=True)
    except (ValueError, OSError) as exc:
        print(f'ERROR: {exc}', file=sys.stderr)
        return 2

    results = []
    mode = 'matrix'
    # Always replace a previous report, even if the first target fails.
    summary.unlink(missing_ok=True)
    for number, (os_name, version, php, ref, cell_platform, cell_zts) in enumerate(cells, 1):
        case = case_dir(build_root, php, ref, os_name, version, cell_platform, cell_zts)
        if (case.is_symlink() or case.resolve() != case or
                (case / 'artifacts').is_symlink() or (case / 'logs').is_symlink()):
            print(f'ERROR: matrix output directory is a symlink: {case}', file=sys.stderr)
            return 2
        artifacts = case / 'artifacts'
        previous = case / '.previous-artifacts'
        if previous.exists() or previous.is_symlink():
            print(f'ERROR: unfinished matrix backup requires inspection: {previous}', file=sys.stderr)
            return 2
        logs = case / 'logs'
        logs.mkdir(parents=True, exist_ok=True)
        log = logs / 'build.log'
        command = ['make', '--no-print-directory', 'build', f'PHP_VERSION={php}',
                   f'WALLET_CORE_REF={ref}',
                   f'BUILD_ROOT={build_root.relative_to(ROOT)}',
                   f'OS={os_name}', f'OS_VERSION={version}',
                   f'PLATFORM={cell_platform}', f'PHP_ZTS={cell_zts}']
        for option in ('RUST_TOOLCHAIN', 'BUILD_JOBS'):
            if option in os.environ:
                command.append(f'{option}={os.environ[option]}')
        print(f'[{number}/{len(cells)}] {os_name}:{version} {cell_platform} '
              f'{"ZTS" if cell_zts == "1" else "NTS"} PHP {php} Wallet Core {ref}', flush=True)
        started = time.monotonic()
        record = {'os_id': os_name, 'os_version': version, 'php_version': php,
                  'wallet_core_ref': ref, 'target_platform': cell_platform,
                  'php_zts': cell_zts == '1', 'log': str(log), 'status': 'FAIL'}
        try:
            # Protect a previous successful release even if a mock/custom
            # build command writes a partial or stale export.
            if artifacts.exists():
                artifacts.rename(previous)
            log.unlink(missing_ok=True)
            process = subprocess.run(command, cwd=ROOT, stdout=subprocess.DEVNULL,
                                     stderr=subprocess.STDOUT, check=False)
            record['exit_code'] = process.returncode
            if process.returncode:
                record['error'] = 'build/tests failed; inspect logs/build.log'
            else:
                record.update(inspect_artifacts(artifacts, php, ref, os_name,
                                                version, cell_platform, cell_zts))
                record['status'] = 'PASS'
        except (OSError, ValueError, json.JSONDecodeError) as exc:
            record['error'] = f'artifact/runner validation failed: {exc}'
        finally:
            if record['status'] == 'PASS':
                if previous.exists():
                    shutil.rmtree(previous)
            else:
                if artifacts.exists():
                    shutil.rmtree(artifacts)
                if previous.exists():
                    previous.rename(artifacts)
        record['elapsed_seconds'] = round(time.monotonic() - started, 2)
        results.append(record)
        # Keep the report valid JSON even if a run is interrupted mid-write.
        partial = summary.with_name(f'.matrix-{os.getpid()}.tmp')
        partial.write_text(json.dumps({'mode': mode, 'results': results}, indent=2) + '\n')
        partial.replace(summary)
        print(f'  {record["status"]}: {record.get("error", "verified build and metadata")}', flush=True)
    passed = sum(row['status'] == 'PASS' for row in results)
    print(f'{mode}: {passed}/{len(results)} PASS; report: {summary}')
    return 0 if passed == len(results) else 1


if __name__ == '__main__':
    if sys.argv[1:]:
        print('usage: matrix.py', file=sys.stderr)
        sys.exit(2)
    sys.exit(main())
