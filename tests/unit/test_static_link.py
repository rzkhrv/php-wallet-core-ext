"""Real ELF linking tests independent of PHP and of upstream build dependencies."""
import ctypes
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

BASE = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which('clang++') and shutil.which('clang') and shutil.which('ar') and os.uname().sysname == 'Linux',
                     'requires a Linux Clang/ar linker toolchain')
class StaticLinkTests(unittest.TestCase):
    def run_cmd(self, *args):
        return subprocess.run(args, capture_output=True, text=True, check=True)

    def test_circular_archives_and_cpp_runtime(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t)
            core_build = root / 'native'
            core_build.mkdir()
            (root / 'entry.c').write_text('extern int TWTest(void); int wallet_test_entry(void) { return TWTest(); }\n')
            (root / 'core1.cpp').write_text('extern "C" int dep(void); extern "C" int TWTest() { return dep(); }\n')
            (root / 'core2.cpp').write_text('extern "C" int final_value(void) { return 42; }\n')
            (root / 'dep.cpp').write_text('#include <string>\nextern "C" int final_value(void); extern "C" int dep(void) { return std::stoi(std::to_string(final_value())); }\n')
            self.run_cmd('clang', '-fPIC', '-c', str(root/'entry.c'), '-o', str(root/'entry.o'))
            for stem in ('core1', 'core2', 'dep'):
                self.run_cmd('clang++', '-fPIC', '-c', str(root/f'{stem}.cpp'), '-o', str(root/f'{stem}.o'))
            core = core_build / 'libTrustWalletCore.a'
            trezor = core_build / 'libTrezorCrypto.a'
            rust = root/'libwallet_core_rs.a'
            self.run_cmd('ar', 'rcs', str(core), str(root/'core1.o'), str(root/'core2.o'))
            self.run_cmd('ar', 'rcs', str(trezor), str(root/'dep.o'))
            self.run_cmd('ar', 'rcs', str(rust))
            # Docker's ASan verification stage sets WALLETCORE_SANITIZE=address
            # globally. Explicitly isolate the release fixture from that stage:
            # its ELF lifecycle must be tested independently from the ASan DSO.
            release_env = os.environ.copy()
            release_env.pop('WALLETCORE_SANITIZE', None)
            release_env.pop('WALLET_CORE_LINK_FLAGS', None)
            release_env.pop('WC_EXTRA_OBJECTS', None)
            so = root/'walletcore.so'
            result = subprocess.run(['bash', str(BASE/'tools/build/link-static.sh'), str(so), str(root/'entry.o'),
                                     str(core), str(core_build), str(rust)], env=release_env,
                                    capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            dll = ctypes.CDLL(str(so))
            self.assertEqual(42, dll.wallet_test_entry())
            dynamic = self.run_cmd('readelf', '-d', str(so)).stdout
            self.assertNotIn('libTrustWalletCore.so', dynamic)
            self.assertIn('libstdc++.so', dynamic)  # clang++ not cc
            # Production ELF modules must still be unloadable by PHP.
            self.assertNotIn('NODELETE', dynamic)
            env = release_env.copy()
            env['WALLETCORE_SANITIZE'] = 'address'
            # Reproduce older binutils warning about modern Rust DWARF forms.
            # It must not spam buildx's diagnostics when DT_FLAGS_1 is valid.
            fakebin = root / 'bin'
            fakebin.mkdir()
            actual_readelf = shutil.which('readelf')
            self.assertIsNotNone(actual_readelf)
            readelf_wrapper = fakebin / 'readelf'
            readelf_wrapper.write_text(
                '#!/bin/sh\n'
                'echo "readelf: Warning: Unrecognized form: 0x22" >&2\n'
                f'exec "{actual_readelf}" "$@"\n'
            )
            readelf_wrapper.chmod(0o755)
            env['PATH'] = str(fakebin) + os.pathsep + env.get('PATH', '')
            instrumented = root / 'walletcore-asan.so'
            probe = subprocess.run(['bash', str(BASE/'tools/build/link-static.sh'),
                                    str(instrumented), str(root/'entry.o'),
                                    str(core), str(core_build), str(rust)],
                                   env=env, capture_output=True, text=True)
            self.assertEqual(0, probe.returncode, probe.stderr)
            self.assertNotIn('Unrecognized form', probe.stderr)
            diagnostic_dynamic = self.run_cmd('readelf', '-d', str(instrumented)).stdout
            self.assertIn('NODELETE', diagnostic_dynamic)

    def test_reject_shared_input(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t)
            root.joinpath('empty.o').touch()
            root.joinpath('libTrustWalletCore.so').touch()
            root.joinpath('librust.a').touch()
            proc = subprocess.run(['bash', str(BASE/'tools/build/link-static.sh'), str(root/'out.so'),
                                   str(root/'empty.o'), str(root/'libTrustWalletCore.so'),
                                   str(root), str(root/'librust.a')], capture_output=True, text=True)
            self.assertNotEqual(proc.returncode, 0)
            self.assertIn('static .a', proc.stderr)


if __name__ == '__main__':
    unittest.main()
