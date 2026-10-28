import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

BASE = Path(__file__).resolve().parents[2]
FIX = BASE / 'tests/fixtures/include/TrustWalletCore'

class GeneratorTests(unittest.TestCase):
    def test_clang_evaluated_enum_values_match_c(self):
        # Explicit enum expressions can be wrapped in ImplicitCastExpr rather
        # than being direct children of EnumConstantDecl in the Clang JSON AST.
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            headers = root / 'TrustWalletCore'
            headers.mkdir()
            (headers / 'TWProbe.h').write_text('''#include "TWBase.h"
enum TWProbe {
    TWProbeBig = (1U << 4),
    TWProbeNext,
    TWProbeCast = (int)(2 + 3),
    TWProbeMask = (1 << 3) | 2,
    TWProbeNegative = -3,
    TWProbeFollowing,
};
TW_EXPORT_STATIC_METHOD int TWProbeRead(void);
''')
            out = root / 'out'
            result = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                                     '--headers', str(headers), '--include', str(FIX),
                                     '--output', str(out)], capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual([], json.loads((out / 'abi.json').read_text())['header_errors'])
            stub = (out / 'walletcore.stub.php').read_text()
            expected = {'Big': 16, 'Next': 17, 'Cast': 5,
                        'Mask': 10, 'Negative': -3, 'Following': -2}
            for label, value in expected.items():
                self.assertIn(f'public const {label} = {value};', stub)
            php = subprocess.run(['php', '-n', '-l', str(out / 'walletcore.stub.php')],
                                 capture_output=True, text=True)
            self.assertEqual(0, php.returncode, php.stderr)
            program = root / 'enum.c'
            program.write_text('#include <stdio.h>\n#include "TWProbe.h"\n'
                               'int main(void) { printf("%d %d %d %d %d %d\\n", '
                               'TWProbeBig, TWProbeNext, TWProbeCast, '
                               'TWProbeMask, TWProbeNegative, TWProbeFollowing); }\n')
            binary = root / 'enum'
            compile_result = subprocess.run(['clang', '-std=gnu11', '-I' + str(headers),
                                             '-I' + str(FIX), str(program), '-o', str(binary)],
                                            capture_output=True, text=True)
            self.assertEqual(0, compile_result.returncode, compile_result.stderr)
            actual = subprocess.check_output([str(binary)], text=True).strip()
            self.assertEqual(' '.join(map(str, expected.values())), actual)

    def test_php_reserved_names_fail_closed(self):
        for filename, declaration, diagnostic in (
            ('TWProbe.h', 'enum TWProbe { TWProbeClass = 1 };', 'PHP constant'),
            ('TWClass.h', 'TW_EXPORT_STATIC_METHOD int TWClassRead(void);', 'PHP class'),
            ('TW1Probe.h', 'TW_EXPORT_STATIC_METHOD int TW1ProbeRead(void);', 'PHP class'),
            ('TWProbe.h', 'TW_EXPORT_STATIC_METHOD int TWProbeRead(int this);', 'PHP parameter'),
        ):
            with self.subTest(declaration=declaration), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                headers = root / 'TrustWalletCore'
                headers.mkdir()
                (headers / filename).write_text('#include "TWBase.h"\n' + declaration + '\n'
                                                + ('TW_EXPORT_STATIC_METHOD int TWProbeRead(void);\n'
                                                   if 'enum TWProbe' in declaration else ''))
                out = root / 'out'
                out.mkdir()
                (out / 'walletcore.c').write_text('stale')
                (out / 'walletcore.stub.php').write_text('stale')
                result = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                                         '--headers', str(headers), '--include', str(FIX),
                                         '--output', str(out)], capture_output=True, text=True)
                self.assertEqual(2, result.returncode, result.stderr)
                errors = json.loads((out / 'abi.json').read_text())['header_errors']
                self.assertTrue(any(diagnostic in entry['error'] for entry in errors), errors)
                self.assertFalse((out / 'walletcore.c').exists())
                self.assertFalse((out / 'walletcore.stub.php').exists())

    def test_enum_beyond_zend_long_range_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            headers = root / 'TrustWalletCore'
            headers.mkdir()
            (headers / 'TWProbe.h').write_text('''#include "TWBase.h"
enum TWProbe { TWProbeOverflow = 0xffffffffffffffffULL };
TW_EXPORT_STATIC_METHOD int TWProbeRead(void);
''')
            out = root / 'out'
            result = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                                     '--headers', str(headers), '--include', str(FIX),
                                     '--output', str(out)], capture_output=True, text=True)
            self.assertEqual(2, result.returncode, result.stderr)
            errors = json.loads((out / 'abi.json').read_text())['header_errors']
            self.assertTrue(any('outside PHP zend_long range' in entry['error'] for entry in errors), errors)

    def test_php_allows_enum_as_class_name(self):
        # `enum` is a PHP keyword, but `class Enum` is legal. Avoid a broader
        # PHP keyword ban than the actual class-identifier restriction.
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            headers = root / 'TrustWalletCore'
            headers.mkdir()
            (headers / 'TWEnum.h').write_text('#include "TWBase.h"\n'
                                              'TW_EXPORT_STATIC_METHOD int TWEnumRead(void);\n')
            out = root / 'out'
            result = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                                     '--headers', str(headers), '--include', str(FIX),
                                     '--output', str(out)], capture_output=True, text=True)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn('class Enum {', (out / 'walletcore.stub.php').read_text())
            php = subprocess.run(['php', '-n', '-l', str(out / 'walletcore.stub.php')],
                                 capture_output=True, text=True)
            self.assertEqual(0, php.returncode, php.stderr)

    def test_generated_bindings(self):
        with tempfile.TemporaryDirectory() as t:
            result = subprocess.run([sys.executable, str(BASE/'tools/codegen/generate.py'),
                '--headers', str(FIX), '--output', t], capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            data = json.loads((Path(t)/'abi.json').read_text())
            self.assertEqual([], data['header_errors'])
            for name in ('TWAnySignerSign', 'TWPrivateKeyCreateWithData', 'TWPrivateKeyData', 'TWPrivateKeyIsValid', 'TWDataVectorGet', 'TWCoinTypeIsEnabled',
                         'TWDerivationPathIndexAt', 'TWStoredKeyEncryptionParameters'):
                self.assertEqual('generated', data['functions'][name]['status'])
            self.assertEqual('excluded', data['functions']['TWPrivateKeyDelete']['status'])
            # ABI metadata and the emitted PHP API use canonical lowerCamelCase.
            for native, entry in data['functions'].items():
                if entry['status'] == 'generated':
                    self.assertRegex(entry['method'], r'^[a-z]', native)
            self.assertEqual('createWithData', data['functions']['TWPrivateKeyCreateWithData']['method'])
            self.assertEqual('sign', data['functions']['TWAnySignerSign']['method'])
            self.assertEqual('indexAt', data['functions']['TWDerivationPathIndexAt']['method'])
            c = (Path(t)/'walletcore.c').read_text()
            self.assertIn('PHP_METHOD(WC_AnySigner, sign)', c)
            # A zero-parameter unsigned return may throw on PHP_INT_MAX
            # overflow; its generated goto must have a cleanup label.
            zero_unsigned = c.split('PHP_METHOD(WC_Counter, count) {', 1)[1].split('\n}', 1)[0]
            self.assertIn('goto cleanup;', zero_unsigned)
            self.assertIn('cleanup: ;', zero_unsigned)
            self.assertIn('TWAnySignerSign(tmp0, (enum TWCoinType)', c)
            self.assertIn('PHP_METHOD(WC_PrivateKey, data)', c)
            self.assertIn('wc_delete_PrivateKey(void *p)', c)
            self.assertIn('PHP_METHOD(WC_DataVector, get)', c)
            self.assertIn('PHP_ME(WC_CoinType, isEnabled, arginfo_WC_CoinType_isEnabled, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)', c)
            self.assertIn('wc_handlers.free_obj = wc_free;', c)
            self.assertIn('#ifdef WC_ASAN_PROTOBUF_CLEANUP', c)
            self.assertIn('PHP_MSHUTDOWN_FUNCTION(walletcore)', c)
            self.assertIn('wc_asan_protobuf_cleanup();', c)
            self.assertIn('#define WC_MODULE_SHUTDOWN NULL', c)
            self.assertIn('PHP_METHOD(WC_DerivationPath, indexAt)', c)
            self.assertIn('TWDerivationPathIndexAt((struct TWDerivationPath *)self, (uint32_t)', c)
            self.assertIn('PHP_METHOD(WC_StoredKey, encryptionParameters)', c)
            # Nullable native object input must accept actual PHP null, but
            # reject an uninitialized Zend object before the C ABI call.
            self.assertEqual('generated', data['functions']['TWStoredKeyAccountForCoin']['status'])
            self.assertEqual('generated', data['functions']['TWStoredKeyAccountForCoinDerivation']['status'])
            section = c.split('PHP_METHOD(WC_StoredKey, accountForCoin) {', 1)[1].split('\n}', 1)[0]
            self.assertIn('Z_TYPE_P(arg[1]) != IS_NULL', section)
            self.assertIn('Z_TYPE_P(arg[1]) == IS_OBJECT && !wc_obj(Z_OBJ_P(arg[1]))->ptr', section)
            self.assertIn('Native argument object is not initialized', section)
            self.assertIn('Z_TYPE_P(arg[1]) == IS_NULL ? NULL', section)
            section2 = c.split('PHP_METHOD(WC_StoredKey, accountForCoinDerivation) {', 1)[1].split('\n}', 1)[0]
            self.assertIn('Z_TYPE_P(arg[2]) == IS_OBJECT && !wc_obj(Z_OBJ_P(arg[2]))->ptr', section2)
            self.assertIn('TWStoredKeyEncryptionParameters((struct TWStoredKey *)self)', c)
            self.assertTrue(data['functions']['TWDerivationPathIndexAt']['instance'])
            self.assertEqual('DerivationPath', data['functions']['TWDerivationPathIndexAt']['owner'])
            self.assertEqual('StoredKey', data['functions']['TWStoredKeyEncryptionParameters']['owner'])
            self.assertTrue(data['functions']['TWStoredKeyEncryptionParameters']['instance'])
            stub = (Path(t)/'walletcore.stub.php').read_text()
            self.assertIn('public const Ethereum = 60;', stub)
            self.assertIn('public static function sign(string $input, int $coin): ?string', stub)
            self.assertIn('function indexAt(int $index): ?DerivationPathIndex', stub)
            self.assertIn('function encryptionParameters(): ?string', stub)
            subprocess.run(['php', '-l', str(Path(t)/'walletcore.stub.php')], check=True, capture_output=True)
            clang = subprocess.run(['clang', '-x', 'c', '-std=gnu11', '-fsyntax-only',
                '-Wno-everything', '-DCOMPILE_DL_WALLETCORE=1',
                '-I'+str(BASE/'tests/fixtures/syntax'), '-I'+str(FIX.parent),
                str(Path(t)/'walletcore.c')], capture_output=True, text=True)
            self.assertEqual(0, clang.returncode, clang.stderr)
            # The diagnostic lifecycle must also compile independently of
            # the release configuration, on the same generator fixture.
            clang_asan = subprocess.run(['clang', '-x', 'c', '-std=gnu11',
                '-fsyntax-only', '-Wno-everything', '-DCOMPILE_DL_WALLETCORE=1',
                '-DWC_ASAN_PROTOBUF_CLEANUP=1', '-I'+str(BASE/'tests/fixtures/syntax'),
                '-I'+str(FIX.parent), str(Path(t)/'walletcore.c')],
                capture_output=True, text=True)
            self.assertEqual(0, clang_asan.returncode, clang_asan.stderr)

    def test_export_markers_use_exact_identifier_offsets(self):
        # Newer Clang releases may report FunctionDecl.range.begin at the
        # return type rather than the macro. Always use the source spelling.
        from tools.codegen.generate import source_export_markers

        source = (b'#define TW_EXPORT_METHOD extern\n'
                  b'TW_EXPORT_STATIC_METHOD\n'
                  b'int TWProbeFirst(void);\n'
                  b'/* TW_EXPORT_METHOD; */\n'
                  b'TW_EXPORT_METHOD\n'
                  b'int TWProbeSecond(void);\n'
                  b'/* TW_EXPORT_STATIC_METHOD; */\n'
                  b'int TWProbePlain(void);\n')
        for name, expected in (('TWProbeFirst', 'STATIC_METHOD'),
                               ('TWProbeSecond', 'METHOD'),
                               ('TWProbePlain', 'FREE')):
            offset = source.index(name.encode('ascii'))
            self.assertEqual(expected, source_export_markers(source).get(offset, 'FREE'), name)

    def test_source_export_markers_are_bound_to_exact_declarations(self):
        from tools.codegen.generate import source_export_markers

        source = (b'#define TW_EXPORT_METHOD extern\n'
                  b'/* TW_EXPORT_STATIC_METHOD int TWCommented(void); */\n'
                  b'TW_EXPORT_STATIC_METHOD\n'
                  b'int TWProbeFirst(void);\n'
                  b'// TW_EXPORT_STATIC_METHOD int TWIgnored(void);\n'
                  b'TW_EXPORT_METHOD int TWProbeSecond(int x);\n'
                  b'int TWProbePlain(void);\n'
                  b'TW_EXPORT_FUNC\n'
                  b'const char * TWProbeLast(void);\n')
        markers = source_export_markers(source)
        self.assertEqual({
            source.index(b'TWProbeFirst'): 'STATIC_METHOD',
            source.index(b'TWProbeSecond'): 'METHOD',
            source.index(b'TWProbeLast'): 'FUNC',
        }, markers)
        self.assertNotIn(source.index(b'TWProbePlain'), markers)

    def test_conflicting_upstream_declarations_fail_closed(self):
        # Every header is parsed in its own translation unit. A duplicate C
        # symbol must not silently take the signature of the last header.
        with tempfile.TemporaryDirectory() as temp:
            headers = Path(temp) / 'TrustWalletCore'
            headers.mkdir()
            a = headers / 'TWProbe.h'
            b = headers / 'TWProbeExtra.h'
            a.write_text('''''''''#include "TWBase.h"
TW_EXPORT_STATIC_METHOD
int TWProbeRead(uint32_t value);
''''''''' .strip())
            b.write_text('''''''''#include "TWBase.h"
TW_EXPORT_STATIC_METHOD
int TWProbeRead(uint64_t value);
''''''''' .strip())

            def run():
                return subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                    '--headers', str(headers), '--include', str(FIX),
                    '--output', str(Path(temp) / 'out')], capture_output=True, text=True)

            failed = run()
            self.assertNotEqual(0, failed.returncode)
            out = Path(temp) / 'out'
            report = json.loads((out / 'abi.json').read_text())
            self.assertTrue(any('conflicting C declarations for TWProbeRead' in x['error']
                                and 'TWProbe.h' in x['error'] and 'TWProbeExtra.h' in x['error']
                                for x in report['header_errors']))
            self.assertFalse((out / 'walletcore.c').exists())
            self.assertFalse((out / 'walletcore.stub.php').exists())

            # A repeated, ABI-equivalent prototype (even with a different
            # argument identifier) is valid and must not shrink coverage.
            b.write_text('''''''''#include "TWBase.h"
TW_EXPORT_STATIC_METHOD
int TWProbeRead(uint32_t another_name);
''''''''' .strip())
            success = run()
            self.assertEqual(0, success.returncode, success.stderr)
            report = json.loads((out / 'abi.json').read_text())
            self.assertEqual([], report['header_errors'])
            self.assertEqual(1, report['parsed_functions'])
            self.assertEqual('generated', report['functions']['TWProbeRead']['status'])
            self.assertIn('PHP_METHOD(WC_Probe, read)', (out / 'walletcore.c').read_text())
            syntax = subprocess.run(['clang', '-x', 'c', '-std=gnu11',
                '-fsyntax-only', '-Wno-everything',
                '-I' + str(BASE / 'tests/fixtures/syntax'), '-I' + str(Path(temp)),
                '-I' + str(FIX), str(out / 'walletcore.c')],
                capture_output=True, text=True)
            self.assertEqual(0, syntax.returncode, syntax.stderr)

            # A marker changes PHP instance-vs-static semantics despite
            # identical C argument types. Detect that mismatch too.
            b.write_text('''''''''#include "TWBase.h"
TW_EXPORT_METHOD
int TWProbeRead(uint32_t another_name);
''''''''' .strip())
            self.assertNotEqual(0, run().returncode)
            report = json.loads((out / 'abi.json').read_text())
            self.assertTrue(any('conflicting C declarations for TWProbeRead' in x['error']
                                and 'STATIC_METHOD' in x['error'] and 'METHOD' in x['error']
                                for x in report['header_errors']))
            self.assertFalse((out / 'walletcore.c').exists())

    def test_conflicting_export_markers_within_one_header_fail_closed(self):
        with tempfile.TemporaryDirectory() as temp:
            headers = Path(temp) / 'TrustWalletCore'
            headers.mkdir()
            (headers / 'TWProbe.h').write_text('''#include "TWBase.h"
TW_EXPORT_STATIC_METHOD int TWProbeRead(uint32_t value);
TW_EXPORT_METHOD int TWProbeRead(uint32_t another_name);
''')
            out = Path(temp) / 'out'
            result = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(headers), '--include', str(FIX), '--output', str(out)],
                capture_output=True, text=True)
            self.assertEqual(2, result.returncode, result.stderr)
            report = json.loads((out / 'abi.json').read_text())
            self.assertTrue(any('conflicting C declarations for TWProbeRead' in x['error']
                                and 'STATIC_METHOD' in x['error'] and 'METHOD' in x['error']
                                for x in report['header_errors']), report['header_errors'])
            self.assertFalse((out / 'walletcore.c').exists())

    def test_marker_conflict_survives_ambiguous_clang_offsets(self):
        from unittest.mock import patch
        from tools.codegen.generate import parse_headers

        with tempfile.TemporaryDirectory() as temp:
            headers = Path(temp) / 'TrustWalletCore'
            headers.mkdir()
            for suffix, marker in (('', 'STATIC_METHOD'), ('Extra', 'METHOD')):
                (headers / f'TWProbe{suffix}.h').write_text(
                    f'// TWProbeRead appears earlier in the file\n'
                    f'TW_EXPORT_{marker} int TWProbeRead(void);\n')

            def ambiguous_ast(command, **_kwargs):
                source = Path(command[-1]).read_bytes()
                # Simulate a Clang location that points to an earlier same-name
                # token: old location-only classification sees FREE in both.
                node = {'kind': 'FunctionDecl', 'name': 'TWProbeRead',
                        'loc': {'offset': source.index(b'TWProbeRead')},
                        'type': {'qualType': 'int (void)'}, 'inner': []}
                return subprocess.CompletedProcess(command, 0, json.dumps({'inner': [node]}), '')

            with patch('tools.codegen.generate.subprocess.run', side_effect=ambiguous_ast):
                functions, _enums, errors = parse_headers(headers, 'clang', [headers])
            self.assertIn('TWProbeRead', functions)
            self.assertTrue(any('conflicting C declarations for TWProbeRead' in x['error']
                                and 'STATIC_METHOD' in x['error'] and 'METHOD' in x['error']
                                for x in errors), errors)

    def test_conflicting_upstream_enum_declarations_fail_closed(self):
        with tempfile.TemporaryDirectory() as temp:
            headers = Path(temp) / 'TrustWalletCore'
            headers.mkdir()
            (headers / 'TWProbe.h').write_text('''''''''#include "TWBase.h"
enum TWProbeChoice { TWProbeChoiceA = 1 };
TW_EXPORT_STATIC_METHOD
int TWProbeRead(void);
''''''''' .strip())
            (headers / 'TWProbeExtension.h').write_text('''''''''#include "TWBase.h"
enum TWProbeChoice { TWProbeChoiceA = 2 };
''''''''' .strip())
            out = Path(temp) / 'out'
            result = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(headers), '--include', str(FIX), '--output', str(out)],
                capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)
            report = json.loads((out / 'abi.json').read_text())
            self.assertTrue(any('conflicting enum declarations for TWProbeChoice' in x['error']
                                for x in report['header_errors']))
            self.assertFalse((out / 'walletcore.c').exists())

    def test_reference_arguments_are_dereferenced_before_validation(self):
        # Z_PARAM_ZVAL does not dereference ordinary PHP aliases. The
        # generated wrapper must unwrap them before checking C argument types.
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            generated = subprocess.run([
                sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(FIX), '--output', str(output)
            ], capture_output=True, text=True)
            self.assertEqual(0, generated.returncode, generated.stderr)
            code = (output / 'walletcore.c').read_text()
            # Data/string pointers, native objects, integers, and enums all
            # use the same Zend-level reference normalization.
            cases = (
                ('PrivateKey', 'createWithData', ['tmp0']),
                ('AnySigner', 'sign', ['tmp0', 'Z_TYPE_P(arg[1])']),
                ('DerivationPath', 'indexAt', ['Z_TYPE_P(arg[0])']),
                ('StoredKey', 'accountForCoin', ['Z_TYPE_P(arg[0])', 'Z_TYPE_P(arg[1])']),
            )
            for owner, method, conditions in cases:
                with self.subTest(method=method):
                    marker = f'PHP_METHOD(WC_{owner}, {method}) {{'
                    section = code.split(marker, 1)[1].split('\n}', 1)[0]
                    # If there are multiple PHP arguments, each is normalized.
                    count = section.count('Z_PARAM_ZVAL(')
                    self.assertGreater(count, 0)
                    for i in range(count):
                        self.assertIn(f'ZVAL_DEREF(arg[{i}]);', section)
                    first_check = min(section.index(condition) for condition in conditions)
                    self.assertLess(section.index('ZVAL_DEREF(arg[0]);'), first_check)

    def test_delete_name_is_not_sufficient_for_object_ownership(self):
        # No coin or version allowlists: a Delete-named symbol is a native
        # destructor only with void (struct TWSameType *) signature. A fake
        # destructor must not turn an opaque result into an owned Zend object.
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'TrustWalletCore'
            root.mkdir()
            declarations = {
                'BadResult': 'int TWBadResultDelete(struct TWBadResult * p);',
                'BadParam': 'void TWBadParamDelete(struct TWUnrelated * p);',
                'BadArity': 'void TWBadArityDelete(struct TWBadArity * p, int reason);',
                'GoodResult': 'void TWGoodResultDelete(const struct TWGoodResult * p);',
            }
            for owner, destructor in declarations.items():
                (root / f'TW{owner}.h').write_text(f"""#pragma once
#include "TWBase.h"
struct TW{owner};
struct TWUnrelated;
TW_EXPORT_STATIC_METHOD
struct TW{owner} * TW{owner}Create(void);
TW_EXPORT_METHOD
{destructor}
""")
            out = Path(temp) / 'generated'
            process = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(root), '--include', str(FIX), '--output', str(out)],
                capture_output=True, text=True)
            self.assertEqual(0, process.returncode, process.stderr)
            report = json.loads((out / 'abi.json').read_text())
            code = (out / 'walletcore.c').read_text()
            for bad in ('BadResult', 'BadParam', 'BadArity'):
                self.assertEqual('excluded', report['functions'][f'TW{bad}Create']['status'])
                self.assertIn('destructor', report['functions'][f'TW{bad}Create']['reason'])
                self.assertNotIn(f'wc_delete_{bad}(', code)
                self.assertNotIn(f'wc_ce_{bad}->create_object = wc_create;', code)
            self.assertEqual('generated', report['functions']['TWGoodResultCreate']['status'])
            self.assertIn('static void wc_delete_GoodResult(void *p)', code)
            self.assertIn('wc_ce_GoodResult->create_object = wc_create;', code)
            clang = subprocess.run(['clang', '-x', 'c', '-std=gnu11', '-fsyntax-only',
                '-Wno-everything', '-I'+str(BASE / 'tests/fixtures/syntax'),
                '-I'+str(Path(temp)), '-I'+str(FIX), str(out / 'walletcore.c')],
                capture_output=True, text=True)
            self.assertEqual(0, clang.returncode, clang.stderr)

    def test_signed_and_enum_returns_are_checked_before_narrowing(self):
        # 64-bit C return values must also be safe when PHP's zend_long is
        # 32-bit. The test uses synthetic ABI declarations, not coin rules.
        with tempfile.TemporaryDirectory() as t:
            root = Path(t) / 'TrustWalletCore'
            root.mkdir()
            (root / 'TWAuditNumber.h').write_text("""#pragma once
#include "TWBase.h"
enum TWAuditNumberKind { TWAuditNumberKindSmall = -1, TWAuditNumberKindLarge = 4294967295U };
TW_EXPORT_STATIC_METHOD
int64_t TWAuditNumberSignedNoArgs(void);
TW_EXPORT_STATIC_METHOD
enum TWAuditNumberKind TWAuditNumberEnumNoArgs(void);
""")
            output = Path(t) / 'out'
            proc = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(root), '--include', str(FIX), '--output', str(output)],
                capture_output=True, text=True)
            self.assertEqual(0, proc.returncode, proc.stderr)
            abi = json.loads((output / 'abi.json').read_text())
            c = (output / 'walletcore.c').read_text()
            for native, method in (
                    ('TWAuditNumberSignedNoArgs', 'signedNoArgs'),
                    ('TWAuditNumberEnumNoArgs', 'enumNoArgs')):
                self.assertEqual('generated', abi['functions'][native]['status'])
                section = c.split(f'PHP_METHOD(WC_AuditNumber, {method}) {{', 1)[1].split('\n}', 1)[0]
                self.assertIn('(int64_t)result < (int64_t)ZEND_LONG_MIN', section)
                self.assertIn('(uint64_t)result > (uint64_t)ZEND_LONG_MAX', section)
                self.assertIn('result < 0', section)
                self.assertIn('result >= 0', section)
                self.assertIn('goto cleanup;', section)
                self.assertIn('cleanup: ;', section)
                self.assertLess(section.index('Native integer exceeds PHP integer range'),
                                section.index('RETVAL_LONG((zend_long)result)'))
            clang = subprocess.run(['clang', '-x', 'c', '-std=gnu11', '-fsyntax-only',
                '-Wno-everything', '-I'+str(BASE/'tests/fixtures/syntax'),
                '-I'+str(Path(t)), '-I'+str(FIX), str(output/'walletcore.c')],
                capture_output=True, text=True)
            self.assertEqual(0, clang.returncode, clang.stderr)
            # Execute the *emitted* guard under a simulated 32-bit zend_long,
            # including very large unsigned enum-like values. This catches
            # semantic overflow mistakes that syntax checks cannot detect.
            section = c.split('PHP_METHOD(WC_AuditNumber, enumNoArgs) {', 1)[1].split('\n}', 1)[0]
            line = next(line.strip() for line in section.splitlines()
                        if 'Native integer exceeds PHP integer range' in line)
            condition = line.split(' { zend_throw_exception', 1)[0][len('if ('):-1]
            check = Path(t) / 'check-bounds.c'
            check.write_text(f'''#include <stdint.h>
#include <limits.h>
#define ZEND_LONG_MIN INT32_MIN
#define ZEND_LONG_MAX INT32_MAX
static int signed_outside(int64_t result) {{ return {condition}; }}
static int unsigned_outside(uint64_t result) {{ return {condition}; }}
int main(void) {{
    return !(signed_outside((int64_t)INT32_MIN-1) &&
             !signed_outside(INT32_MIN) && !signed_outside(-1) &&
             !signed_outside(INT32_MAX) && signed_outside((int64_t)INT32_MAX+1) &&
             !unsigned_outside(INT32_MAX) &&
             unsigned_outside((uint64_t)INT32_MAX+1) &&
             unsigned_outside(UINT64_MAX));
}}
''')
            executable = Path(t) / 'check-bounds'
            compile_guard = subprocess.run(['clang', '-std=c11', '-Wall', '-Wextra',
                str(check), '-o', str(executable)], capture_output=True, text=True)
            self.assertEqual(0, compile_guard.returncode, compile_guard.stderr)
            self.assertEqual(0, subprocess.run([str(executable)]).returncode)

    def test_new_classes_sharing_data_string_prefixes_are_not_discarded(self):
        # The core TWData/TWString adapters must not swallow future, distinct
        # TWData* / TWString* classes that have ordinary native destructors.
        with tempfile.TemporaryDirectory() as t:
            root = Path(t) / 'TrustWalletCore'
            root.mkdir()
            for owner in ('TWDataPacket', 'TWStringBuffer'):
                (root / (owner + '.h')).write_text(f"""#pragma once
#include "TWBase.h"
#ifndef TW_EXPORT_CLASS
#define TW_EXPORT_CLASS
#endif
TW_EXPORT_CLASS
struct {owner};
TW_EXPORT_STATIC_METHOD
struct {owner} * _Nullable {owner}Create(void);
TW_EXPORT_METHOD
void {owner}Delete(struct {owner} * _Nonnull object);
TW_EXPORT_PROPERTY
size_t {owner}Size(const struct {owner} * _Nonnull object);
""")
            output = Path(t) / 'out'
            proc = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(root), '--include', str(FIX), '--output', str(output)],
                capture_output=True, text=True)
            self.assertEqual(0, proc.returncode, proc.stderr)
            abi = json.loads((output / 'abi.json').read_text())
            c = (output / 'walletcore.c').read_text()
            for owner in ('TWDataPacket', 'TWStringBuffer'):
                self.assertEqual('generated', abi['functions'][owner+'Create']['status'])
                self.assertEqual('generated', abi['functions'][owner+'Size']['status'])
                self.assertEqual('excluded', abi['functions'][owner+'Delete']['status'])
                self.assertIn('PHP_METHOD(WC_'+owner[2:]+', size)', c)
            clang = subprocess.run(['clang', '-x', 'c', '-std=gnu11', '-fsyntax-only',
                '-Wno-everything', '-I'+str(BASE/'tests/fixtures/syntax'),
                '-I'+str(Path(t)), '-I'+str(FIX), str(output/'walletcore.c')],
                capture_output=True, text=True)
            self.assertEqual(0, clang.returncode, clang.stderr)

    def test_byte_pointer_length_marshalling_is_generic_and_bidirectional(self):
        # Synthetic class, not a coin/version allowlist. Every adjacent
        # uint8_t* + size_t pair collapses to one binary PHP parameter.
        # Mutable C pointers use pass-by-reference, preserving native writes.
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'TrustWalletCore'
            root.mkdir()
            (root / 'TWBufferProbe.h').write_text("""#pragma once
#include "TWBase.h"
struct TWBufferProbe;
TW_EXPORT_METHOD
void TWBufferProbeDelete(struct TWBufferProbe *_Nonnull object);
TW_EXPORT_STATIC_METHOD
struct TWBufferProbe *_Nullable TWBufferProbeCreateWritable(uint8_t *_Nonnull bytes, size_t size);
TW_EXPORT_STATIC_METHOD
struct TWBufferProbe *_Nullable TWBufferProbeCreateReadOnly(const uint8_t *_Nonnull bytes, size_t size);
TW_EXPORT_STATIC_METHOD
struct TWBufferProbe *_Nullable TWBufferProbeCreateNullable(uint8_t *_Nullable bytes, size_t size);
TW_EXPORT_STATIC_METHOD
void TWBufferProbeMutate(uint8_t *_Nonnull bytes, size_t size);
TW_EXPORT_STATIC_METHOD
void TWBufferProbeRead(const uint8_t *_Nonnull bytes, size_t size);
TW_EXPORT_STATIC_METHOD
void TWBufferProbeTransform(uint8_t *_Nonnull output, size_t outSize, const uint8_t *_Nonnull input, size_t inSize);
TW_EXPORT_STATIC_METHOD
void TWBufferProbePointerOnly(uint8_t *_Nonnull bytes);
TW_EXPORT_STATIC_METHOD
void TWBufferProbeOtherLength(uint8_t *_Nonnull bytes, uint32_t size);
""")
            destination = Path(directory) / 'out'
            generated = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(root), '--include', str(FIX), '--output', str(destination)],
                capture_output=True, text=True)
            self.assertEqual(0, generated.returncode, generated.stderr)
            report = json.loads((destination / 'abi.json').read_text())['functions']
            for name in ('CreateWritable', 'CreateReadOnly', 'CreateNullable', 'Mutate', 'Read'):
                entry = report['TWBufferProbe' + name]
                self.assertEqual('generated', entry['status'], (name, entry))
                self.assertEqual(1, len(entry['params']))
                self.assertTrue(entry['params'][0]['byte_buffer'])
            transform = report['TWBufferProbeTransform']
            self.assertEqual('generated', transform['status'])
            self.assertEqual(2, len(transform['params']))
            self.assertTrue(transform['params'][0]['byte_mutable'])
            self.assertFalse(transform['params'][1]['byte_mutable'])
            for name in ('PointerOnly', 'OtherLength'):
                self.assertEqual('excluded', report['TWBufferProbe' + name]['status'])
            c = (destination / 'walletcore.c').read_text()
            stub = (destination / 'walletcore.stub.php').read_text()
            self.assertIn('TWBufferProbeCreateWritable(raw0, raw_size0)', c)
            self.assertIn('TWBufferProbeCreateReadOnly(raw0, raw_size0)', c)
            self.assertIn('TWBufferProbeMutate(raw0, raw_size0)', c)
            self.assertIn('TWBufferProbeTransform(raw0, raw_size0, raw1, raw_size1)', c)
            self.assertIn('ZEND_ARG_INFO(1, bytes)', c)
            self.assertIn('ZEND_ARG_INFO(0, bytes)', c)
            mutable_section = c.split('PHP_METHOD(WC_BufferProbe, mutate) {', 1)[1].split('\n}', 1)[0]
            self.assertLess(mutable_section.index('ZVAL_DEREF(arg[0]);'),
                            mutable_section.index('raw_value0 = arg[0];'))
            self.assertIn('if (wc_native_called && raw0 && raw_value0', mutable_section)
            self.assertIn('zend_string_init((const char *)raw0, raw_size0, 0)', c)
            self.assertIn('if (raw0) efree(raw0);', c)
            # Do not replace a PHP by-reference value on *validation* failure
            # before the native call. Both buffers are prepared sequentially:
            # a valid writable output may precede an invalid input.
            section = c.split('PHP_METHOD(WC_BufferProbe, transform) {', 1)[1].split('\n}', 1)[0]
            self.assertIn('bool wc_native_called = false;', section)
            self.assertIn('wc_native_called = true;', section)
            self.assertLess(section.index('wc_native_called = true;'), section.index('TWBufferProbeTransform('))
            self.assertIn('if (wc_native_called && raw0 && raw_value0', section)
            self.assertLess(section.index('Z_TYPE_P(raw_value1) != IS_STRING'),
                            section.index('wc_native_called = true;'))
            self.assertIn('function createWritable(string &$bytes)', stub)
            self.assertIn('function createReadOnly(string $bytes)', stub)
            self.assertIn('function createNullable(?string &$bytes)', stub)
            self.assertIn('function mutate(string &$bytes): void', stub)
            self.assertIn('function read(string $bytes): void', stub)
            self.assertIn('function transform(string &$output, string $input): void', stub)
            syntax = subprocess.run(['clang', '-x', 'c', '-std=gnu11', '-fsyntax-only',
                '-Wno-everything', '-I'+str(BASE / 'tests/fixtures/syntax'),
                '-I'+str(Path(directory)), '-I'+str(FIX), str(destination / 'walletcore.c')],
                capture_output=True, text=True)
            self.assertEqual(0, syntax.returncode, syntax.stderr)
            php = subprocess.run(['php', '-l', str(destination / 'walletcore.stub.php')],
                capture_output=True, text=True)
            self.assertEqual(0, php.returncode, php.stderr)

    def test_enum_input_respects_native_abi_width_and_sign(self):
        # An enum's compatible integer type is selected by C/clang. A single
        # INT_MIN..UINT32_MAX gate does not fit packed, signed, or wide enums.
        # Validate ABI representability, not membership in enumerator lists.
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / 'TrustWalletCore'
            root.mkdir()
            (root / 'TWAuditEnum.h').write_text("""#pragma once
#include "TWBase.h"
enum __attribute__((packed)) TWAuditEnumSmall {
    TWAuditEnumSmallZero = 0, TWAuditEnumSmallMax = 42
};
enum __attribute__((packed)) TWAuditEnumSigned {
    TWAuditEnumSignedLow = -8, TWAuditEnumSignedHigh = 7
};
TW_EXPORT_STATIC_METHOD
bool TWAuditEnumAcceptSmall(enum TWAuditEnumSmall value);
TW_EXPORT_STATIC_METHOD
bool TWAuditEnumAcceptSigned(enum TWAuditEnumSigned value);
""")
            dest = Path(tmp) / 'out'
            generated = subprocess.run([
                sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(root), '--include', str(FIX), '--output', str(dest)
            ], capture_output=True, text=True)
            self.assertEqual(0, generated.returncode, generated.stderr)
            report = json.loads((dest / 'abi.json').read_text())['functions']
            c_code = (dest / 'walletcore.c').read_text()
            checks = []
            for native, method in (('TWAuditEnumAcceptSmall', 'acceptSmall'),
                                   ('TWAuditEnumAcceptSigned', 'acceptSigned')):
                self.assertEqual('generated', report[native]['status'])
                section = c_code.split(f'PHP_METHOD(WC_AuditEnum, {method}) {{', 1)[1].split('\n}', 1)[0]
                self.assertIn('native enum range', section)
                self.assertNotIn('UINT32_MAX', section)
                self.assertIn('goto cleanup;', section)
                check = next(line.strip() for line in section.splitlines()
                             if 'native enum range' in line)
                self.assertTrue(check.startswith('if ('), check)
                expression = check.split(' { zend_value_error', 1)[0][len('if ('):-1]
                # The expression must use Z_LVAL_P and be runnable against
                # the actual native enum type, not a generated constants list.
                checks.append(expression.replace('Z_LVAL_P(arg[0])', 'value'))
            probe = Path(tmp) / 'enum-abi.c'
            probe.write_text(f"""#include <stdint.h>
#include "TrustWalletCore/TWAuditEnum.h"
static int bad_small(int64_t value) {{ return {checks[0]}; }}
static int bad_signed(int64_t value) {{ return {checks[1]}; }}
int main(void) {{
    return !(sizeof(enum TWAuditEnumSmall) == 1 &&
        sizeof(enum TWAuditEnumSigned) == 1 &&
        !bad_small(42) && !bad_small(255) && bad_small(256) &&
        bad_small(-1) && !bad_signed(-8) && !bad_signed(7) &&
        bad_signed(128) && bad_signed(-129));
}}
""")
            exe = Path(tmp) / 'enum-abi'
            compilation = subprocess.run([
                'clang', '-std=gnu11', '-Wno-everything', '-I'+str(Path(tmp)),
                '-I'+str(FIX), str(probe), '-o', str(exe)
            ], capture_output=True, text=True)
            self.assertEqual(0, compilation.returncode, compilation.stderr)
            self.assertEqual(0, subprocess.run([str(exe)]).returncode)
            syntax = subprocess.run([
                'clang', '-x', 'c', '-std=gnu11', '-fsyntax-only', '-Wno-everything',
                '-I'+str(BASE / 'tests/fixtures/syntax'), '-I'+str(Path(tmp)), '-I'+str(FIX),
                str(dest / 'walletcore.c')
            ], capture_output=True, text=True)
            self.assertEqual(0, syntax.returncode, syntax.stderr)

    def test_const_opaque_return_is_not_assumed_owned(self):
        # The ABI does not transfer ownership of a const opaque pointer simply
        # because a Delete symbol exists. Only ordinary mutable object returns
        # can enter the existing owned Zend-wrapper path without a stronger
        # native ownership contract.
        with tempfile.TemporaryDirectory() as temp:
            headers = Path(temp) / 'TrustWalletCore'
            headers.mkdir()
            (headers / 'TWResource.h').write_text("""#pragma once
#include "TWBase.h"
struct TWResource;
TW_EXPORT_METHOD
void TWResourceDelete(struct TWResource *value);
TW_EXPORT_STATIC_METHOD
struct TWResource *_Nullable TWResourceCreate(void);
TW_EXPORT_STATIC_METHOD
const struct TWResource *_Nullable TWResourceView(void);
TW_EXPORT_STATIC_METHOD
const struct TWResource *_Nonnull TWResourceInspect(void);
""")
            output = Path(temp) / 'out'
            run = subprocess.run([sys.executable, str(BASE / 'tools/codegen/generate.py'),
                '--headers', str(headers), '--include', str(FIX),
                '--output', str(output)], capture_output=True, text=True)
            self.assertEqual(0, run.returncode, run.stderr)
            abi = json.loads((output / 'abi.json').read_text())['functions']
            self.assertEqual('generated', abi['TWResourceCreate']['status'])
            for func in ('TWResourceView', 'TWResourceInspect'):
                self.assertEqual('excluded', abi[func]['status'])
                self.assertIn('const', abi[func]['reason'])
            code = (output / 'walletcore.c').read_text()
            self.assertIn('PHP_METHOD(WC_Resource, create)', code)
            self.assertNotIn('PHP_METHOD(WC_Resource, view)', code)
            self.assertNotIn('PHP_METHOD(WC_Resource, inspect)', code)
            syntax = subprocess.run(['clang', '-x', 'c', '-std=gnu11',
                '-fsyntax-only', '-Wno-everything', '-I'+str(BASE/'tests/fixtures/syntax'),
                '-I'+str(Path(temp)), '-I'+str(FIX), str(output/'walletcore.c')],
                capture_output=True, text=True)
            self.assertEqual(0, syntax.returncode, syntax.stderr)

    def test_fail_closed_when_headers_incomplete(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t)/'headers'
            root.mkdir()
            (root/'TWBreak.h').write_text('#include "not_a_real_generated_header.h"\nextern int TWBreakThing(void);\n')
            output = Path(t)/'out'
            result = subprocess.run([sys.executable, str(BASE/'tools/codegen/generate.py'),
                '--headers', str(root), '--output', str(output)], capture_output=True, text=True)
            self.assertNotEqual(0, result.returncode)
            self.assertFalse((output/'walletcore.c').exists())

if __name__ == '__main__':
    unittest.main()
