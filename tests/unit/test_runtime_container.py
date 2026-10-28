"""External PHP test uses a sealed, correct-architecture, read-only container."""
import hashlib
import importlib.util
import json
import os
import platform
import re
import shutil
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / 'tools/matrix/runtime-test.py'
SPEC = importlib.util.spec_from_file_location('runtime_test', RUNNER)
RUNTIME = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNTIME)


class RuntimeContainerTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.artifacts = self.root / 'artifacts'
        self.artifacts.mkdir()
        so = b'NOT AN ELF; mock container never executes PHP'
        (self.artifacts / 'walletcore.so').write_bytes(so)
        (self.artifacts / 'build-info.json').write_text(json.dumps({
            'target_platform': 'linux/arm64',
            'walletcore_sha256': hashlib.sha256(so).hexdigest(),
        }))

    def test_command_checks_platform_mounts_and_offline_execution(self):
        args = RUNTIME.command('my-runtime:php8.4', self.artifacts)
        self.assertEqual('linux/arm64', args[args.index('--platform') + 1])
        self.assertIn('none', args)
        self.assertIn('--read-only', args)
        self.assertIn('readonly', ' '.join(args))
        self.assertNotIn('privileged', ' '.join(args))
        self.assertIn('/work/extension/tests/php', ' '.join(args))
        self.assertIn('runtime_compat.php', args[-3])
        self.assertIn('binding_safety.php', args[-3])
        self.assertEqual('--', args[-2])
        self.assertEqual('php', args[-1])

    def test_bad_hash_and_platform_fail_before_docker(self):
        (self.artifacts / 'walletcore.so').write_bytes(b'bad')
        with self.assertRaisesRegex(ValueError, 'SHA-256|does not match'):
            RUNTIME.command('php:8.4-cli', self.artifacts)
        (self.artifacts / 'build-info.json').write_text(json.dumps({
            'walletcore_sha256': hashlib.sha256(b'bad').hexdigest(),
            'target_platform': 'linux/s390x',
        }))
        with self.assertRaisesRegex(ValueError, 'platform'):
            RUNTIME.command('php:8.4-cli', self.artifacts)

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI is not installed')
    def test_runtime_abi_preflight_using_local_php(self):
        # Execute the same PHP script as the runtime container, with a fake
        # .so. Its ABI preflight does not attempt to load the extension.
        tests = self.root / 'tests/php'
        tests.mkdir(parents=True)
        shutil.copyfile(ROOT / 'tests/php/runtime_compat.php',
                        tests / 'runtime_compat.php')
        build = self.root / 'build'
        build.mkdir()
        so = (self.artifacts / 'walletcore.so').read_bytes()
        (build / 'walletcore.so').write_bytes(so)
        php_version = subprocess.check_output(['php', '-n', '-r', 'echo PHP_VERSION;'], text=True)
        abi_output = subprocess.check_output(['php', '-n', '-i'], text=True)
        match = re.search(r'^PHP API\s*=>\s*([0-9]+)\s*$', abi_output, re.M)
        self.assertIsNotNone(match)
        zts = subprocess.check_output(['php', '-n', '-r', 'echo PHP_ZTS;'], text=True) == '1'
        debug = subprocess.check_output(['php', '-n', '-r', 'echo PHP_DEBUG;'], text=True) == '1'
        arch = {'x86_64': 'linux/amd64', 'aarch64': 'linux/arm64'}.get(platform.machine())
        if arch is None:
            self.skipTest('unknown test machine architecture')
        info = dict(php_version=php_version, php_api=match[1], php_zts=zts,
                    php_debug=debug, target_platform=arch,
                    libc='musl' if Path('/etc/alpine-release').exists() else 'glibc',
                    walletcore_sha256=hashlib.sha256(so).hexdigest())
        (build / 'build-info.json').write_text(json.dumps(info))
        cmd = ['php', '-n', str(tests / 'runtime_compat.php')]
        result = subprocess.run(cmd, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn('Runtime ABI:', result.stdout)
        info['php_api'] = '99999999'
        (build / 'build-info.json').write_text(json.dumps(info))
        failed = subprocess.run(cmd, capture_output=True, text=True)
        self.assertNotEqual(0, failed.returncode)
        self.assertIn('Zend module API mismatch', failed.stderr + failed.stdout)

    def test_image_and_binary_must_be_specified(self):
        for image, php in [('', 'php'), ('--privileged', 'php'), ('php:8.4-cli', '-S')]:
            with self.subTest(image=image, php=php), self.assertRaises(ValueError):
                RUNTIME.command(image, self.artifacts, php=php)

    def test_failing_mock_container_is_not_reported_as_pass(self):
        docker = self.root / 'docker'
        docker.write_text('#!/bin/sh\nexit 37\n')
        docker.chmod(0o755)
        p = subprocess.run([sys.executable, str(RUNNER), '--image', 'php:8.4-cli',
                            '--artifacts', str(self.artifacts), '--docker', str(docker)],
                           cwd=ROOT)
        self.assertEqual(37, p.returncode)


if __name__ == '__main__':
    unittest.main()
