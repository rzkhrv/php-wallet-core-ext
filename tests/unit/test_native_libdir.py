"""Native bootstrap must handle both lib and lib64 upstream install layouts."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class NativeLibraryDirectoryTests(unittest.TestCase):
    def test_installer_layouts(self):
        for layout in ('lib64', 'lib', 'neither'):
            with self.subTest(layout=layout), tempfile.TemporaryDirectory() as tmp:
                base = Path(tmp)
                upstream = base / 'wallet-core'
                (upstream / 'include/TrustWalletCore').mkdir(parents=True)
                (upstream / 'tools').mkdir()
                (base / 'bin').mkdir()

                def script(path, body):
                    path.write_text('#!/usr/bin/env bash\nset -euo pipefail\n' + body)
                    path.chmod(0o755)

                script(upstream / 'tools/install-dependencies',
                       'mkdir -p build/local/bin\n'
                       'touch build/local/bin/protoc build/local/bin/protoc-gen-c-typedef\n'
                       'chmod +x build/local/bin/protoc build/local/bin/protoc-gen-c-typedef\n'
                       + (f'mkdir -p build/local/{layout}\n' if layout != 'neither' else ''))
                # Upstream rust-bindgen uses an unconditional cp to lib/.
                script(upstream / 'tools/generate-files',
                       'mkdir -p rust/target/release\n'
                       'echo rust > rust/target/release/libwallet_core_rs.a\n'
                       'cp rust/target/release/libwallet_core_rs.a build/local/lib/\n')
                script(base / 'bin/cmake',
                       'if [[ "${1:-}" == --build ]]; then\n'
                       '  echo core > "$2/libTrustWalletCore.a"\n'
                       'fi\n')
                env = dict(os.environ, PATH=str(base / 'bin') + ':' + os.environ['PATH'],
                           WALLET_CORE_DIR=str(upstream), BUILD_DIR=str(base / 'output'),
                           WALLET_CORE_AUTO_BUILD='1', WALLET_CORE_INSTALL_DEPS='1',
                           CC='/bin/true', CXX='/bin/true', CLANG='/bin/true')
                run = subprocess.run(['bash', str(ROOT / 'tools/build/build.sh'), '--native-only'],
                                     env=env, capture_output=True, text=True)
                self.assertEqual(0, run.returncode, run.stderr)
                lib = upstream / 'build/local/lib'
                self.assertTrue(lib.is_dir())
                self.assertEqual(lib.is_symlink(), layout == 'lib64')
                if layout == 'lib64':
                    self.assertEqual(lib.readlink(), Path('lib64'))
                self.assertTrue((lib / 'libwallet_core_rs.a').is_file())
                self.assertTrue((upstream / 'build/libTrustWalletCore.a').is_file())


if __name__ == '__main__':
    unittest.main()
