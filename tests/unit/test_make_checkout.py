"""Offline tests of the public Makefile interface and managed Git checkout."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

BASE = Path(__file__).resolve().parents[2]


def git(*argv, cwd=None):
    return subprocess.run(['git', *map(str, argv)], cwd=cwd, check=True,
                          capture_output=True, text=True).stdout.strip()


@unittest.skipUnless(shutil.which('git'), 'requires local Git for fixture')
class CheckoutTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / 'tools/build').mkdir(parents=True)
        shutil.copy2(BASE / 'tools/build' / 'checkout-wallet-core.sh',
                     self.root / 'tools/build' / 'checkout-wallet-core.sh')
        self.work = self.root / 'upstream-work'
        git('init', '-b', 'master', self.work)
        git('config', 'user.email', 'tests@example.invalid', cwd=self.work)
        git('config', 'user.name', 'Test', cwd=self.work)
        (self.work / 'src.txt').write_text('first\n')
        git('add', '.', cwd=self.work)
        git('commit', '-m', 'first', cwd=self.work)
        self.first = git('rev-parse', 'HEAD', cwd=self.work)
        git('tag', 'v1-test', cwd=self.work)
        self.remote = self.root / 'remote.git'
        git('clone', '--bare', self.work, self.remote)
        self.checkout = self.root / 'build' / 'wallet-core'
        self.env = dict(os.environ,
                        WALLET_CORE_DIR=str(self.checkout),
                        WALLET_CORE_REPO=str(self.remote),
                        WALLET_CORE_REF='master')

    def run_checkout(self, **kwargs):
        env = dict(self.env, **kwargs)
        return subprocess.run(['bash', str(self.root / 'tools/build' / 'checkout-wallet-core.sh')],
                              env=env, capture_output=True, text=True)

    def test_git_cache_reuses_ref_across_clean_checkouts_without_remote(self):
        cache = self.root / 'git-cache'
        first = self.run_checkout(WALLET_CORE_GIT_CACHE=str(cache),
                                  WALLET_CORE_REF='v1-test')
        self.assertEqual(0, first.returncode, first.stderr)
        self.assertEqual(self.first, git('rev-parse', 'HEAD', cwd=self.checkout))
        self.assertEqual('v1-test', git('describe', '--tags', '--exact-match', cwd=self.checkout))
        self.assertFalse((self.checkout / '.git/objects/info/alternates').exists())
        shutil.rmtree(self.checkout)
        # Remove the original remote so a network fetch would fail.
        self.remote.rename(self.root / 'remote-hidden.git')
        second = self.run_checkout(WALLET_CORE_GIT_CACHE=str(cache),
                                   WALLET_CORE_REF='v1-test')
        self.assertEqual(0, second.returncode, second.stderr)
        self.assertIn('Reusing cached Wallet Core ref', second.stdout)
        self.assertEqual(self.first, git('rev-parse', 'HEAD', cwd=self.checkout))
        self.assertFalse((self.checkout / '.git/objects/info/alternates').exists())

    def test_git_cache_refreshes_moving_branch(self):
        cache = self.root / 'git-cache'
        first = self.run_checkout(WALLET_CORE_GIT_CACHE=str(cache))
        self.assertEqual(0, first.returncode, first.stderr)
        shutil.rmtree(self.checkout)
        (self.work / 'src.txt').write_text('second\n')
        git('add', '.', cwd=self.work)
        git('commit', '-m', 'second', cwd=self.work)
        second_commit = git('rev-parse', 'HEAD', cwd=self.work)
        git('push', str(self.remote), 'master', cwd=self.work)
        cached = self.run_checkout(WALLET_CORE_GIT_CACHE=str(cache))
        self.assertEqual(0, cached.returncode, cached.stderr)
        self.assertEqual(self.first, git('rev-parse', 'HEAD', cwd=self.checkout))
        shutil.rmtree(self.checkout)
        refreshed = self.run_checkout(WALLET_CORE_GIT_CACHE=str(cache),
                                      WALLET_CORE_REFRESH='1')
        self.assertEqual(0, refreshed.returncode, refreshed.stderr)
        self.assertEqual(second_commit, git('rev-parse', 'HEAD', cwd=self.checkout))

    def test_checkout_pinned_ref_into_empty_directory(self):
        result = self.run_checkout(WALLET_CORE_REF='v1-test')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(git('rev-parse', 'HEAD', cwd=self.checkout), self.first)
        self.assertEqual(git('status', '--porcelain', cwd=self.checkout), '')

    def test_checkout_in_empty_docker_upstream_stage(self):
        # Docker copies only checkout-wallet-core.sh before this RUN step.
        isolated_root = self.root / 'container' / 'extension'
        (isolated_root / 'tools/build').mkdir(parents=True)
        shutil.copy2(BASE / 'tools/build' / 'checkout-wallet-core.sh',
                     isolated_root / 'tools/build' / 'checkout-wallet-core.sh')
        isolated_checkout = self.root / 'container' / 'wallet-core'
        self.assertFalse((isolated_root / 'build').exists())
        env = dict(self.env, WALLET_CORE_DIR=str(isolated_checkout))
        result = subprocess.run(['bash', str(isolated_root / 'tools/build/checkout-wallet-core.sh')],
                                env=env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(git('rev-parse', 'HEAD', cwd=isolated_checkout), self.first)
        self.assertFalse((isolated_root / 'build').exists())

    def test_ref_switch_requires_fresh_checkout(self):
        self.assertEqual(0, self.run_checkout().returncode)
        result = self.run_checkout(WALLET_CORE_REF='v1-test')
        self.assertEqual(result.returncode, 2)
        self.assertIn('already exists', result.stderr)
        self.assertEqual(git('rev-parse', 'HEAD', cwd=self.checkout), self.first)

    def test_unknown_ref_fails_closed(self):
        result = self.run_checkout(WALLET_CORE_REF='nonexistent-upstream-tag')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('reference not found', result.stderr)

    def test_rejects_existing_non_git_target(self):
        self.checkout.mkdir(parents=True)
        result = self.run_checkout()
        self.assertEqual(2, result.returncode)
        self.assertIn('already exists', result.stderr)


@unittest.skipUnless(shutil.which('make'), 'requires GNU make')
class MakefileTests(unittest.TestCase):
    def test_single_make_build_invokes_buildx_export(self):
        # The Docker builder supplies its own target settings in the environment.
        # Both variants must honor the explicit Makefile arguments instead.
        for php_zts, abi_variant in (('0', 'nts'), ('1', 'zts')):
            with self.subTest(php_zts=php_zts):
                inherited = dict(os.environ, OS='alpine', OS_VERSION='3.24',
                                 PLATFORM='linux/arm64',
                                 PHP_ZTS='1' if php_zts == '0' else '0',
                                 PHP_VERSION='8.4.27')
                result = subprocess.run(
                    ['make', '-n', 'build', 'OS=debian', 'OS_VERSION=13',
                     'PLATFORM=linux/amd64', 'PHP_ZTS=' + php_zts,
                     'WALLET_CORE_REF=v1-test', 'PHP_VERSION=8.5.12'],
                    cwd=BASE, env=inherited, capture_output=True, text=True)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn('docker buildx build', result.stdout)
                self.assertIn('--target artifacts', result.stdout)
                expected = (
                    'build/debian13-amd64-php8.5.12-' + abi_variant
                    + '-wcv1-test/artifacts'
                )
                self.assertIn('tools/build/publish-artifacts.sh', result.stdout)
                self.assertIn(' "' + expected + '"', result.stdout)
                self.assertIn('--build-arg PHP_ZTS="' + php_zts + '"',
                              result.stdout)
                self.assertIn('logs/build.log', result.stdout)
                self.assertIn('WALLET_CORE_REF="v1-test"', result.stdout)
                for forbidden in ('git clone', 'docker run',
                                  'checkout-wallet-core.sh', 'php -n',
                                  'tools/build/build.sh'):
                    self.assertNotIn(forbidden, result.stdout)

    def test_test_and_update_remain_inside_buildx(self):
        test = subprocess.run(['make', '-n', 'test'],
                              cwd=BASE, capture_output=True, text=True)
        self.assertEqual(0, test.returncode, test.stderr)
        self.assertIn('--target verified', test.stdout)
        self.assertNotIn('--output', test.stdout)
        update = subprocess.run(['make', '-n', 'update'],
                                cwd=BASE, capture_output=True, text=True)
        self.assertEqual(0, update.returncode, update.stderr)
        self.assertIn('--no-cache-filter upstream', update.stdout)
        self.assertIn('WALLET_CORE_REFRESH=1', update.stdout)
        self.assertIn('--target artifacts', update.stdout)

    @unittest.skipUnless(shutil.which('git'), 'requires Git for ignore verification')
    def test_generated_outputs_are_gitignored(self):
        # Docker deliberately copies the source without .git. Use a temporary
        # repository so this validates actual Git ignore behavior in both
        # Docker and local source archives.
        with tempfile.TemporaryDirectory() as temp:
            repository = Path(temp)
            subprocess.run(['git', 'init', '-q', str(repository)], check=True,
                           capture_output=True, text=True)
            shutil.copy2(BASE / '.gitignore', repository / '.gitignore')
            for relative in ('build/debian13-amd64-php8.5.12-nts-wc4.8.4/artifacts/walletcore.so',
                             'build/debian13-amd64-php8.4.27-nts-wc4.7.0/logs/asan.log',
                             'build/matrix.json',
                             'dist/walletcore.so', 'build/walletcore.c',
                             'release/walletcore.so', 'asan-trace.log',
                             'asan-traces/failure.log', 'other/libwallet_core_rs.a',
                             '.idea/php.xml'):
                with self.subTest(relative=relative):
                    check = subprocess.run(
                        ['git', 'check-ignore', '--no-index', '-q', relative],
                        cwd=repository, capture_output=True, text=True)
                    self.assertEqual(0, check.returncode,
                                     f'{relative}: {check.stderr}')

    def test_failed_build_preserves_previous_artifact_and_saves_log(self):
        with tempfile.TemporaryDirectory() as temp:
            wrapper = Path(temp) / 'fake-docker'
            wrapper.write_text('#!/bin/sh\necho "docker failed" >&2\nexit 13\n')
            wrapper.chmod(0o755)
            case = BASE / 'build/debian13-amd64-php8.5.12-nts-wcfailed-fixture'
            artifact = case / 'artifacts' / 'walletcore.so'
            artifact.parent.mkdir(parents=True, exist_ok=True)
            artifact.write_bytes(b'last known good binary')
            try:
                command = ['make', 'build', 'DOCKER=' + str(wrapper),
                           'OS=debian', 'OS_VERSION=13', 'PLATFORM=linux/amd64',
                           'PHP_ZTS=0', 'PHP_VERSION=8.5.12',
                           'WALLET_CORE_REF=failed-fixture']
                # The Docker build injects its target PHP and OS settings into
                # the environment. This fixture must remain an isolated Debian
                # case even when those inherited values describe Alpine.
                inherited = dict(os.environ, OS='alpine', OS_VERSION='3.24',
                                 PLATFORM='linux/arm64', PHP_ZTS='1',
                                 PHP_VERSION='8.4.27')
                failed = subprocess.run(command, cwd=BASE, env=inherited,
                                        capture_output=True, text=True)
                self.assertNotEqual(0, failed.returncode, failed.stderr)
                self.assertEqual(b'last known good binary', artifact.read_bytes())
                self.assertIn('docker failed', (case / 'logs/build.log').read_text())
            finally:
                shutil.rmtree(case, ignore_errors=True)

    def test_build_root_rejects_paths_outside_build(self):
        with tempfile.TemporaryDirectory() as temp:
            fake = Path(temp) / 'fake-docker'
            fake.write_text('#!/bin/sh\necho SHOULD_NOT_RUN\nexit 0\n')
            fake.chmod(0o755)
            for build_root in ('dist', 'build/../output', temp):
                with self.subTest(build_root=build_root):
                    run = subprocess.run(['make', 'build', 'DOCKER=' + str(fake),
                                          'BUILD_ROOT=' + build_root],
                                         cwd=BASE, capture_output=True, text=True)
                    self.assertNotEqual(0, run.returncode)
                    self.assertNotIn('SHOULD_NOT_RUN', run.stdout)

    def test_buildkit_shared_source_caches_are_not_image_specific(self):
        dockerfile = (BASE / 'docker/Dockerfile').read_text()
        self.assertIn('id=walletcore-git-v1', dockerfile)
        self.assertIn('id=php-src-git-v1', dockerfile)
        for cache in ('walletcore-cargo-git-v1', 'walletcore-cargo-registry-v1'):
            self.assertEqual(3, dockerfile.count('id=' + cache))
        # Git mirrors serialize fetches; compiled Rust target dirs must be
        # locked too, while ccache supports parallel compiler writes.
        self.assertIn('id=walletcore-git-v1,target=/var/cache/walletcore-git,sharing=locked',
                      dockerfile)
        self.assertIn('id=php-src-git-v1,target=/var/cache/php-git,sharing=locked',
                      dockerfile)
        self.assertEqual(2, dockerfile.count('target=/work/wallet-core/rust/target,sharing=locked'))
        self.assertEqual(2, dockerfile.count('target=/work/wallet-core/codegen-v2/target,sharing=locked'))
        self.assertEqual(3, dockerfile.count('target=/var/cache/walletcore-ccache,sharing=shared'))
        self.assertIn('WALLET_CORE_GIT_CACHE=/var/cache/walletcore-git', dockerfile)
        self.assertIn('PHP_GIT_CACHE=/var/cache/php-git', dockerfile)

    def test_multistage_dockerfile_exposes_only_artifacts(self):
        dockerfile = (BASE / 'docker/Dockerfile').read_text()
        self.assertIn('AS upstream', dockerfile)
        self.assertIn('AS verified', dockerfile)
        self.assertIn('FROM scratch AS artifacts', dockerfile)
        self.assertIn('COPY --from=verified /work/export/ /', dockerfile)
        self.assertIn('php -n -d extension=', dockerfile)
        self.assertIn('bash tools/build/build.sh', dockerfile)
        self.assertIn('bash tools/build/export-artifacts.sh', dockerfile)
        ignore = (BASE / '.dockerignore').read_text().splitlines()
        self.assertIn('dist', ignore)
        self.assertIn('build', ignore)



if __name__ == '__main__':
    unittest.main()
