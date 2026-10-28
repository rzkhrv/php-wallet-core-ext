"""Regression checks for the isolated sanitized-PHP pipeline."""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AsanWiringTests(unittest.TestCase):
    def test_all_php_scenarios_have_diagnostic_checks(self):
        docker = (ROOT / 'docker/Dockerfile').read_text()
        section = docker.split('FROM asan_php AS asan_verified')[1]
        for script in ('tests/php/runtime.php', 'tests/php/binding_safety.php',
                       'tests/php/integration.php', 'tests/php/ownership_stress.php'):
            self.assertIn(script, section)
        self.assertIn('ASan/LSan baseline: instrumented PHP', section)
        self.assertIn('python3 -m unittest discover', section)
        self.assertIn('ASAN_DETECT_LEAKS="${ASAN_DETECT_LEAKS}"', section)
        self.assertNotIn('ASAN_DETECT_LEAKS=0', section)

    def test_protobuf_shutdown_is_asan_only_and_linked_from_same_archive(self):
        generator = (ROOT / 'tools/codegen/generate.py').read_text()
        builder = (ROOT / 'tools/build/build.sh').read_text()
        linker = (ROOT / 'tools/build/link-static.sh').read_text()
        bridge = (ROOT / 'tools/asan/asan-protobuf-cleanup.cpp').read_text()
        self.assertIn('WC_ASAN_PROTOBUF_CLEANUP', generator)
        self.assertIn('WC_MODULE_SHUTDOWN NULL', generator)
        self.assertIn('WALLETCORE_SANITIZE:-', builder)
        self.assertIn('asan_extra_defines=(-DWC_ASAN_PROTOBUF_CLEANUP=1)', builder)
        self.assertIn('WC_EXTRA_OBJECTS="$extra_objects"', builder)
        self.assertIn('WC_EXTRA_OBJECTS:-', linker)
        self.assertIn('"${extra_objects[@]}"', linker)
        self.assertIn('google::protobuf::ShutdownProtobufLibrary()', bridge)
        self.assertNotIn('ShutdownProtobufLibrary', (ROOT / 'tools/asan/asan-cxx.sh').read_text())

    @unittest.skipUnless(shutil.which('c++'), 'C++ compiler unavailable')
    def test_shutdown_bridge_calls_protobuf_api(self):
        # Build the bridge against a minimal protobuf-compatible declaration;
        # this checks linkage without relying on a locally installed protobuf.
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            header = root / 'google/protobuf/message_lite.h'
            header.parent.mkdir(parents=True)
            header.write_text('namespace google { namespace protobuf { '
                              'void ShutdownProtobufLibrary(); } }\n')
            mock = root / 'mock.cpp'
            mock.write_text('#include <google/protobuf/message_lite.h>\n#include <cstdio>\nnamespace google { namespace protobuf {\nvoid ShutdownProtobufLibrary() { std::puts("protobuf shutdown invoked"); }\n} }\nextern "C" void wc_asan_protobuf_cleanup(void);\nint main() { wc_asan_protobuf_cleanup(); }\n')
            executable = root / 'test'
            build = subprocess.run(['c++', '-std=c++14', '-I' + str(root),
                                    str(ROOT / 'tools/asan/asan-protobuf-cleanup.cpp'),
                                    str(mock), '-o', str(executable)],
                                   capture_output=True, text=True)
            self.assertEqual(build.returncode, 0, build.stderr)
            call = subprocess.run([str(executable)], capture_output=True, text=True)
            self.assertEqual(call.returncode, 0, call.stderr)
            self.assertIn('protobuf shutdown invoked', call.stdout)

    def test_native_build_bootstrapping_remains_isolated(self):
        build = (ROOT / 'tools/asan/build-asan.sh').read_text()
        self.assertIn('protoc-gen-c-typedef', build)
        self.assertIn('WALLET_CORE_INSTALL_DEPS=0', build)
        self.assertIn('export PHP_BINARY="$ROOT/tools/asan/asan-php.sh"', build)
        with tempfile.TemporaryDirectory() as temp:
            upstream = Path(temp) / 'wallet-core'
            (upstream / 'build/local/bin').mkdir(parents=True)
            env = os.environ.copy()
            env.update({'WALLET_CORE_DIR': str(upstream),
                        'WALLET_CORE_BUILD_DIR': str(upstream / 'build-asan'),
                        'WALLET_CORE_INSTALL_DEPS': '0'})
            cmd = ['bash', str(ROOT / 'tools/asan/build-asan.sh'), '--native-only']
            got = subprocess.run(cmd, env=env, capture_output=True, text=True)
            self.assertEqual(got.returncode, 2)
            self.assertIn('missing uninstrumented build tool', got.stderr)
            for tool in ('protoc', 'protoc-gen-c-typedef'):
                (upstream / 'build/local/bin' / tool).symlink_to('/bin/true')
            env['WALLET_CORE_BUILD_DIR'] = str(upstream / 'build')
            got = subprocess.run(cmd, env=env, capture_output=True, text=True)
            self.assertEqual(got.returncode, 2)
            self.assertIn('distinct WALLET_CORE_BUILD_DIR', got.stderr)
            env['WALLET_CORE_BUILD_DIR'] = str(upstream / 'build-asan')
            env['WALLET_CORE_INSTALL_DEPS'] = '1'
            got = subprocess.run(cmd, env=env, capture_output=True, text=True)
            self.assertEqual(got.returncode, 2)
            self.assertIn('WALLET_CORE_INSTALL_DEPS=0', got.stderr)

    def test_php_probe_reports_failure_and_does_not_claim_walletcore_fault(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            fake_php = directory / 'php'
            fake_php.write_text('#!/bin/sh\nprintf "probe diagnostic\\n" >&2\nexit 139\n')
            fake_php.chmod(0o755)
            fake_config = directory / 'php-config'
            fake_config.write_text('#!/bin/sh\necho 8.1.2\n')
            fake_config.chmod(0o755)
            # The fake PHP is a shell script, not an ELF executable. The
            # test is about a failed PHP startup AFTER the ELF preflight, so
            # mock readelf to model a correctly linked non-PIE PHP executable.
            fake_readelf = directory / 'readelf'
            fake_readelf.write_text('#!/bin/sh\necho "  Type: EXEC (Executable file)"\n')
            fake_readelf.chmod(0o755)
            env = os.environ.copy()
            env.update({'PHP_ASAN_BIN': str(fake_php), 'PHP_CONFIG': str(fake_config),
                        'PATH': str(directory) + os.pathsep + env['PATH']})
            run = subprocess.run(['bash', str(ROOT / 'tools/asan/check-asan-php.sh')],
                                 env=env, capture_output=True, text=True)
            self.assertEqual(run.returncode, 139, run.stderr)
            self.assertIn('instrumented PHP failed (startup stability 1/8)', run.stderr)
            self.assertIn('FAILURE occurred without loading walletcore.so.', run.stderr)

    def test_runner_fails_closed_without_sanitized_php(self):
        command = ['bash', str(ROOT / 'tools/asan/run-asan-php.sh'), '-n', '-v']
        invalid = subprocess.run(command, env={'ASAN_DETECT_LEAKS': 'invalid'},
                                 capture_output=True, text=True)
        self.assertEqual(invalid.returncode, 2)
        self.assertIn('must be 0 or 1', invalid.stderr)
        absent = subprocess.run(command, env={
            'ASAN_DETECT_LEAKS': '1', 'PHP_ASAN_BIN': '/nonexistent/asan/php'},
            capture_output=True, text=True)
        self.assertEqual(absent.returncode, 2)
        self.assertIn('missing instrumented PHP CLI', absent.stderr)

    @unittest.skipUnless(shutil.which('php'), 'PHP syntax checker unavailable')
    def test_stress_script_valid_syntax(self):
        result = subprocess.run(['php', '-n', '-l', 'tests/php/ownership_stress.php'],
                                cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main()
