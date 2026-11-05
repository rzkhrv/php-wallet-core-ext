"""Selected PHP release must drive both Docker runtime and Zend build."""
import os
import re
from pathlib import Path
import subprocess
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]


class PhpTargetTests(unittest.TestCase):
    def make_preview(self, target='build', version=None):
        args = ['make', '-n', target]
        if version is not None:
            args.append('PHP_VERSION=' + version)
        # `make matrix` sets PHP_VERSION for the Docker build. A test of the
        # default must not inherit that explicit runtime selection.
        env = os.environ.copy()
        env.pop('PHP_VERSION', None)
        env.pop('RUST_TOOLCHAIN', None)
        result = subprocess.run(args, cwd=ROOT, capture_output=True, text=True,
                                env=env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def test_default_matches_selected_php_source_release(self):
        makefile = (ROOT / 'Makefile').read_text()
        dockerfile = (ROOT / 'docker/Dockerfile').read_text()
        make_default = re.search(r'^PHP_VERSION\s*\?=\s*(\d+\.\d+\.\d+)\s*$',
                                 makefile, re.MULTILINE)
        docker_default = re.search(r'^ARG PHP_VERSION=(\d+\.\d+\.\d+)\s*$',
                                   dockerfile, re.MULTILINE)
        self.assertIsNotNone(make_default)
        self.assertIsNotNone(docker_default)
        self.assertEqual(make_default.group(1), docker_default.group(1))
        self.assertIn('FROM ${BASE_IMAGE} AS toolchain', dockerfile)
        self.assertIn('tools/build/build-php.sh', dockerfile)
        self.assertNotIn('FROM php:', dockerfile)
        self.assertIn('--build-arg PHP_VERSION="$(PHP_VERSION)"', makefile)
        self.assertNotIn('PHP_PACKAGES', makefile + dockerfile)
        with mock.patch.dict(os.environ, {'PHP_VERSION': '0.0.0'}):
            preview = self.make_preview()
        self.assertIn(f'--build-arg PHP_VERSION="{make_default.group(1)}"', preview)
        self.assertNotIn('--build-arg PHP_VERSION="0.0.0"', preview)

    def test_wallet_core_default_matches_dockerfile(self):
        makefile = (ROOT / 'Makefile').read_text()
        dockerfile = (ROOT / 'docker/Dockerfile').read_text()
        selected = re.search(r'^WALLET_CORE_REF\s*\?=\s*([^\s]+)$',
                             makefile, re.MULTILINE)
        docker = re.search(r'^ARG WALLET_CORE_REF="([^"]+)"$',
                           dockerfile, re.MULTILINE)
        self.assertIsNotNone(selected)
        self.assertIsNotNone(docker)
        self.assertEqual(selected.group(1), docker.group(1))

    def test_rust_default_is_configurable_without_auto_selection(self):
        makefile = (ROOT / 'Makefile').read_text()
        dockerfile = (ROOT / 'docker/Dockerfile').read_text()
        make_default = re.search(r'^RUST_TOOLCHAIN\s*\?=\s*([^\s]+)\s*$',
                                 makefile, re.MULTILINE)
        docker_default = re.search(r'^ARG RUST_TOOLCHAIN=([^\s]+)\s*$',
                                   dockerfile, re.MULTILINE)
        self.assertIsNotNone(make_default)
        self.assertIsNotNone(docker_default)
        self.assertNotEqual('auto', make_default.group(1))
        self.assertEqual(make_default.group(1), docker_default.group(1))
        self.assertIn('--default-toolchain "${RUST_TOOLCHAIN}"', dockerfile)
        for target in ('build', 'test', 'test-asan'):
            with self.subTest(target=target):
                default_preview = self.make_preview(target)
                self.assertIn(f'--build-arg RUST_TOOLCHAIN="{make_default.group(1)}"', default_preview)
                result = subprocess.run(
                    ['make', '-n', target, 'RUST_TOOLCHAIN=1.96.1'],
                    cwd=ROOT, capture_output=True, text=True)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn('--build-arg RUST_TOOLCHAIN="1.96.1"', result.stdout)
        self.assertFalse((ROOT / 'tools/select-rust-toolchain.sh').exists())

    def test_explicit_php_version_reaches_each_target(self):
        for target in ('build', 'test', 'test-asan'):
            with self.subTest(target=target):
                preview = self.make_preview(target, '8.4.27')
                self.assertIn('--build-arg PHP_VERSION="8.4.27"', preview)
                self.assertNotIn('--build-arg PHP_PACKAGES=', preview)

    def test_php_interpreter_config_and_asan_use_same_version(self):
        dockerfile = (ROOT / 'docker/Dockerfile').read_text()
        # The selected PHP source is compiled in the distro builder.
        self.assertIn('php -n -r', dockerfile)
        self.assertIn('test "$(php-config --version)" = "$PHP_VERSION"', dockerfile)
        self.assertIn('PHP_ASAN_VERSION="$release_php_version"', dockerfile)
        self.assertIn('PHP_ASAN_VERSION" != "$release_php_version"', dockerfile)
        self.assertIn('ENV PHP_CONFIG=/opt/php-asan/bin/php-config', dockerfile)
        self.assertIn('&& php -n -d extension=/work/extension/build/walletcore.so', dockerfile)


if __name__ == '__main__':
    unittest.main()
