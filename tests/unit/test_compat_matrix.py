"""The compatibility matrix checks real build outputs but never mocks Wallet Core."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / 'tools/matrix/matrix.py'

FAKE_MAKE = r'''#!/usr/bin/env python3
import hashlib, json, os, pathlib, sys
args = {arg.split('=', 1)[0]: arg.split('=', 1)[1] for arg in sys.argv[1:] if '=' in arg}
php = args['PHP_VERSION']
ref = args['WALLET_CORE_REF']
abi = 'zts' if args['PHP_ZTS'] == '1' else 'nts'
profile = (args['OS'] + args['OS_VERSION'] + '-' + args['PLATFORM'].split('/')[1]
           + '-php' + php + '-' + abi + '-wc' + ref.replace('/', '%2F'))
out = pathlib.Path(args['BUILD_ROOT']) / profile / 'artifacts'
print('Mock Docker build:', php, ref)
(out.parent / 'logs').mkdir(parents=True, exist_ok=True)
(out.parent / 'logs' / 'build.log').write_text('Rust default: ' + args.get('RUST_TOOLCHAIN', 'unset'))
if os.environ.get('MOCK_FAIL_OS') == args['OS'] or os.environ.get('MOCK_FAIL_REF') == ref:
    sys.exit(32)
if os.environ.get('MOCK_SKIP_EXPORT') == ref:
    sys.exit(0)
out.mkdir(parents=True, exist_ok=True)
so = b'FAKE TEST BINARY - NOT A REAL PHP EXTENSION'
(out / 'walletcore.so').write_bytes(so)
(out / 'walletcore.stub.php').write_text('<?php class Example {}')
functions = {'TWExampleCreate': {'status': 'generated'}, 'TWExampleDelete': {'status': 'excluded'}}
(out / 'abi.json').write_text(json.dumps({'parsed_functions': 2, 'header_errors': [], 'functions': functions}))
(out / 'build-info.json').write_text(json.dumps({
    'php_version': '0.0.0' if os.getenv('MOCK_BAD_PHP') == php else php,
    'wallet_core_ref': ref,
    'wallet_core_commit': 'a' * 40,
    'php_api': '20250925',
    'wallet_core_repository': 'https://example.invalid/wallet-core.git',
    'php_extension_dir': '/usr/local/lib/php/extensions/no-debug-non-zts-test',
    'target_architecture': 'aarch64' if args['PLATFORM'] == 'linux/arm64' else 'x86_64',
    'target_platform': args['PLATFORM'],
    'os_id': args['OS'],
    'os_version': args['OS_VERSION'],
    'libc': 'musl' if args['OS'] == 'alpine' else 'glibc',
    'php_zts': args['PHP_ZTS'] == '1',
    'php_debug': False,
    'needed_libraries': ['libc.so.6'],
    'target_system': 'Linux',
    'clang_version': 'clang 19',
    'rustc_version': 'rustc 1.96.1',
    'walletcore_sha256': hashlib.sha256(so).hexdigest()
}))
'''


class MatrixRunnerTests(unittest.TestCase):
    def setUp(self):
        (ROOT / 'build').mkdir(exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=ROOT / 'build')
        self.addCleanup(self.temp.cleanup)
        self.work = Path(self.temp.name)
        fake = self.work / 'make'
        fake.write_text(FAKE_MAKE)
        fake.chmod(0o755)
        self.output = self.work
        self.env = dict(os.environ, PATH=str(self.work) + os.pathsep + os.environ['PATH'],
                        OS='debian', OS_VERSION='13', PLATFORM='linux/amd64', PHP_ZTS='0',
                        MATRIX_TARGETS='debian:13:amd64:0',
                        MATRIX_PHP_VERSIONS='8.4.27 8.5.12',
                        MATRIX_WALLET_CORE_REFS='4.7.0 4.8.4',
                        BUILD_ROOT=str(self.work.relative_to(ROOT)))

    def cell(self, php, ref, os_id='debian', os_version='13', platform='linux/amd64', zts='0'):
        abi = 'zts' if zts == '1' else 'nts'
        return self.work / (f'{os_id}{os_version}-{platform.split("/")[1]}'
                            f'-php{php}-{abi}-wc{ref}')

    def run_matrix(self, **environ):
        return subprocess.run([sys.executable, str(RUNNER)], cwd=ROOT,
                              capture_output=True, text=True, env=dict(self.env, **environ))

    def results(self):
        return json.loads((self.output / 'matrix.json').read_text())['results']

    def test_all_four_cells_verify_independent_artifacts_and_metadata(self):
        result = self.run_matrix()
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        rows = self.results()
        self.assertEqual(4, len(rows))
        self.assertEqual('matrix', json.loads((self.output / 'matrix.json').read_text())['mode'])
        self.assertEqual([('8.4.27', '4.7.0'), ('8.4.27', '4.8.4'),
                          ('8.5.12', '4.7.0'), ('8.5.12', '4.8.4')],
                         [(r['php_version'], r['wallet_core_ref']) for r in rows])
        self.assertTrue(all(r['status'] == 'PASS' for r in rows))
        self.assertTrue(all(r['generated'] == 1 and r['excluded'] == 1 for r in rows))
        self.assertTrue(all(Path(r['log']).is_file() for r in rows))
        for index in range(1, 5):
            row = rows[index - 1]
            folder = self.cell(row['php_version'], row['wallet_core_ref'])
            self.assertTrue((folder / 'artifacts' / 'walletcore.so').is_file())
            self.assertTrue((folder / 'logs' / 'build.log').is_file())

    def test_alpine_zts_arm64_matrix_profile(self):
        result = self.run_matrix(OS='alpine', OS_VERSION='3.24',
                                 PLATFORM='linux/arm64', PHP_ZTS='1',
                                 MATRIX_TARGETS='alpine:3.24:arm64:1',
                                 MATRIX_PHP_VERSIONS='8.4.27',
                                 MATRIX_WALLET_CORE_REFS='4.7.0')
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertTrue((self.cell('8.4.27', '4.7.0', 'alpine', '3.24',
                                   'linux/arm64', '1') / 'artifacts/walletcore.so').exists())

    def test_failure_does_not_stop_next_build_or_reuse_stale_artifacts(self):
        previous = self.run_matrix()
        self.assertEqual(0, previous.returncode)
        result = self.run_matrix(MOCK_FAIL_REF='4.7.0')
        self.assertEqual(1, result.returncode, result.stderr + result.stdout)
        rows = self.results()
        self.assertEqual(['FAIL', 'PASS', 'FAIL', 'PASS'], [r['status'] for r in rows])
        self.assertEqual([32, 32], [r['exit_code'] for r in rows if r['status'] == 'FAIL'])
        # Previous successful artifacts remain accessible, but these cells
        # must be marked FAIL: never misrepresent an old .so as this run.
        self.assertTrue((self.cell('8.4.27', '4.7.0') / 'artifacts/walletcore.so').exists())
        self.assertTrue((self.cell('8.5.12', '4.7.0') / 'artifacts/build-info.json').exists())

    def test_zero_exit_with_stale_binary_cannot_pass(self):
        self.assertEqual(0, self.run_matrix().returncode)
        original = (self.cell('8.4.27', '4.7.0') / 'artifacts/walletcore.so').read_bytes()
        bad = self.run_matrix(MOCK_SKIP_EXPORT='4.7.0')
        self.assertEqual(1, bad.returncode)
        self.assertEqual(['FAIL', 'PASS', 'FAIL', 'PASS'],
                         [r['status'] for r in self.results()])
        self.assertIn('missing or empty artifact', self.results()[0]['error'])
        restored = (self.cell('8.4.27', '4.7.0') / 'artifacts/walletcore.so')
        self.assertEqual(original, restored.read_bytes())

    def test_successful_command_with_wrong_php_metadata_fails_closed(self):
        result = self.run_matrix(MOCK_BAD_PHP='8.5.12')
        self.assertEqual(1, result.returncode)
        self.assertEqual(['PASS', 'PASS', 'FAIL', 'FAIL'], [r['status'] for r in self.results()])
        self.assertIn('does not match', self.results()[2]['error'])

    def test_rejects_invalid_ref_and_version_before_work(self):
        for env in ({'MATRIX_PHP_VERSIONS': '8.5.12;touch'},
                    {'MATRIX_WALLET_CORE_REFS': '../../other'},
                    {'MATRIX_WALLET_CORE_REFS': ''},
                    {'BUILD_ROOT': '../outside-build'}):
            with self.subTest(env=env):
                result = self.run_matrix(**env)
                self.assertEqual(2, result.returncode)

    def test_matrix_forwards_selected_rust_default_without_version_lookup(self):
        result = self.run_matrix(RUST_TOOLCHAIN='nightly-2025-12-11',
                                 MATRIX_TARGETS='alpine:3.24:arm64:1',
                                 MATRIX_PHP_VERSIONS='8.4.27',
                                 MATRIX_WALLET_CORE_REFS='4.7.0')
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        for row in self.results():
            log = Path(row['log'])
            self.assertEqual('Rust default: nightly-2025-12-11', log.read_text())

    def test_multi_os_profiles_and_php_core_cartesian_product(self):
        targets = 'ubuntu:24.04:amd64:0 debian:13:arm64:1'
        result = self.run_matrix(MATRIX_TARGETS=targets)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        rows = self.results()
        self.assertEqual(8, len(rows))
        self.assertEqual([('ubuntu', '24.04', 'linux/amd64', False)] * 4 +
                         [('debian', '13', 'linux/arm64', True)] * 4,
                         [(r['os_id'], r['os_version'], r['target_platform'], r['php_zts'])
                          for r in rows])
        self.assertTrue(all(r['status'] == 'PASS' for r in rows))
        self.assertTrue((self.cell('8.4.27', '4.7.0', 'debian', '13',
                                   'linux/arm64', '1') / 'artifacts/walletcore.so').exists())

    def test_all_supported_os_families(self):
        targets = ('debian:12:amd64:0 alpine:3.23:amd64:0 '
                   'ubuntu:22.04:amd64:0 fedora:43:amd64:0 '
                   'rockylinux:9:amd64:0 almalinux:9:amd64:0')
        result = self.run_matrix(MATRIX_TARGETS=targets,
                                 MATRIX_PHP_VERSIONS='8.4.27',
                                 MATRIX_WALLET_CORE_REFS='4.8.4')
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertEqual(['debian', 'alpine', 'ubuntu', 'fedora', 'rockylinux', 'almalinux'],
                         [row['os_id'] for row in self.results()])

    def test_failed_os_preserves_previous_artifacts_and_continues(self):
        params = dict(MATRIX_TARGETS='ubuntu:24.04:amd64:0 fedora:44:arm64:0 '
                                    'alpine:3.24:amd64:1',
                      MATRIX_PHP_VERSIONS='8.4.27',
                      MATRIX_WALLET_CORE_REFS='4.8.4')
        self.assertEqual(0, self.run_matrix(**params).returncode)
        result = self.run_matrix(MOCK_FAIL_OS='fedora', **params)
        self.assertEqual(1, result.returncode, result.stderr + result.stdout)
        self.assertEqual(['PASS', 'FAIL', 'PASS'],
                         [row['status'] for row in self.results()])
        self.assertTrue((self.cell('8.4.27', '4.8.4', 'fedora', '44',
                                   'linux/arm64') / 'artifacts/walletcore.so').is_file())

    def test_rejects_invalid_or_duplicate_matrix_profiles(self):
        for raw in ('', 'debian:13:amd64:0 debian:13:amd64:0',
                    'unknown:24.04:amd64:0', 'debian:13:armv7:0',
                    'debian:13:amd64:2', 'debian:../../tmp:amd64:0',
                    'alpine:3.23;echo:arm64:0'):
            with self.subTest(raw=raw):
                result = self.run_matrix(MATRIX_TARGETS=raw)
                self.assertEqual(2, result.returncode, result.stderr + result.stdout)

    def test_matrix_rejects_symlinked_report(self):
        with tempfile.TemporaryDirectory() as outside:
            (self.work / 'matrix.json').symlink_to(Path(outside) / 'report.json')
            result = self.run_matrix()
            self.assertEqual(2, result.returncode)
            self.assertIn('symlink', result.stderr)

    def test_default_profile_respects_single_build_selection(self):
        env = dict(self.env, OS='alpine', OS_VERSION='3.24',
                   PLATFORM='linux/arm64', PHP_ZTS='1',
                   MATRIX_PHP_VERSIONS='8.4.27', MATRIX_WALLET_CORE_REFS='4.8.4')
        env.pop('MATRIX_TARGETS')
        result = subprocess.run([sys.executable, str(RUNNER)], cwd=ROOT,
                                capture_output=True, text=True, env=env)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertEqual(('alpine', '3.24', 'linux/arm64', True),
                         tuple(self.results()[0][k] for k in
                               ('os_id', 'os_version', 'target_platform', 'php_zts')))

    def test_default_matrix_uses_selected_php_and_core(self):
        env = dict(self.env, PHP_VERSION='8.4.27', WALLET_CORE_REF='4.8.4')
        env.pop('MATRIX_PHP_VERSIONS')
        env.pop('MATRIX_WALLET_CORE_REFS')
        result = subprocess.run([sys.executable, str(RUNNER)], cwd=ROOT,
                                capture_output=True, text=True, env=env)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        rows = self.results()
        self.assertEqual(1, len(rows))
        self.assertEqual(('8.4.27', '4.8.4'),
                         (rows[0]['php_version'], rows[0]['wallet_core_ref']))

    def test_unexpected_runner_flags_are_rejected(self):
        for flag in ('--unexpected', '--platform'):
            result = subprocess.run([sys.executable, str(RUNNER), flag], cwd=ROOT,
                                    capture_output=True, text=True, env=self.env)
            self.assertEqual(2, result.returncode)
            self.assertIn('usage: matrix.py', result.stderr)

    def test_make_matrix_wiring_is_host_orchestration_only(self):
        preview = subprocess.run(['make', '-n', 'matrix', 'OS=debian',
                                  'OS_VERSION=13', 'PLATFORM=linux/amd64',
                                  'PHP_ZTS=0'], cwd=ROOT,
                                 capture_output=True, text=True)
        self.assertEqual(0, preview.returncode, preview.stderr)
        self.assertIn('tools/matrix/matrix.py', preview.stdout)
        self.assertNotIn('docker buildx', preview.stdout)
        self.assertIn('BUILD_ROOT=', preview.stdout)
        self.assertIn('MATRIX_TARGETS="debian:13:amd64:0"', preview.stdout)
        self.assertNotIn('OS_MATRIX_TARGETS', preview.stdout)
        self.assertNotIn('PORTABILITY_TARGETS', preview.stdout)
        alternate = subprocess.run(
            ['make', '-n', 'matrix', 'OS=alpine', 'OS_VERSION=3.24',
             'PLATFORM=linux/arm64', 'PHP_ZTS=1'],
            cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(0, alternate.returncode, alternate.stderr)
        self.assertIn('MATRIX_TARGETS="alpine:3.24:arm64:1"', alternate.stdout)


if __name__ == '__main__':
    unittest.main()
