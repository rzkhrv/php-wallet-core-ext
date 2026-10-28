"""Smoke tests for the native-only Docker split and actionable Clang failures."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
FIX = ROOT / 'tests/fixtures/include/TrustWalletCore'


@unittest.skipUnless(shutil.which('clang'), 'requires Clang for C-ABI header parsing')
class NativeBuildPhaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.core = self.base / 'wallet-core'
        self.headers = self.core / 'include/TrustWalletCore'
        shutil.copytree(FIX, self.headers)
        for archive in ('build/libTrustWalletCore.a', 'rust/target/release/libwallet_core_rs.a'):
            p = self.core / archive
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(b'fixture')
        self.out = self.base / 'output'
        self.mock = self.base / 'mock-compiler'
        self.mock.write_text('#!/bin/sh\necho "walletcore.c:42:3: error: deliberate fixture error" >&2\nexit 1\n')
        self.mock.chmod(0o755)
        self.mock_php_config = self.base / 'php-config'
        self.mock_php_config.write_text('#!/bin/sh\nif [ "$1" = --includes ]; then echo "-I/tmp/fake-php"; fi\n')
        self.mock_php_config.chmod(0o755)
        self.env = dict(os.environ,
                        WALLET_CORE_DIR=str(self.core),
                        BUILD_DIR=str(self.out),
                        WALLET_CORE_AUTO_BUILD='0',
                        PHP_CONFIG=str(self.mock_php_config),
                        CC=str(self.mock), CXX='clang++', CLANG='clang',
                        PHP_BINARY='php',
                        # Fixture tests model the release build. Never inherit
                        # WALLETCORE_SANITIZE=address from asan_verified: these
                        # fake upstream trees intentionally contain no Protobuf.
                        WALLETCORE_SANITIZE='')

    def run_build(self, *args):
        return subprocess.run(['bash', str(ROOT / 'tools/build/build.sh'), *args],
                              env=self.env, capture_output=True, text=True)

    def test_native_only_does_not_run_binding_generator(self):
        # PHP is intentionally absent in Docker's native stage; the builder
        # must not require php-config before the PHP source stage runs.
        self.env['PHP_CONFIG'] = str(self.base / 'missing-php-config')
        result = self.run_build('--native-only')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse((self.out / 'walletcore.c').exists())

    def test_full_build_still_requires_php_config(self):
        self.env['PHP_CONFIG'] = str(self.base / 'missing-php-config')
        result = self.run_build()
        self.assertEqual(2, result.returncode)
        self.assertIn('php-config missing', result.stderr)
        self.assertFalse((self.out / 'walletcore.c').exists())

    def test_failed_clang_reports_first_error(self):
        result = self.run_build()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('First compiler errors with source context', result.stderr)
        self.assertIn('error: deliberate fixture error', result.stderr)
        self.assertFalse((self.out / 'walletcore.so').exists())
        self.assertTrue((self.out / 'walletcore-compile.log').is_file())

    def test_non_executable_link_script_does_not_block_build(self):
        # Some host unpackers/checkouts drop executable bits. Docker copies the
        # resulting mode, so build.sh must run link-static.sh via Bash.
        isolated = self.base / 'isolated-extension'
        shutil.copytree(ROOT / 'tools', isolated / 'tools')
        linker = isolated / 'tools/build/link-static.sh'
        linker.chmod(0o644)

        mock_compiler = self.base / 'success-compiler'
        mock_compiler.write_text("""#!/usr/bin/env bash
set -eu
while (($#)); do
    if [[ "$1" == -o ]]; then
        shift
        : > "$1"
        exit 0
    fi
    shift
done
exit 1
""")
        mock_compiler.chmod(0o755)
        mock_php = self.base / 'success-php'
        mock_php.write_text('#!/bin/sh\nexit 0\n')
        mock_php.chmod(0o755)
        env = dict(self.env, CC=str(mock_compiler), CXX=str(mock_compiler),
                   PHP_BINARY=str(mock_php))
        result = subprocess.run(['bash', str(isolated / 'tools/build/build.sh')],
                                env=env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('Linking walletcore.so from', result.stdout)
        self.assertTrue((self.out / 'walletcore.so').exists())

    def test_module_version_tracks_wallet_core_ref(self):
        script = (ROOT / 'tools/build/build.sh').read_text()
        self.assertIn('--version "${WALLET_CORE_REF:-dev}"', script)
        self.assertNotIn('WALLET_CORE_VERSION', script)

    def test_native_stage_is_cached_separately(self):
        dockerfile = (ROOT / 'docker/Dockerfile').read_text()
        self.assertIn('FROM upstream AS native', dockerfile)
        self.assertIn('bash tools/build/build.sh --native-only', dockerfile)
        # PHP is compiled independently of the Wallet Core native stage.
        self.assertIn('FROM toolchain AS php_toolchain', dockerfile)
        self.assertIn('FROM native AS verified', dockerfile)
        self.assertIn('COPY --from=php_toolchain /opt/php /opt/php', dockerfile)
        self.assertLess(dockerfile.index('bash tools/build/build.sh --native-only'),
                        dockerfile.index('COPY . /work/extension/'))


if __name__ == '__main__':
    unittest.main()
