"""PHP bridge test syntax checks independent of network or coin protocols."""
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class IntegrationWiringTests(unittest.TestCase):
    def test_php_integration_syntax(self):
        php = shutil.which('php')
        if not php:
            self.skipTest('php not available outside Docker; Docker executes this PHP code')
        for source in ['tests/php/integration.php', 'tests/php/runtime.php',
                       'tests/php/binding_safety.php']:
            with self.subTest(source=source):
                result = subprocess.run([php, '-n', '-l', source], cwd=ROOT,
                                        text=True, capture_output=True)
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
