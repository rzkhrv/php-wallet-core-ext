"""Atomic publication: never expose a partial or stale release on Docker failure."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'tools/build/publish-artifacts.sh'


class PublishTests(unittest.TestCase):
    def setUp(self):
        (ROOT / 'build').mkdir(exist_ok=True)
        self.tmp = tempfile.TemporaryDirectory(dir=ROOT / 'build')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.artifacts = self.root / 'artifacts'
        self.log = self.root / 'logs/build.log'
        self.fake = self.root / 'docker'
        self.fake.write_text('''#!/usr/bin/env python3
import os, pathlib, sys
output = next((x.split('dest=', 1)[1] for x in sys.argv[1:] if x.startswith('type=local,dest=')), None)
if output is None:
    sys.exit(30)
out = pathlib.Path(output)
(out / 'walletcore.so').write_bytes(b'new binary')
if os.environ.get('FAIL_EXPORT') == '1':
    print('simulated Docker failure')
    sys.exit(13)
if os.environ.get('INCOMPLETE_EXPORT') == '1':
    sys.exit(0)
for name in ('walletcore.stub.php', 'abi.json', 'build-info.json'):
    (out / name).write_text('{}')
''')
        self.fake.chmod(0o755)

    def run_publish(self, **kwargs):
        return subprocess.run(['bash', str(SCRIPT), str(self.artifacts),
            str(self.log), str(self.fake), 'buildx', 'build'], cwd=ROOT,
            capture_output=True, text=True, env=dict(os.environ, **kwargs))

    def test_success_replaces_previous_release(self):
        self.artifacts.mkdir()
        (self.artifacts / 'walletcore.so').write_bytes(b'old')
        p = self.run_publish()
        self.assertEqual(0, p.returncode, p.stderr)
        self.assertEqual(b'new binary', (self.artifacts / 'walletcore.so').read_bytes())
        self.assertTrue((self.artifacts / 'build-info.json').is_file())
        self.assertFalse(list(self.root.glob('.incoming.*')))

    def test_failure_keeps_old_release_and_only_one_log(self):
        self.artifacts.mkdir()
        (self.artifacts / 'walletcore.so').write_bytes(b'old')
        p = self.run_publish(FAIL_EXPORT='1')
        self.assertEqual(13, p.returncode)
        self.assertEqual(b'old', (self.artifacts / 'walletcore.so').read_bytes())
        self.assertIn('simulated Docker failure', self.log.read_text())
        self.assertFalse(list(self.root.glob('.incoming.*')))

    def test_failure_without_prior_release_leaves_no_empty_artifacts(self):
        p = self.run_publish(FAIL_EXPORT='1')
        self.assertNotEqual(0, p.returncode)
        self.assertFalse(self.artifacts.exists())

    def test_zero_exit_with_incomplete_export_does_not_publish(self):
        p = self.run_publish(INCOMPLETE_EXPORT='1')
        self.assertEqual(2, p.returncode)
        self.assertFalse(self.artifacts.exists())
        self.assertFalse(list(self.root.glob('.incoming.*')))


if __name__ == '__main__':
    unittest.main()
