"""Regression: process-lifetime Rust statics must not hide real LSan leaks.

All native builds in the project's supported workflow happen inside Docker.
This tiny test runs in the Docker verification stage with its own test DSOs.
"""
import os
import platform
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
CLANG = shutil.which('clang')


def lsan_blocked_by_ptrace(stderr):
    # QEMU-user/containers may forbid the ptrace operation LSan needs to scan
    # threads. This is an unavailable sanitizer, not a detected memory leak.
    return ('LeakSanitizer has encountered a fatal error.' in stderr
            and 'LeakSanitizer does not work under ptrace' in stderr)


class RegistryLifetimeLinkTests(unittest.TestCase):
    def test_sanitized_only_linker_option_and_release_unload_semantics(self):
        linker = (ROOT / 'tools/build/link-static.sh').read_text()
        self.assertIn('if [[ "${WALLETCORE_SANITIZE:-}" == address ]]', linker)
        self.assertIn('extra_flags+=(-Wl,-z,nodelete)', linker)
        self.assertIn('readelf -W -d "$OUTPUT"', linker)
        self.assertIn('dynamic_table=', linker)
        self.assertNotIn('LSAN_OPTIONS', linker)
        self.assertNotIn('suppressions=', linker)

    def test_lsan_ptrace_failure_is_distinct_from_actual_leaks(self):
        self.assertTrue(lsan_blocked_by_ptrace(
            'LeakSanitizer has encountered a fatal error.\n'
            'HINT: LeakSanitizer does not work under ptrace (strace, gdb, etc)'))
        self.assertFalse(lsan_blocked_by_ptrace(
            'ERROR: LeakSanitizer: detected memory leaks\n33040 byte(s) leaked'))
        self.assertFalse(lsan_blocked_by_ptrace('LeakSanitizer has encountered a fatal error.'))

    @unittest.skipUnless(CLANG and os.name == 'posix' and os.uname().sysname == 'Linux'
                         and platform.libc_ver()[0] == 'glibc',
                         'requires Linux/glibc Clang with ASan (DSO root behavior differs on musl)')
    def test_nodelete_preserves_registry_root_but_does_not_mask_real_leak(self):
        with tempfile.TemporaryDirectory(prefix='wc-asan-registry-') as d:
            path = Path(d)
            (path / 'plugin.c').write_text(r'''
#include <stdlib.h>
static void *registry_root;
void registry_initialize(void) {
    registry_root = malloc(33040);
    ((void **)registry_root)[0] = malloc(500);
}
void drop_reference_without_free(void) {
    void * volatile ptr = malloc(99);
    (void)ptr;
}
''')
            (path / 'host.c').write_text(r'''
#include <dlfcn.h>
#include <stdio.h>
int main(int argc, char **argv) {
    if (argc < 2) return 2;
    void *lib = dlopen(argv[1], RTLD_NOW | RTLD_LOCAL);
    if (!lib) { fprintf(stderr, "%s\n", dlerror()); return 2; }
    void (*init)(void) = (void (*)(void))dlsym(lib, "registry_initialize");
    if (!init) return 3;
    init();
    if (argc > 2) {
        void (*lose)(void) = (void (*)(void))dlsym(lib, "drop_reference_without_free");
        if (!lose) return 4;
        lose();
    }
    dlclose(lib);
    return 0;
}
''')
            compiler = [CLANG, '-g', '-O1', '-fno-omit-frame-pointer']

            def compile_cmd(args):
                completed = subprocess.run(compiler + args, capture_output=True, text=True)
                self.assertEqual(0, completed.returncode, completed.stderr)

            compile_cmd(['-fsanitize=address', '-no-pie', str(path/'host.c'),
                         '-ldl', '-o', str(path/'host')])
            compile_cmd(['-shared', '-fPIC', str(path/'plugin.c'),
                         '-o', str(path/'plugin.so')])
            compile_cmd(['-shared', '-fPIC', '-Wl,-z,nodelete', str(path/'plugin.c'),
                         '-o', str(path/'plugin-nodelete.so')])
            flags = subprocess.run(['readelf', '-d', str(path/'plugin-nodelete.so')],
                                   capture_output=True, text=True, check=True).stdout
            self.assertIn('NODELETE', flags)
            release_flags = subprocess.run(['readelf', '-d', str(path/'plugin.so')],
                                           capture_output=True, text=True, check=True).stdout
            self.assertNotIn('NODELETE', release_flags)

            env = os.environ.copy()
            env['ASAN_OPTIONS'] = 'detect_leaks=1:halt_on_error=1:abort_on_error=0'
            env.pop('LD_PRELOAD', None)

            def check_run(plugin, with_real_leak=False):
                args = [str(path/'host'), str(path/plugin)]
                if with_real_leak:
                    args.append('real-leak')
                return subprocess.run(args, env=env, capture_output=True, text=True)

            # An unloaded DSO loses its static roots, so LSan sees a false leak.
            unloaded = check_run('plugin.so')
            if lsan_blocked_by_ptrace(unloaded.stderr):
                self.skipTest('LSan cannot inspect processes under ptrace/emulation; '
                              'run this check on native Linux/glibc')
            self.assertNotEqual(0, unloaded.returncode)
            self.assertIn('33040 byte(s)', unloaded.stderr)
            # The same static stays reachable in a NODELETE DSO.
            retained = check_run('plugin-nodelete.so')
            self.assertEqual(0, retained.returncode, retained.stderr)
            self.assertNotIn('LeakSanitizer', retained.stderr)
            # NODELETE is not a global leak suppression: a lost allocation fails.
            actually_leaked = check_run('plugin-nodelete.so', True)
            self.assertNotEqual(0, actually_leaked.returncode)
            self.assertIn('99 byte(s)', actually_leaked.stderr)
            self.assertIn('LeakSanitizer: detected memory leaks', actually_leaked.stderr)


if __name__ == '__main__':
    unittest.main()
