"""Diagnostic failures must leave one complete, actionable host-side trace."""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AsanTraceTests(unittest.TestCase):
    def test_failed_docker_run_persists_both_streams_and_real_exit_code(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / 'diagnostics' / 'asan trace.log'
            fake = Path(temp) / 'fake-docker'
            fake.write_text('#!/bin/sh\n'
                            'echo "ASAN_CRASH: native stack" >&2\n'
                            'echo "progress: generated bindings"\n'
                            'exit 23\n')
            fake.chmod(0o755)
            run = subprocess.run(
                ['bash', str(ROOT / 'tools/asan/capture-asan-trace.sh'), str(output),
                 str(fake), 'buildx', 'build', '--progress=plain'],
                capture_output=True, text=True)
            self.assertEqual(23, run.returncode, run.stderr)
            content = output.read_text()
            self.assertIn('ASAN_CRASH: native stack', content)
            self.assertIn('progress: generated bindings', content)
            self.assertIn('docker_exit_code=23', content)
            self.assertIn('buildx build --progress=plain', content)
            self.assertIn(str(output), run.stderr)

    def test_success_overwrites_previous_trace(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / 'asan-trace.log'
            output.write_text('old secret log must be replaced\n')
            run = subprocess.run(
                ['bash', str(ROOT / 'tools/asan/capture-asan-trace.sh'), str(output),
                 '/bin/sh', '-c', 'echo pass'], capture_output=True, text=True)
            self.assertEqual(0, run.returncode, run.stderr)
            content = output.read_text()
            self.assertIn('docker_exit_code=0', content)
            self.assertNotIn('old secret log', content)

    def test_unknown_frame_is_symbolized_from_saved_elf_mapping(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp)
            symbolizer = path / 'llvm-symbolizer'
            symbolizer.write_text('#!/bin/sh\necho "TWAnySignerSign"\n'
                                  'echo "Signer.cpp:95:9"\n')
            symbolizer.chmod(0o755)
            report = path / 'asan-case.log'
            report.write_text(
                'WC_ASAN_MAP 70000000-70002000 r-xp 00001000 00:01 5 /bin/sh\n'
                '==9==ERROR: LeakSanitizer: detected memory leaks\n'
                '  #1 0x70000234  (<unknown module>)\n'
            )
            env = os.environ.copy()
            env['PATH'] = str(path) + os.pathsep + env['PATH']
            result = subprocess.run(
                ['python3', str(ROOT / 'tools/asan/symbolize-asan-trace.py'), str(report)],
                env=env, capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn('file_offset=0x1234', result.stdout)
            self.assertIn('TWAnySignerSign', result.stdout)
            self.assertIn('Signer.cpp:95:9', result.stdout)

    def test_failed_php_case_survives_with_symbolized_frames_and_exit_status(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp)
            fake_php = path / 'sanitized-php'
            fake_php.write_text(
                '#!/bin/sh\n'
                'echo "WC_ASAN_MAP 70000000-70002000 r-xp 00001000 00:01 5 /bin/sh"\n'
                'echo "==13==ERROR: LeakSanitizer: detected memory leaks" >&2\n'
                'echo "    #1 0x70000234  (<unknown module>)" >&2\n'
                'exit 23\n')
            fake_php.chmod(0o755)
            fake_sym = path / 'llvm-symbolizer'
            fake_sym.write_text('#!/bin/sh\necho "TWPrivateKeyCreate"\n'
                                'echo "PrivateKey.cpp:17"\n')
            fake_sym.chmod(0o755)
            env = os.environ.copy()
            env['PHP_ASAN_BIN'] = str(fake_php)
            env['PATH'] = str(path) + os.pathsep + env['PATH']
            run = subprocess.run(
                ['bash', str(ROOT / 'tools/asan/asan-trace-test.sh'), 'tests/php/runtime.php'],
                env=env, capture_output=True, text=True)
            self.assertEqual(23, run.returncode, run.stderr)
            self.assertIn('ASAN_CASE_EXIT_CODE=23', run.stdout)
            self.assertIn('detected memory leaks', run.stdout)
            self.assertIn('WC_ASAN_FRAME pc=0x70000234', run.stdout)
            self.assertIn('TWPrivateKeyCreate', run.stdout)

    def test_default_trace_is_ignored_and_wired_into_make_and_buildx(self):
        make = (ROOT / 'Makefile').read_text()
        self.assertIn('$(LOG_DIR)/asan.log', make)
        self.assertIn('tools/asan/capture-asan-trace.sh', make)
        self.assertIn('--target asan_verified', make)
        self.assertIn('$(DOCKER_BUILD)', make)
        self.assertIn('build/**', (ROOT / '.dockerignore').read_text())
        docker = (ROOT / 'docker/Dockerfile').read_text()
        self.assertEqual(4, docker.count('bash tools/asan/asan-trace-test.sh'))
        php_maps = (ROOT / 'tools/asan/asan-module-maps.php').read_text()
        self.assertIn('/proc/self/maps', php_maps)
        self.assertIn('WC_ASAN_MAP', php_maps)

    @unittest.skipUnless(shutil.which('php'), 'PHP syntax checker unavailable')
    def test_php_mapping_helper_syntax(self):
        result = subprocess.run(
            ['php', '-n', '-l', str(ROOT / 'tools/asan/asan-module-maps.php')],
            capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)


if __name__ == '__main__':
    unittest.main()
