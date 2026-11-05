"""All host build outputs are grouped by PHP and Wallet Core revision."""
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class BuildLayoutTests(unittest.TestCase):
    def preview(self, target, **options):
        profile = {'OS': 'debian', 'OS_VERSION': '13',
                   'PLATFORM': 'linux/amd64', 'PHP_ZTS': '0'}
        profile.update(options)
        proc = subprocess.run(['make', '-n', target] +
                              [f'{key}={value}' for key, value in profile.items()],
                              cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(0, proc.returncode, proc.stderr)
        return proc.stdout

    def test_build_test_asan_paths_share_version_pair(self):
        opts = dict(PHP_VERSION='8.4.27', WALLET_CORE_REF='release/4.7.0')
        prefix = 'build/debian13-amd64-php8.4.27-nts-wcrelease%2F4.7.0'
        self.assertIn(prefix + '/artifacts', self.preview('build', **opts))
        self.assertIn(prefix + '/logs/build.log', self.preview('build', **opts))
        self.assertIn(prefix + '/logs/test.log', self.preview('test', **opts))
        self.assertIn(prefix + '/logs/asan.log', self.preview('test-asan', **opts))

    def test_profile_and_abi_are_isolated(self):
        opts = dict(OS='alpine', OS_VERSION='3.24', PHP_VERSION='8.4.27',
                    PHP_ZTS='1', PLATFORM='linux/arm64', WALLET_CORE_REF='4.7.0')
        prefix = 'build/alpine3.24-arm64-php8.4.27-zts-wc4.7.0'
        for target in ('build', 'test', 'test-asan'):
            self.assertIn(prefix + '/logs/', self.preview(target, **opts))
        preview = self.preview('build', **opts)
        for arg in ('--platform="linux/arm64"', '--build-arg OS="alpine"',
                    '--build-arg OS_VERSION="3.24"', '--build-arg PHP_ZTS="1"'):
            self.assertIn(arg, preview)

    def test_rejects_unsafe_or_unsupported_profiles(self):
        script = ROOT / 'tools/build/check-build-output.sh'
        for os_id, version, platform, zts in (
            ('unsupported', '24.04', 'linux/amd64', '0'),
            ('alpine', '../../evil', 'linux/amd64', '0'),
            ('debian', '13', 'linux/386', '0'),
            ('debian', '13', 'linux/amd64', '2'),
        ):
            p = subprocess.run(['bash', str(script), 'build', '8.4.27', '4.7.0',
                                os_id, version, platform, zts], cwd=ROOT,
                               capture_output=True, text=True)
            self.assertNotEqual(p.returncode, 0, (os_id, version, platform, zts))

    def test_additional_distro_profiles_use_distinct_image_tags_and_output_dirs(self):
        images = {
            ('ubuntu', '24.04'): 'ubuntu:24.04',
            ('fedora', '44'): 'fedora:44',
            ('rockylinux', '9'): 'rockylinux/rockylinux:9',
            ('almalinux', '9'): 'almalinux:9',
        }
        for (os_id, release), image in images.items():
            with self.subTest(os_id=os_id, release=release):
                dry = self.preview('build', OS=os_id, OS_VERSION=release,
                                   PHP_VERSION='8.4.27', WALLET_CORE_REF='4.8.4')
                self.assertIn(f'--build-arg BASE_IMAGE="{image}"', dry)
                self.assertIn(f'build/{os_id}{release}-amd64-php8.4.27-nts-wc4.8.4/artifacts', dry)
                validator = subprocess.run([
                    'bash', str(ROOT / 'tools/build/check-build-output.sh'),
                    'build', '8.4.27', '4.8.4', os_id, release, 'linux/amd64', '0'
                ], cwd=ROOT, capture_output=True, text=True)
                self.assertEqual(0, validator.returncode, validator.stderr)

    def test_validator_matches_flat_profile_and_rejects_symlinked_case(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'build') as temp:
            parent = Path(temp)
            outside = parent / 'destination'
            outside.mkdir()
            profile = parent / 'alpine3.22-amd64-php8.4.27-nts-wc4.8.4'
            profile.symlink_to(outside, target_is_directory=True)
            args = ['bash', str(ROOT / 'tools/build/check-build-output.sh'),
                    str(parent.relative_to(ROOT)), '8.4.27', '4.8.4',
                    'alpine', '3.22', 'linux/amd64', '0']
            proc = subprocess.run(args, cwd=ROOT, capture_output=True, text=True)
            self.assertNotEqual(0, proc.returncode)
            self.assertIn('symlink', proc.stderr)

    def test_validation_works_without_gnu_realpath(self):
        # Alpine's BusyBox realpath has no -m. The validator needs only Python
        # to canonicalize paths that do not exist yet.
        with tempfile.TemporaryDirectory() as temp:
            bin_dir = Path(temp)
            (bin_dir / 'python3').symlink_to(Path(sys.executable).resolve())
            command = ['/bin/bash', str(ROOT / 'tools/build/check-build-output.sh'),
                       'build', '8.4.27', '4.8.4', 'alpine', '3.24',
                       'linux/amd64', '0']
            result = subprocess.run(command, cwd=ROOT, env={'PATH': str(bin_dir)},
                                    capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)

    def test_trace_and_test_logs_survive_failed_commands(self):
        script = ROOT / 'tools/build/capture-build-log.sh'
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'logs' / 'build.log'
            result = subprocess.run(['bash', str(script), str(path),
                                     '/bin/sh', '-c', 'echo failed-build; exit 29'],
                                    capture_output=True, text=True)
            self.assertEqual(29, result.returncode)
            self.assertIn('failed-build', path.read_text())
            self.assertIn(str(path), result.stderr)

    def test_ref_validation_and_root_validation(self):
        script = ROOT / 'tools/build/check-build-output.sh'
        for root, php, core in [ ('build', '8.5.12', 'master'),
                                 ('build', '8.4.27', 'release/4.7.0') ]:
            good = subprocess.run(['bash', str(script), root, php, core, 'debian', '13', 'linux/amd64', '0'],
                                  cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(0, good.returncode, good.stderr)
        for root, php, core in [ ('dist', '8.5.12', 'master'),
                                 ('build/../../dist', '8.5.12', 'master'),
                                 ('build', '../../x', 'master'),
                                 ('build', '8.5.12', '../../evil') ]:
            bad = subprocess.run(['bash', str(script), root, php, core, 'debian', '13', 'linux/amd64', '0'],
                                 cwd=ROOT, capture_output=True, text=True)
            self.assertNotEqual(0, bad.returncode)


if __name__ == '__main__':
    unittest.main()
