"""OCI publishing consumes verified outputs and cannot silently mix target ABIs."""
import importlib.util
import hashlib
import json
from pathlib import Path
import struct
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('walletcore_oci_publish', ROOT / 'tools/oci/publish.py')
oci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(oci)


def fake_elf(machine):
    header = bytearray(64)
    header[:7] = b'\x7fELF\x02\x01\x01'
    struct.pack_into('<HH', header, 16, 3, machine)
    return bytes(header)


class OciPublisherTests(unittest.TestCase):
    def setUp(self):
        (ROOT / 'build').mkdir(exist_ok=True)
        self.tmp = tempfile.TemporaryDirectory(dir=ROOT / 'build')
        self.addCleanup(self.tmp.cleanup)
        self.build_root = Path(self.tmp.name)
        self.args = type('Arguments', (), {
            'image': 'ghcr.io/example/walletcore-php', 'os': 'alpine',
            'os_version': '3.24', 'php': '8.4.27', 'zts': '0',
            'core': '4.8.4', 'platforms': 'linux/amd64',
            'build_root': str(self.build_root), 'docker': 'docker',
        })()

    def make_artifacts(self, platform='linux/amd64', corrupt=False):
        directory = oci.case_dir(self.build_root, self.args.php, self.args.core,
                                 self.args.os, self.args.os_version, platform,
                                 self.args.zts) / 'artifacts'
        directory.mkdir(parents=True)
        binary = fake_elf(oci.PLATFORMS[platform])
        (directory / 'walletcore.so').write_bytes(binary)
        (directory / 'walletcore.stub.php').write_text('<?php class Stub {}')
        functions = {'TWProbe': {'status': 'generated'}}
        (directory / 'abi.json').write_text(json.dumps({
            'functions': functions, 'parsed_functions': 1, 'header_errors': [],
        }))
        (directory / 'build-info.json').write_text(json.dumps({
            'os_id': self.args.os, 'os_version': self.args.os_version,
            'libc': 'musl' if self.args.os == 'alpine' else 'glibc',
            'php_version': self.args.php, 'php_zts': self.args.zts == '1',
            'php_debug': False, 'wallet_core_ref': self.args.core,
            'target_platform': platform,
            'target_architecture': 'x86_64' if platform.endswith('amd64') else 'aarch64',
            'php_api': '20240924', 'needed_libraries': ['libc.musl-x86_64.so.1'],
            'walletcore_sha256': '0' * 64 if corrupt else hashlib.sha256(binary).hexdigest(),
            'wallet_core_repository': 'https://github.com/trustwallet/wallet-core.git',
            'wallet_core_commit': 'a' * 40, 'php_extension_dir': '/opt/php/lib/extensions/no-debug-non-zts',
            'target_system': 'Linux', 'clang_version': 'clang 22', 'rustc_version': 'rust 1.94',
        }))
        return directory

    def test_release_tag_is_abi_based_not_patch_version(self):
        self.assertEqual('4.8.4-php8.4-alpine3.24-nts',
                         oci.release_tag('4.8.4', '8.4.27', 'alpine', '3.24', '0'))
        self.assertEqual('4.8.4-php8.4-debian13-zts',
                         oci.release_tag('4.8.4', '8.4.27', 'debian', '13', '1'))

    def test_single_arch_publishes_data_only_image_then_release_tag(self):
        self.make_artifacts()
        tag, profiles = oci.prepare(self.args)
        self.assertEqual(['linux/amd64'], [p for p, _ in profiles])
        commands = []

        def run(command, check):
            self.assertTrue(check)
            if command[2] == 'build':
                context = Path(command[-1])
                self.assertEqual((profiles[0][1] / 'walletcore.so').read_bytes(),
                                 (context / 'walletcore.so').read_bytes())
                self.assertEqual(oci.DOCKERFILE.read_bytes(), (context / 'Dockerfile').read_bytes())
            commands.append(command)

        with mock.patch.object(oci.subprocess, 'run', side_effect=run):
            oci.publish(self.args, tag, profiles)
        self.assertEqual(2, len(commands))
        self.assertIn('linux/amd64', commands[0])
        self.assertIn('ghcr.io/example/walletcore-php:4.8.4-php8.4-alpine3.24-nts-amd64', commands[0])
        self.assertEqual(['docker', 'buildx', 'imagetools', 'create'], commands[1][:4])
        self.assertEqual('ghcr.io/example/walletcore-php:4.8.4-php8.4-alpine3.24-nts', commands[1][5])

    def test_multiarch_validates_all_inputs_before_any_push(self):
        self.args.platforms = 'linux/amd64 linux/arm64'
        self.make_artifacts('linux/amd64')
        with mock.patch.object(oci.subprocess, 'run') as run:
            with self.assertRaisesRegex(ValueError, 'missing verified artifacts'):
                oci.prepare(self.args)
            run.assert_not_called()
        self.make_artifacts('linux/arm64')
        tag, profiles = oci.prepare(self.args)
        with mock.patch.object(oci.subprocess, 'run') as run:
            oci.publish(self.args, tag, profiles)
        self.assertEqual(3, run.call_count)
        self.assertIn('linux/arm64', run.call_args_list[1].args[0])
        self.assertEqual(2, len(run.call_args_list[2].args[0][-2:]))

    def test_failed_architecture_push_does_not_replace_release_tag(self):
        self.args.platforms = 'linux/amd64 linux/arm64'
        self.make_artifacts('linux/amd64')
        self.make_artifacts('linux/arm64')
        tag, profiles = oci.prepare(self.args)
        commands = []

        def run(command, check):
            commands.append(command)
            if len(commands) == 2:
                raise oci.subprocess.CalledProcessError(1, command)

        with mock.patch.object(oci.subprocess, 'run', side_effect=run):
            with self.assertRaises(oci.subprocess.CalledProcessError):
                oci.publish(self.args, tag, profiles)
        self.assertEqual(2, len(commands))
        self.assertTrue(all(command[2] == 'build' for command in commands))

    def test_bad_hash_or_abi_cannot_publish(self):
        artifacts = self.make_artifacts(corrupt=True)
        with self.assertRaisesRegex(ValueError, 'SHA-256'):
            oci.prepare(self.args)
        info = json.loads((artifacts / 'build-info.json').read_text())
        info['walletcore_sha256'] = hashlib.sha256((artifacts / 'walletcore.so').read_bytes()).hexdigest()
        info['php_zts'] = True
        (artifacts / 'build-info.json').write_text(json.dumps(info))
        with self.assertRaisesRegex(ValueError, 'target mismatch'):
            oci.prepare(self.args)

    def test_wrong_architecture_or_non_elf_rejected(self):
        artifacts = self.make_artifacts()
        (artifacts / 'walletcore.so').write_bytes(fake_elf(183))
        info = json.loads((artifacts / 'build-info.json').read_text())
        info['walletcore_sha256'] = hashlib.sha256((artifacts / 'walletcore.so').read_bytes()).hexdigest()
        (artifacts / 'build-info.json').write_text(json.dumps(info))
        with self.assertRaisesRegex(ValueError, 'ELF type/architecture mismatch'):
            oci.prepare(self.args)
        (artifacts / 'walletcore.so').write_bytes(b'fake')
        info['walletcore_sha256'] = hashlib.sha256(b'fake').hexdigest()
        (artifacts / 'build-info.json').write_text(json.dumps(info))
        with self.assertRaisesRegex(ValueError, 'not a 64-bit little-endian ELF'):
            oci.prepare(self.args)

    def test_invalid_registry_or_duplicate_profiles_rejected(self):
        self.make_artifacts()
        self.args.image = 'ghcr.io/Example/invalid:latest'
        with self.assertRaisesRegex(ValueError, 'OCI_IMAGE'):
            oci.prepare(self.args)
        self.args.image = 'ghcr.io/example/walletcore-php'
        self.args.platforms = 'linux/amd64 linux/amd64'
        with self.assertRaisesRegex(ValueError, 'OCI_PLATFORMS'):
            oci.prepare(self.args)

    def test_symlinks_rejected(self):
        directory = self.make_artifacts()
        (directory / 'walletcore.so').rename(directory / 'real.so')
        (directory / 'walletcore.so').symlink_to(directory / 'real.so')
        with self.assertRaisesRegex(ValueError, 'symlinked artifact'):
            oci.prepare(self.args)


if __name__ == '__main__':
    unittest.main()
