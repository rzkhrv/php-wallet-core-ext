#!/usr/bin/env python3
"""Symbolize unloaded native frames from ASan/LSan with saved /proc/self/maps.

Called *inside Docker*, while the build's unstripped walletcore.so exists.
Does not suppress sanitizer errors, alter their return code or write artifacts
back to the host. The caller's plain buildx log is captured on the host.
"""
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys

UNKNOWN_PC = re.compile(r'#\d+\s+0x([0-9a-fA-F]+)\s+\(<unknown module>\)')
MAX_PCS = 64


def executable_maps(output):
    mappings = []
    for line in output.splitlines():
        if not line.startswith('WC_ASAN_MAP '):
            continue
        fields = line[len('WC_ASAN_MAP '):].split(None, 5)
        if len(fields) != 6 or 'x' not in fields[1]:
            continue
        try:
            start, end = (int(v, 16) for v in fields[0].split('-', 1))
            offset = int(fields[2], 16)
        except ValueError:
            continue
        binary = fields[5].removesuffix(' (deleted)')
        if start < end and binary.startswith('/'):
            mappings.append((start, end, offset, binary))
    return mappings


def candidate_pcs(output, mappings):
    seen = set()
    found = []
    for match in UNKNOWN_PC.finditer(output):
        pc = int(match.group(1), 16)
        if pc in seen:
            continue
        seen.add(pc)
        for start, end, offset, binary in mappings:
            if start <= pc < end:
                found.append((pc, offset + pc - start, binary))
                break
    return found


def main():
    if len(sys.argv) != 2:
        print('usage: symbolize-asan-trace.py CASE_LOG', file=sys.stderr)
        return 2
    output = Path(sys.argv[1]).read_text(errors='replace')
    mappings = executable_maps(output)
    found = candidate_pcs(output, mappings)
    all_unknown = len(set(int(m.group(1), 16) for m in UNKNOWN_PC.finditer(output)))
    print(f'WC_ASAN_SYMBOLIZER unknown_pcs={all_unknown} mapped_pcs={len(found)} '
          f'executable_mappings={len(mappings)}', flush=True)
    if not found:
        print('WC_ASAN_SYMBOLIZER no address-to-binary mapping available; '
              'raw ASan report is preserved above.', flush=True)
        return 0
    symbolizer = shutil.which('llvm-symbolizer')
    if not symbolizer:
        try:
            compiler_version = subprocess.check_output(
                [os.environ.get('CLANG', 'clang'), '-dumpversion'],
                text=True, stderr=subprocess.DEVNULL).strip()
            major = compiler_version.split('.', 1)[0]
            if major.isdigit():
                symbolizer = shutil.which('llvm-symbolizer-' + major)
        except (OSError, subprocess.CalledProcessError):
            pass
    if not symbolizer:
        print('WC_ASAN_SYMBOLIZER missing llvm-symbolizer; original addresses retained')
        return 0
    for pc, relative, binary in found[:MAX_PCS]:
        print(f'WC_ASAN_FRAME pc=0x{pc:x} binary={binary} file_offset=0x{relative:x}')
        if not os.path.isfile(binary):
            print('  binary no longer present; cannot resolve symbol')
            continue
        try:
            got = subprocess.run(
                [symbolizer, f'--obj={binary}', '--inlining', '--demangle',
                 f'0x{relative:x}'], capture_output=True, text=True, timeout=8)
        except (OSError, subprocess.TimeoutExpired) as exc:
            print(f'  symbolizer failed: {exc}')
            continue
        detail = (got.stdout or got.stderr).strip()
        if got.returncode or not detail:
            print(f'  symbolizer exit={got.returncode}: {detail[:300]}')
        else:
            print('  ' + '\n  '.join(detail.splitlines()[:12]))
    if len(found) > MAX_PCS:
        print(f'WC_ASAN_SYMBOLIZER truncated to {MAX_PCS} unique frames')
    return 0


if __name__ == '__main__':
    sys.exit(main())
