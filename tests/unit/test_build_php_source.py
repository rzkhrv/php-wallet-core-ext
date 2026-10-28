"""Offline PHP-source build contract tests; no PHP downloads or compilers."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'tools/build/build-php.sh'


@unittest.skipUnless(shutil.which('git') and shutil.which('bash'), 'requires git and bash')
class PhpSourceBuilderTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.source = self.root / 'upstream-php'
        self.source.mkdir()
        subprocess.run(['git', 'init', '-q', str(self.source)], check=True)
        subprocess.run(['git', '-C', str(self.source), 'config', 'user.email', 'fixture@example.invalid'], check=True)
        subprocess.run(['git', '-C', str(self.source), 'config', 'user.name', 'Fixture'], check=True)
        buildconf = self.source / 'buildconf'
        buildconf.write_text('#!/bin/sh\ncat > configure <<\'EOF\'\n'
                             '#!/bin/sh\nprintf "%s\\n" "$@" > "$TRACE_CONFIG"\n'
                             'EOF\nchmod +x configure\n')
        buildconf.chmod(0o755)
        subprocess.run(['git', '-C', str(self.source), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(self.source), 'commit', '-qm', 'php-source-fixture'], check=True)
        subprocess.run(['git', '-C', str(self.source), 'tag', 'php-8.4.27'], check=True)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        mock_make = self.bin / 'make'
        mock_make.write_text('''#!/bin/sh
set -eu
if [ "${1:-}" = install ]; then
    mkdir -p "$PHP_PREFIX/bin"
    printf '#!/bin/sh\\nexit 0\\n' > "$PHP_PREFIX/bin/php"
    printf '#!/bin/sh\\necho 8.4.27\\n' > "$PHP_PREFIX/bin/php-config"
    chmod +x "$PHP_PREFIX/bin/php" "$PHP_PREFIX/bin/php-config"
fi
''')
        mock_make.chmod(0o755)
        self.prefix = self.root / 'installed'
        self.trace = self.root / 'configure-args'
        self.env = dict(os.environ, PHP_VERSION='8.4.27', PHP_ZTS='0',
                        PHP_PREFIX=str(self.prefix),
                        PHP_SOURCE_REPO=str(self.source),
                        TRACE_CONFIG=str(self.trace),
                        PATH=str(self.bin) + os.pathsep + os.environ['PATH'])

    def test_php_git_cache_reuses_tag_without_remote(self):
        cache = self.root / 'git-cache'
        first = subprocess.run(['bash', str(SCRIPT)],
            env=dict(self.env, PHP_GIT_CACHE=str(cache)), capture_output=True, text=True)
        self.assertEqual(0, first.returncode, first.stderr)
        shutil.rmtree(self.prefix)
        self.source.rename(self.root / 'source-offline')
        second = subprocess.run(['bash', str(SCRIPT)],
            env=dict(self.env, PHP_GIT_CACHE=str(cache)), capture_output=True, text=True)
        self.assertEqual(0, second.returncode, second.stderr)
        self.assertIn('Reusing cached PHP tag', second.stdout)

    def test_ordinary_php_has_no_zts_option(self):
        p = subprocess.run(['bash', str(SCRIPT)], env=self.env, capture_output=True, text=True)
        self.assertEqual(0, p.returncode, p.stderr)
        self.assertNotIn('--enable-zts', self.trace.read_text())
        self.assertIn('--prefix=' + str(self.prefix), self.trace.read_text())

    def test_zts_php_is_explicit(self):
        p = subprocess.run(['bash', str(SCRIPT)], env=dict(self.env, PHP_ZTS='1'),
                           capture_output=True, text=True)
        self.assertEqual(0, p.returncode, p.stderr)
        self.assertIn('--enable-zts', self.trace.read_text())

    def test_bad_version_or_zts_fails_before_checkout(self):
        for overrides in (dict(PHP_VERSION='8.4.27;evil'), dict(PHP_ZTS='2')):
            with self.subTest(overrides=overrides):
                result = subprocess.run(['bash', str(SCRIPT)], env=dict(self.env, **overrides),
                                        capture_output=True, text=True)
                self.assertEqual(2, result.returncode)
                self.assertFalse(self.trace.exists())


if __name__ == '__main__':
    unittest.main()
