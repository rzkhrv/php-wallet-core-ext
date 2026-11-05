"""Guard build-cache design without requiring Docker or a network checkout."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
DOCKERFILE = (ROOT / 'docker/Dockerfile').read_text()


class BuildCacheTests(unittest.TestCase):
    def test_compilation_caches_are_isolated_by_platform_and_distro(self):
        scope = '${OS}-${OS_VERSION}-${TARGETARCH}-v1'
        self.assertIn('ARG TARGETARCH', DOCKERFILE)
        self.assertIn('id=walletcore-ccache-' + scope, DOCKERFILE)
        self.assertIn('id=walletcore-rust-' + scope, DOCKERFILE)
        self.assertIn('id=walletcore-codegen-' + scope, DOCKERFILE)
        self.assertIn('id=walletcore-cbindgen-' + scope, DOCKERFILE)
        self.assertIn('CARGO_TARGET_DIR=/opt/cargo-cbindgen-target cargo install cbindgen',
                      DOCKERFILE)
        self.assertIn('target=/work/wallet-core/rust/target,sharing=locked', DOCKERFILE)
        self.assertIn('target=/work/wallet-core/codegen-v2/target,sharing=locked', DOCKERFILE)
        self.assertIn('target=/var/cache/walletcore-ccache,sharing=shared', DOCKERFILE)
        self.assertIn('id=walletcore-ccache-asan-' + scope, DOCKERFILE)

    def test_rust_library_survives_disappearing_cache_mount(self):
        # A cache mount must never be the only copy of a library needed by
        # subsequent Docker stages, especially the unsanitized PHP link step.
        self.assertEqual(2, DOCKERFILE.count(
            'ENV WALLET_CORE_RUST_LIB=/work/wallet-core/build/local/lib/libwallet_core_rs.a'))
        self.assertIn('cp /work/wallet-core/rust/target/release/libwallet_core_rs.a', DOCKERFILE)
        self.assertIn('test -s /work/wallet-core/build/local/lib/libwallet_core_rs.a',
                      DOCKERFILE)
        self.assertIn('FROM toolchain AS php_toolchain', DOCKERFILE)
        self.assertIn('FROM native AS verified', DOCKERFILE)
        self.assertIn('FROM native AS asan_native', DOCKERFILE)
        self.assertEqual(2, DOCKERFILE.count('COPY --from=php_toolchain /opt/php /opt/php'))

    def test_php_workdir_override_is_fresh_and_safe(self):
        script = ROOT / 'tools/build/build-php.sh'
        with tempfile.TemporaryDirectory() as tmp:
            existing = Path(tmp) / 'already'
            existing.mkdir()
            for path in (str(existing), '/not-tmp/test', '/'):
                env = dict(os.environ, PHP_VERSION='8.4.27', PHP_ZTS='0',
                           PHP_PREFIX=str(Path(tmp) / 'php-install'),
                           PHP_BUILD_WORKDIR=path)
                proc = subprocess.run(['bash', str(script)], env=env,
                                      capture_output=True, text=True)
                self.assertEqual(2, proc.returncode, proc.stderr)
                self.assertIn('PHP_BUILD_WORKDIR', proc.stderr)
        self.assertIn('PHP_BUILD_WORKDIR=/tmp/walletcore-php-build', DOCKERFILE)

    def test_matrix_defaults_to_selected_single_build_versions(self):
        result = subprocess.run(
            ['make', '-n', 'matrix', 'PHP_VERSION=8.4.27',
             'WALLET_CORE_REF=4.8.4'], cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('MATRIX_PHP_VERSIONS="8.4.27"', result.stdout)
        self.assertIn('MATRIX_WALLET_CORE_REFS="4.8.4"', result.stdout)
        self.assertNotIn('8.4.27 8.5.12', result.stdout)

    def test_matrix_propagates_build_jobs(self):
        p = subprocess.run(['make', '-n', 'matrix', 'BUILD_JOBS=5'], cwd=ROOT,
                           capture_output=True, text=True)
        self.assertEqual(0, p.returncode, p.stderr)
        self.assertIn('BUILD_JOBS="5"', p.stdout)
        runner = (ROOT / 'tools/matrix/matrix.py').read_text()
        self.assertIn("'RUST_TOOLCHAIN', 'BUILD_JOBS'", runner)


if __name__ == '__main__':
    unittest.main()
