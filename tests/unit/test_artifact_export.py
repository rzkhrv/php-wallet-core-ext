"""Validate release metadata and exported files without building Wallet Core."""
import hashlib
import json
import os
import shlex
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('git'), 'requires Git in the test container')
class ArtifactExportTests(unittest.TestCase):
    def test_export_contains_only_compiled_module_and_audit_files(self):
        with tempfile.TemporaryDirectory() as tmp:
            folder = Path(tmp)
            upstream = folder / 'upstream'
            upstream.mkdir()
            subprocess.run(['git', 'init', '-q', str(upstream)], check=True)
            subprocess.run(['git', '-C', str(upstream), 'config', 'user.name', 'Test'], check=True)
            subprocess.run(['git', '-C', str(upstream), 'config', 'user.email', 'test@example.invalid'], check=True)
            subprocess.run(['git', '-C', str(upstream), 'commit', '-q', '--allow-empty', '-m', 'test'], check=True)
            subprocess.run(['git', '-C', str(upstream), 'remote', 'add', 'origin', 'https://example.invalid/fixture.git'], check=True)
            build = folder / 'build'
            build.mkdir()
            data = b'example ELF fixture bytes, not a native build'
            (build / 'walletcore.so').write_bytes(data)
            (build / 'walletcore.stub.php').write_text('<?php')
            (build / 'walletcore.c').write_text('/* stub */')
            (build / 'abi.json').write_text('{}')
            (build / 'intermediates.a').write_bytes(b'excluded')
            mockbin = folder / 'mockbin'
            mockbin.mkdir()
            (upstream / 'rust').mkdir()
            for executable, message in (('clang', 'clang fixture version'),
                                        ('rustc', 'rustc fixture version')):
                mock = mockbin / executable
                mock.write_text('#!/bin/sh\necho ' + shlex.quote(message) + '\n')
                mock.chmod(0o755)
            mock_readelf = mockbin / 'readelf'
            mock_readelf.write_text('#!/bin/sh\necho "Shared library: [libc.so.6]"\n')
            mock_readelf.chmod(0o755)
            include_dir = folder / 'php85' / 'include' / 'php'
            (include_dir / 'Zend').mkdir(parents=True)
            (include_dir / 'Zend' / 'zend_modules.h').write_text(
                '#define ZEND_MODULE_API_NO 20250925\n')
            php_config = mockbin / 'php-config'
            php_config.write_text('#!/bin/sh\ncase "$1" in\n'
                                  '--version) echo 8.5.12 ;;\n'
                                  '--include-dir) echo ' + shlex.quote(str(include_dir)) + ' ;;\n'
                                  '--extension-dir) echo /usr/lib/php/modules ;;\n'
                                  '*) exit 1 ;;\nesac\n')
            php_config.chmod(0o755)
            env = dict(os.environ,
                       WALLET_CORE_DIR=str(upstream),
                       BUILD_DIR=str(build),
                       EXPORT_DIR=str(folder / 'export'),
                       WALLET_CORE_REF='v-fixture',
                       PATH=str(mockbin) + os.pathsep + os.environ['PATH'])
            # The ASan Docker stage exports PHP_CONFIG for its instrumented PHP.
            # This fixture must test the PATH fallback with *its own* php-config.
            env.pop('PHP_CONFIG', None)
            result = subprocess.run(['bash', str(ROOT / 'tools/build/export-artifacts.sh')],
                                    env=env, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            exported = folder / 'export'
            self.assertEqual({'walletcore.so', 'walletcore.stub.php',
                              'abi.json', 'build-info.json'},
                             {p.name for p in exported.iterdir()})
            info = json.loads((exported / 'build-info.json').read_text())
            self.assertEqual('8.5.12', info['php_version'])
            self.assertEqual('20250925', info['php_api'])
            self.assertEqual('v-fixture', info['wallet_core_ref'])
            self.assertIn(info['os_id'], ('debian', 'alpine', 'ubuntu', 'fedora', 'rockylinux', 'almalinux'))
            self.assertEqual('libc.so.6', info['needed_libraries'][0])
            runtime_zts = subprocess.check_output(
                ['php', '-n', '-r', 'echo (int) PHP_ZTS;'], text=True).strip()
            self.assertEqual(runtime_zts == '1', info['php_zts'])
            self.assertEqual('clang fixture version', info['clang_version'])
            self.assertEqual('rustc fixture version', info['rustc_version'])
            self.assertEqual(hashlib.sha256(data).hexdigest(), info['walletcore_sha256'])
            expected_commit = subprocess.check_output(
                ['git', '-C', str(upstream), 'rev-parse', 'HEAD'], text=True).strip()
            self.assertEqual(expected_commit, info['wallet_core_commit'])
            self.assertFalse((exported / 'walletcore.c').exists())
            # A caller can target a different installed PHP by setting
            # PHP_CONFIG. The exported ABI must describe that PHP, not an
            # unrelated php-config first in PATH.
            custom_include_dir = folder / 'php83' / 'include' / 'php'
            (custom_include_dir / 'Zend').mkdir(parents=True)
            (custom_include_dir / 'Zend' / 'zend_modules.h').write_text(
                '#define ZEND_MODULE_API_NO 20230831\n')
            custom_config = folder / 'php-config-selected'
            custom_config.write_text('#!/bin/sh\ncase "$1" in\n'
                                     '--version) echo 8.3.17 ;;\n'
                                     '--include-dir) echo ' + shlex.quote(str(custom_include_dir)) + ' ;;\n'
                                     '--extension-dir) echo /opt/php83/modules ;;\n'
                                     '*) exit 1 ;;\nesac\n')
            custom_config.chmod(0o755)
            (folder / 'export-custom').mkdir()
            (folder / 'export-custom/other.keep').write_text('user supplied')
            custom_env = dict(env, PHP_CONFIG=str(custom_config),
                              EXPORT_DIR=str(folder / 'export-custom'))
            result = subprocess.run(['bash', str(ROOT / 'tools/build/export-artifacts.sh')],
                                    env=custom_env, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            custom_info = json.loads((folder / 'export-custom/build-info.json').read_text())
            self.assertEqual('8.3.17', custom_info['php_version'])
            self.assertEqual('20230831', custom_info['php_api'])
            self.assertEqual('/opt/php83/modules', custom_info['php_extension_dir'])
            self.assertEqual('user supplied', (folder / 'export-custom/other.keep').read_text())

            # The export must reject a false OS profile or ZTS claim.
            wrong_os = subprocess.run(['bash', str(ROOT / 'tools/build/export-artifacts.sh')],
                                      env=dict(env, BUILD_OS='invalid'),
                                      capture_output=True, text=True)
            self.assertNotEqual(wrong_os.returncode, 0)
            self.assertIn('BUILD_OS does not match', wrong_os.stderr)
            wrong_zts = subprocess.run(['bash', str(ROOT / 'tools/build/export-artifacts.sh')],
                                       env=dict(env, BUILD_PHP_ZTS='0' if runtime_zts == '1' else '1'),
                                       capture_output=True, text=True)
            self.assertNotEqual(wrong_zts.returncode, 0)
            self.assertIn('PHP_ZTS build argument', wrong_zts.stderr)

            # A compiler-provenance error must fail at export, not only when
            # the already-published artifacts reach compatibility inspection.
            (mockbin / 'rustc').write_text('#!/bin/sh\nexit 7\n')
            missing = subprocess.run(['bash', str(ROOT / 'tools/build/export-artifacts.sh')],
                                     env=env, capture_output=True, text=True)
            self.assertNotEqual(0, missing.returncode)
            self.assertIn('rustc', missing.stderr)
            (mockbin / 'rustc').write_text('#!/bin/sh\necho rustc fixture version\n')

            # Unsupported/missing Zend headers must not produce invented ABI
            # metadata, even if --version works.
            (custom_include_dir / 'Zend' / 'zend_modules.h').write_text(
                '#define SOME_OTHER_MACRO 1234\n')
            failed = subprocess.run(['bash', str(ROOT / 'tools/build/export-artifacts.sh')],
                                    env=custom_env, capture_output=True, text=True)
            self.assertNotEqual(0, failed.returncode)
            self.assertIn('Cannot determine PHP Zend module API', failed.stderr)


if __name__ == '__main__':
    unittest.main()
