#!/usr/bin/env python3
"""Generate executable Zend class bindings from the preprocessed Wallet Core C ABI.

Clang (also used by Wallet Core) parses C declarations; there is no maintained
list of blockchain-specific functions. Unsupported ABI patterns fail visibly in
abi.json instead of silently disappearing.
"""

import argparse
import json
import re
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

SCALARS = {
    'bool': ('bool', 'bool'),
    '_Bool': ('bool', '_Bool'),
    'int': ('signed', 'int'),
    'size_t': ('unsigned', 'size_t'),
    'char': ('signed', 'char'),
    'uint8_t': ('unsigned', 'uint8_t'),
    'uint16_t': ('unsigned', 'uint16_t'),
    'uint32_t': ('unsigned', 'uint32_t'),
    'uint64_t': ('unsigned', 'uint64_t'),
    'int8_t': ('signed', 'int8_t'),
    'int16_t': ('signed', 'int16_t'),
    'int32_t': ('signed', 'int32_t'),
    'int64_t': ('signed', 'int64_t'),
}
# For inputs, require values representable exactly in the ABI C type.
BOUNDS = {
    'uint8_t': ('0', 'UINT8_MAX'),
    'uint16_t': ('0', 'UINT16_MAX'),
    'uint32_t': ('0', 'UINT32_MAX'),
    'uint64_t': ('0', 'ZEND_LONG_MAX'),
    'size_t': ('0', 'ZEND_LONG_MAX'),
    'int8_t': ('INT8_MIN', 'INT8_MAX'),
    'int16_t': ('INT16_MIN', 'INT16_MAX'),
    'int32_t': ('INT32_MIN', 'INT32_MAX'),
    'int64_t': ('ZEND_LONG_MIN', 'ZEND_LONG_MAX'),
    'int': ('INT_MIN', 'INT_MAX'),
    'char': ('CHAR_MIN', 'CHAR_MAX'),
}


def simplify(q):
    q = re.sub(r'\b(_Nullable|_Nonnull|_Null_unspecified|const|volatile|restrict)\b', '', q)
    return ' '.join(q.replace('*', ' * ').split())


def kind(q):
    q = simplify(q)
    if q == 'void':
        return ('void', None)
    if q in SCALARS:
        return (SCALARS[q][0], q)
    if q.startswith('enum TW') and '*' not in q:
        return ('enum', q[len('enum TW'):])
    if q in ('TWData *', 'struct TWData *'):
        return ('data', None)
    if q in ('TWString *', 'struct TWString *'):
        return ('string', None)
    m = re.fullmatch(r'struct TW([A-Za-z0-9_]+) \*', q)
    if m:
        return ('object', m[1])
    return ('unsupported', q)


def has_const_pointee(q):
    """Check const on a pointed-to C type, not top-level pointer qualifiers."""
    return '*' in q and bool(re.search(r'\bconst\b', q.split('*', 1)[0]))


def byte_buffer_pair(pointer, length):
    """Recognize a C byte buffer followed immediately by its element count.

    It is purely a C signature rule. Writable pointers are passed by PHP
    reference so writes are observable; non-const pointers never alias a Zend
    string's backing storage. Other pointer shapes remain explicitly excluded.
    """
    return simplify(pointer['type']) == 'uint8_t *' and simplify(length['type']) == 'size_t'


def php_parameters(native_params):
    """Map one (bytes, length) C pair to one PHP binary string argument."""
    result = []
    index = 0
    while index < len(native_params):
        parameter = native_params[index]
        if index + 1 < len(native_params) and byte_buffer_pair(parameter, native_params[index + 1]):
            result.append({**parameter, 'byte_buffer': True,
                           'byte_mutable': 'const' not in parameter['type'].split('*', 1)[0].split(),
                           'byte_size_name': native_params[index + 1]['name']})
            index += 2
        else:
            result.append(parameter)
            index += 1
    return result


# Match a marker and its C function declarator in the *original header*.
# Clang has changed where it reports FunctionDecl.range.begin across releases.
# Using the exact identifier offset also avoids attributing one declaration's
# marker to a later unmarked function.
EXPORT_DECL = re.compile(
    rb'(?m)^[ \t]*TW_EXPORT_(STATIC_METHOD|STATIC_PROPERTY|METHOD|PROPERTY|FUNC)\b'
    rb'(?:(?!TW_EXPORT_|[;{}]).)*?\b(TW[A-Za-z0-9_]+)\s*\(',
    re.DOTALL,
)


def source_export_markers(source):
    """Map native function identifier positions to unexpanded export markers."""
    # Blank comments and preprocessor lines without shifting source offsets.
    clean = re.sub(rb'/\*.*?\*/|//[^\n]*',
                   lambda match: re.sub(rb'[^\n]', b' ', match.group()),
                   source, flags=re.DOTALL)
    clean = re.sub(rb'(?m)^[ \t]*#[^\n]*',
                   lambda match: b' ' * len(match.group()), clean)
    return {match.start(2): match.group(1).decode('ascii')
            for match in EXPORT_DECL.finditer(clean)}


def declaration_abi(function):
    """Compare native C types/semantics, not parameter variable names."""
    return (
        function['return'],
        function['marker'],
        tuple(parameter['type'] for parameter in function['params']),
    )


def enum_constant_value(node):
    """Use Clang's evaluated value, including beneath implicit AST casts.

    Never guess at an explicit initializer: a wrong value would also corrupt
    the following implicitly numbered enumerators.
    """
    if 'value' in node:
        return int(node['value'], 0)
    pending = list(node.get('inner', []))
    while pending:
        current = pending.pop(0)
        if current.get('kind') == 'ConstantExpr' and 'value' in current:
            return int(current['value'], 0)
        pending[0:0] = current.get('inner', [])
    return None


def php_classes(functions, enums, status):
    owners = {entry['owner'] for entry in status.values() if entry['status'] == 'generated'}
    referenced = {
        type_name
        for f in functions.values()
        for type_kind, type_name in (
            [kind(f['return'])] + [kind(param['type']) for param in f['params']]
        )
        if type_kind == 'object'
    }
    return sorted(set(enums) | owners | referenced)


# Conservative across supported PHP versions: do not emit class declarations
# whose names are PHP keywords or reserved scalar/pseudo types.
PHP_RESERVED_CLASS_NAMES = set("""abstract and array as break callable case catch class clone const continue
    declare default do else elseif enddeclare endfor endforeach endif endswitch
    endwhile extends final finally fn for foreach function global goto if
    implements include include_once instanceof insteadof interface isset list
    match namespace new or print private protected public readonly require
    require_once return static switch throw trait try unset use var while xor
    yield int float bool string void never iterable object mixed self parent
    true false null""".split())
PHP_IDENTIFIER = re.compile(r'[A-Za-z_][A-Za-z0-9_]*\Z')


def php_api_errors(functions, enums, status):
    errors = []
    seen_classes = {}
    for owner in php_classes(functions, enums, status):
        lower = owner.lower()
        if not PHP_IDENTIFIER.fullmatch(owner) or lower in PHP_RESERVED_CLASS_NAMES:
            errors.append({'header': '<php-api>',
                           'error': f'PHP class name WalletCore\\{owner} is invalid or reserved'})
        elif lower in seen_classes:
            errors.append({'header': '<php-api>',
                           'error': f'case-insensitive PHP class collision: {seen_classes[lower]} and {owner}'})
        else:
            seen_classes[lower] = owner
    for owner, constants in enums.items():
        seen_constants = set()
        for native_name in constants:
            label = native_name[len('TW'+owner):] if native_name.startswith('TW'+owner) else native_name
            if not PHP_IDENTIFIER.fullmatch(label) or label.lower() == 'class':
                errors.append({'header': '<php-api>',
                               'error': f'invalid/reserved PHP constant WalletCore\\{owner}::{label} ({native_name})'})
            elif label in seen_constants:
                errors.append({'header': '<php-api>',
                               'error': f'duplicate PHP constant WalletCore\\{owner}::{label}'})
            seen_constants.add(label)
    for native_name, entry in status.items():
        if entry['status'] != 'generated':
            continue
        method = entry['method']
        if not PHP_IDENTIFIER.fullmatch(method) or method.lower().startswith('__'):
            errors.append({'header': entry['header'],
                           'error': f'unsafe PHP method name {entry["owner"]}::{method} ({native_name})'})
        seen_params = set()
        for param in entry['params']:
            name = param['name']
            if not PHP_IDENTIFIER.fullmatch(name) or name.lower() == 'this' or name in seen_params:
                errors.append({'header': entry['header'],
                               'error': f'invalid/duplicate PHP parameter ${name} in {native_name}'})
            seen_params.add(name)
    return errors


def parse_headers(folder, clang, include_dirs):
    functions, enums, errors = {}, {}, []
    enum_sources = {}
    exported_symbols = {}
    files = sorted(folder.glob('*.h'))
    for header in files:
        src = header.read_bytes()
        export_markers = source_export_markers(src)
        # Compare exported C symbols directly in their source headers. Clang
        # versions disagree on macro-expanded declaration locations, so AST
        # offsets alone cannot enforce instance-vs-static ABI consistency.
        for offset, marker in export_markers.items():
            match = re.match(rb'TW[A-Za-z0-9_]+', src[offset:])
            if not match:
                continue
            symbol = match.group().decode('ascii')
            previous = exported_symbols.get(symbol)
            if previous is not None and previous[1] != marker:
                errors.append({'header': header.name,
                               'error': f'conflicting C declarations for {symbol}: '
                                        f'{previous[0]} {previous[1]} vs {header.name} {marker}'})
            else:
                exported_symbols[symbol] = (header.name, marker)
        cmd = [clang, '-x', 'c', '-std=gnu11', '-fsyntax-only', '-Wno-everything',
               '-Xclang', '-ast-dump=json', *(f'-I{d}' for d in include_dirs), str(header)]
        proc = subprocess.run(cmd, capture_output=True, text=True)
        if proc.returncode:
            errors.append({'header': header.name, 'error': proc.stderr[:1200]})
            # AST may be incomplete; never claim completeness from failed parsing.
            continue
        try:
            tree = json.loads(proc.stdout)
        except json.JSONDecodeError as exc:
            errors.append({'header': header.name, 'error': str(exc)})
            continue
        for node in tree.get('inner', []):
            name = node.get('name', '')
            loc = node.get('loc', {})
            offset = loc.get('offset', -1)
            # clang's included declarations can lack a 'file', so validate the
            # lexical spelling at the offset against *this* source file.
            if offset < 0 or loc.get('includedFrom') or src[offset:offset+len(name)] != name.encode('ascii'):
                continue
            if node['kind'] == 'FunctionDecl' and name.startswith('TW'):
                params = [x for x in node.get('inner', []) if x.get('kind') == 'ParmVarDecl']
                qual = node['type']['qualType']
                ret = qual.split('(', 1)[0].strip()
                declaration = {
                    'name': name, 'header': header.name, 'marker': export_markers.get(offset, 'FREE'),
                    'return': ret, 'params': [{'name': p.get('name') or f'arg{i}',
                                                'type': p['type']['qualType']}
                                               for i, p in enumerate(params)],
                }
                previous = functions.get(name)
                if previous is None:
                    functions[name] = declaration
                elif declaration_abi(previous) != declaration_abi(declaration):
                    errors.append({'header': header.name,
                                   'error': f'conflicting C declarations for {name}: '
                                            f'{previous["header"]} {declaration_abi(previous)!r} vs '
                                            f'{header.name} {declaration_abi(declaration)!r}'})
                # An identical redeclaration is harmless; retain the first
                # declaration so PHP names/metadata are independent of order.
            if node['kind'] == 'EnumDecl' and name.startswith('TW'):
                cases = {}
                next_val = 0
                for x in node.get('inner', []):
                    if x.get('kind') != 'EnumConstantDecl':
                        continue
                    if x.get('inner'):
                        try:
                            explicit = enum_constant_value(x)
                        except (TypeError, ValueError) as exc:
                            explicit = None
                        if explicit is None:
                            errors.append({'header': header.name,
                                           'error': f'cannot evaluate C enum {name}::{x["name"]}'})
                            break
                        next_val = explicit
                    if not -(1 << 63) <= next_val < (1 << 63):
                        errors.append({'header': header.name,
                                       'error': f'C enum {name}::{x["name"]} is outside PHP zend_long range'})
                        break
                    cases[x['name']] = next_val
                    next_val += 1
                enum_name = name[2:]
                previous_cases = enums.get(enum_name)
                if previous_cases is not None and cases and previous_cases and previous_cases != cases:
                    errors.append({'header': header.name,
                                   'error': f'conflicting enum declarations for {name}: '
                                            f'{enum_sources[enum_name]} vs {header.name}'})
                elif cases and not previous_cases:
                    enums[enum_name] = cases
                    enum_sources[enum_name] = header.name
                elif previous_cases is None:
                    enums[enum_name] = cases
                    enum_sources[enum_name] = header.name
    return functions, enums, errors


def quoted(v): return json.dumps(v)


def classify(f, classes, destructors):
    name = f['name']
    candidates = sorted((c for c in classes if name.startswith('TW'+c)), key=len, reverse=True)
    # TWData and TWString are marshalled as PHP strings. Do not, however,
    # discard independently declared classes that merely share these prefixes
    # (TWDataVector and future TWString* / TWData* classes).
    if name.startswith(('TWData', 'TWString')) and not any(
            c not in ('Data', 'String') for c in candidates):
        return None, 'special TWData/TWString handled by the runtime adapter'
    if not candidates:
        return None, 'unrecognized declaring class or namespace'
    owner = candidates[0]
    # Names are not enough: longer class names can shadow the true receiver.
    # Examples: DerivationPathIndexAt belongs to DerivationPath, and
    # StoredKeyEncryptionParameters belongs to StoredKey (not the enum).
    if f['marker'] in ('METHOD', 'PROPERTY') and f['params']:
        receiver_kind, receiver_type = kind(f['params'][0]['type'])
        if receiver_kind in ('object', 'enum') and receiver_type in candidates:
            owner = receiver_type
    retk, rett = kind(f['return'])
    if retk == 'unsupported':
        return None, 'unsupported return type: '+str(rett)
    # A read-only opaque result is not evidence of ownership transfer. Even
    # when this class has a valid Delete function, taking ownership here would
    # require casting away const and might destroy upstream-owned storage.
    # Do not infer a borrowed-object policy or invent a version-specific rule.
    if retk == 'object' and has_const_pointee(f['return']):
        return None, 'const-qualified opaque return has no verified ownership transfer'
    if retk == 'object' and rett not in destructors:
        return None, 'opaque return has no known destructor: '+rett
    if name == 'TW'+owner+'Delete':
        return None, 'native destructor handled by Zend object lifecycle'
    suffix = name[len('TW'+owner):]
    if not suffix:
        return None, 'empty function suffix'
    # Zend method names should follow PHP's lowerCamelCase convention.
    # Preserve the remaining C ABI suffix (including internal acronyms).
    method = suffix[0].lower() + suffix[1:]
    # Marker, not parameter heuristics, defines instance/static semantics.
    instance = f['marker'] in ('METHOD', 'PROPERTY')
    # C enum methods take an enum value as receiver. PHP enums are constant-only
    # classes, so expose those methods as static functions with explicit value.
    if instance and owner in classes and f['params'] and kind(f['params'][0]['type']) == ('enum', owner):
        instance = False
    if instance:
        if not f['params'] or kind(f['params'][0]['type']) != ('object', owner):
            return None, 'instance method lacks matching receiver'
        if owner not in destructors:
            return None, 'receiver has no known destructor/class'
    native_params = f['params'][1:] if instance else f['params']
    mapped_params = php_parameters(native_params)
    for p in mapped_params:
        if p.get('byte_buffer'):
            continue
        k, t = kind(p['type'])
        if k in ('unsupported', 'void'):
            return None, 'unsupported parameter type: '+p['type']
        if k == 'object' and t not in destructors:
            return None, 'opaque argument has no known destructor/class: '+t
    return {'owner': owner, 'method': method, 'instance': instance,
            'params': mapped_params,
            'return_kind': retk, 'return_type': rett}, None


def valid_destructors(functions):
    """Recognize only the actual native one-pointer void destructor ABI.

    A *Delete suffix alone is not a lifetime contract. This guards both
    wrapper creation and the Zend object's free handler against mismatched C
    signatures in the selected upstream headers.
    """
    result = set()
    for name, f in functions.items():
        match = re.fullmatch(r'TW([A-Za-z0-9_]+)Delete', name)
        if not match:
            continue
        owner = match[1]
        params = f['params']
        if (kind(f['return']) == ('void', None) and len(params) == 1
                and kind(params[0]['type']) == ('object', owner)):
            result.add(owner)
    return result


def generate_function(f, info):
    name, owner, meth = f['name'], info['owner'], info['method']
    pars = info['params']
    out = [f'PHP_METHOD(WC_{owner}, {meth}) {{']
    n = len(pars)
    if n:
        out.append('    zval *arg['+str(n)+'] = {0};')
    if n:
        out += [f'    ZEND_PARSE_PARAMETERS_START({n}, {n})']
        for i in range(n):
            out.append(f'        Z_PARAM_ZVAL(arg[{i}])')
        out.append('    ZEND_PARSE_PARAMETERS_END();')
    else:
        out.append('    ZEND_PARSE_PARAMETERS_NONE();')
    # Z_PARAM_ZVAL returns the actual zval, which may be an IS_REFERENCE.
    # Unwrap every input before inspecting Z_TYPE_P / Z_LVAL_P or accessing
    # a native object. Writable byte buffers still modify the referenced zval.
    for i in range(n):
        out.append(f'    ZVAL_DEREF(arg[{i}]);')
    if info['instance']:
        out.append('    void *self = NULL;')
    # A writable buffer must only be propagated back to PHP if the native
    # function was actually entered. Earlier argument/receiver validation may
    # fail after we have allocated temporary buffers.
    if any(p.get('byte_mutable') for p in pars):
        out.append('    bool wc_native_called = false;')
    for i, p in enumerate(pars):
        k, t = kind(p['type'])
        if p.get('byte_buffer'):
            out.append(f'    uint8_t *raw{i} = NULL;')
            out.append(f'    size_t raw_size{i} = 0;')
            out.append(f'    zval *raw_value{i} = NULL;')
        elif k in ('data', 'string'):
            out.append(f'    {"TWData" if k == "data" else "TWString"} *tmp{i} = NULL;')
    for i, p in enumerate(pars):
        k, t = kind(p['type'])
        nullable = '_Nullable' in p['type']
        if p.get('byte_buffer'):
            out.append(f'    raw_value{i} = arg[{i}];')
            condition = f'Z_TYPE_P(raw_value{i}) != IS_STRING'
            if nullable:
                condition += f' && Z_TYPE_P(raw_value{i}) != IS_NULL'
            out.append(f'    if ({condition}) {{ zend_type_error("{name}: argument {i+1} must be a binary string"); goto cleanup; }}')
            out.append(f'    if (Z_TYPE_P(raw_value{i}) == IS_STRING) {{')
            out.append(f'        raw_size{i} = Z_STRLEN_P(raw_value{i});')
            out.append(f'        raw{i} = emalloc(raw_size{i} ? raw_size{i} : 1);')
            out.append(f'        if (raw_size{i}) memcpy(raw{i}, Z_STRVAL_P(raw_value{i}), raw_size{i});')
            out.append('    }')
        elif k in ('data', 'string'):
            out.append(f'    if (Z_TYPE_P(arg[{i}]) != IS_STRING'+(' && Z_TYPE_P(arg['+str(i)+']) != IS_NULL' if nullable else '')+f') {{ zend_type_error("{name}: argument {i+1} must be a string"); goto cleanup; }}')
            out.append(f'    if (Z_TYPE_P(arg[{i}]) == IS_STRING) {{')
            if k == 'data':
                out.append(f'        tmp{i} = TWDataCreateWithBytes((const uint8_t *)Z_STRVAL_P(arg[{i}]), Z_STRLEN_P(arg[{i}]));')
            else:
                out.append(f'        tmp{i} = TWStringCreateWithRawBytes((const uint8_t *)Z_STRVAL_P(arg[{i}]), Z_STRLEN_P(arg[{i}]));')
            out.append(f'        if (!tmp{i}) {{ zend_throw_exception(zend_ce_exception, "Failed to allocate Wallet Core buffer", 0); goto cleanup; }}')
            out.append('    }')
        elif k == 'object':
            cond = f'Z_TYPE_P(arg[{i}]) != IS_OBJECT || !instanceof_function(Z_OBJCE_P(arg[{i}]), wc_ce_{t})'
            if nullable:
                cond = f'(Z_TYPE_P(arg[{i}]) != IS_NULL && ({cond}))'
            out.append(f'    if ({cond}) {{ zend_type_error("{name}: argument {i+1} must be a {t} object"); goto cleanup; }}')
            # A nullable native pointer means PHP null is allowed, *not* that
            # a fabricated/uninitialized Zend wrapper may act as a pointer.
            # This must be checked for both nullable and non-nullable objects
            # before calling Wallet Core C (which may dereference the pointer).
            out.append(f'    if (Z_TYPE_P(arg[{i}]) == IS_OBJECT && !wc_obj(Z_OBJ_P(arg[{i}]))->ptr) {{ zend_throw_exception(zend_ce_exception, "Native argument object is not initialized", 0); goto cleanup; }}')
        elif k == 'bool':
            out.append(f'    if (Z_TYPE_P(arg[{i}]) != IS_TRUE && Z_TYPE_P(arg[{i}]) != IS_FALSE) {{ zend_type_error("{name}: argument {i+1} must be bool"); goto cleanup; }}')
        elif k in ('signed', 'unsigned', 'enum'):
            out.append(f'    if (Z_TYPE_P(arg[{i}]) != IS_LONG) {{ zend_type_error("{name}: argument {i+1} must be int"); goto cleanup; }}')
            if k == 'enum':
                # C enums may be signed, unsigned, packed, or wider than int;
                # their integer representation is compiler- and header-defined.
                # Validate ABI representability, never an enumerator allowlist.
                ct = simplify(p['type'])
                value = f'Z_LVAL_P(arg[{i}])'
                cast = f'({ct}){value}'
                unsigned_enum = f'!(({ct})-1 < ({ct})0)'
                overflow = (f'({value} < 0 && {unsigned_enum}) || '
                            f'({value} < 0 ? (int64_t){cast} != (int64_t){value} : '
                            f'(uint64_t){cast} != (uint64_t){value})')
                out.append(f'    if ({overflow}) {{ zend_value_error("{name}: argument {i+1} outside native enum range"); goto cleanup; }}')
            else:
                lo, hi = BOUNDS.get(t, ('INT_MIN', 'INT_MAX'))
                out.append(f'    if (Z_LVAL_P(arg[{i}]) < {lo} || Z_LVAL_P(arg[{i}]) > ({hi})) {{ zend_value_error("{name}: argument {i+1} outside native range"); goto cleanup; }}')
    if info['instance']:
        out.append('    if (!getThis() || Z_TYPE_P(getThis()) != IS_OBJECT || !instanceof_function(Z_OBJCE_P(getThis()), wc_ce_'+owner+')) { zend_throw_exception(zend_ce_exception, "Invalid receiver", 0); goto cleanup; }')
        out.append('    self = wc_obj(Z_OBJ_P(getThis()))->ptr;')
        out.append('    if (!self) { zend_throw_exception(zend_ce_exception, "Native object was released", 0); goto cleanup; }')
    args = []
    if info['instance']:
        args.append('(struct TW'+owner+' *)self')
    for i, p in enumerate(pars):
        k, t = kind(p['type'])
        if p.get('byte_buffer'):
            args.extend((f'raw{i}', f'raw_size{i}'))
        elif k in ('data', 'string'):
            args.append(f'tmp{i}')
        elif k == 'object':
            args.append(f'(struct TW{t} *) (Z_TYPE_P(arg[{i}]) == IS_NULL ? NULL : wc_obj(Z_OBJ_P(arg[{i}]))->ptr)')
        elif k == 'bool':
            args.append(f'(Z_TYPE_P(arg[{i}]) == IS_TRUE)')
        else:
            args.append(f'({simplify(p["type"])})Z_LVAL_P(arg[{i}])')
    call = f'{name}({", ".join(args)})'
    rk, rt = info['return_kind'], info['return_type']
    if any(p.get('byte_mutable') for p in pars):
        out.append('    wc_native_called = true;')
    if rk == 'void':
        out.append('    '+call+';')
    else:
        out.append('    '+f'{f["return"].replace("_Nullable", "").replace("_Nonnull", "")} result = {call};')
    if rk == 'bool':
        out.append('    RETVAL_BOOL(result);')
    elif rk in ('signed', 'enum'):
        # zend_long may be narrower than the C return type. Check using the
        # original value's sign before casting: a future enum can also have
        # an unsigned underlying type wider than int64_t's positive range.
        out.append('    if ((result < 0 && (int64_t)result < (int64_t)ZEND_LONG_MIN) || (result >= 0 && (uint64_t)result > (uint64_t)ZEND_LONG_MAX)) { zend_throw_exception(zend_ce_exception, "Native integer exceeds PHP integer range", 0); goto cleanup; }')
        out.append('    RETVAL_LONG((zend_long)result);')
    elif rk == 'unsigned':
        out.append('    if ((uint64_t)result > ZEND_LONG_MAX) { zend_throw_exception(zend_ce_exception, "Native integer exceeds PHP_INT_MAX", 0); goto cleanup; }')
        out.append('    RETVAL_LONG((zend_long)result);')
    elif rk == 'void':
        out.append('    RETVAL_NULL();')
    elif rk in ('data', 'string'):
        out.append('    if (!result) { RETVAL_NULL(); } else {')
        if rk == 'data':
            out.append('        RETVAL_STRINGL((const char *)TWDataBytes(result), TWDataSize(result));')
        else:
            out.append('        RETVAL_STRINGL(TWStringUTF8Bytes(result), TWStringSize(result));')
        out.append('        '+('TWDataDelete' if rk == 'data' else 'TWStringDelete')+'(result);')
        out.append('    }')
    elif rk == 'object':
        out.append('    if (!result) { RETVAL_NULL(); } else {')
        out.append(f'        object_init_ex(return_value, wc_ce_{rt});')
        out.append(f'        wc_obj(Z_OBJ_P(return_value))->ptr = result;')
        out.append(f'        wc_obj(Z_OBJ_P(return_value))->deleter = wc_delete_{rt};')
        out.append('    }')
    if n or info['instance'] or rk in ('unsigned', 'signed', 'enum'):
        out.append('cleanup: ;')
    for i, p in enumerate(pars):
        k, t = kind(p['type'])
        if p.get('byte_buffer'):
            if p['byte_mutable']:
                # A writable C pointer may modify the buffer. Preserve those
                # writes in the PHP reference even if the function returned
                # a failure code; do not silently discard native side effects.
                out.append(f'    if (wc_native_called && raw{i} && raw_value{i} && Z_TYPE_P(raw_value{i}) == IS_STRING) {{')
                out.append(f'        zend_string *modified = zend_string_init((const char *)raw{i}, raw_size{i}, 0);')
                out.append(f'        zval_ptr_dtor(raw_value{i});')
                out.append(f'        ZVAL_STR(raw_value{i}, modified);')
                out.append('    }')
            out.append(f'    if (raw{i}) efree(raw{i});')
        elif k in ('data', 'string'):
            out.append(f'    if (tmp{i}) '+('TWDataDelete' if k == 'data' else 'TWStringDelete')+f'(tmp{i});')
    out.append('}')
    return '\n'.join(out)


def emit(functions, enums, result, dest, version, headers, destructors):
    included = {k:v for k, v in result.items() if v['status'] == 'generated'}
    wrappers = defaultdict(list)
    for name, data in included.items():
        owner = data['owner']
        wrappers[owner].append((functions[name], data))
    # Include input-only native classes as well as classes owning PHP methods.
    classes = php_classes(functions, enums, included)
    used_headers = sorted(headers)
    src = ['/* Autogenerated. Edit tools/codegen/generate.py, not this file. */',
         '#ifdef HAVE_CONFIG_H', '#include "config.h"', '#endif',
         '#include "php.h"', '#include "Zend/zend_exceptions.h"', '#include "ext/standard/info.h"',
         '#include <stdint.h>', '#include <limits.h>', '#include <string.h>',
         *[f'#include "TrustWalletCore/{h}"' for h in used_headers],
         '', 'typedef struct { void *ptr; void (*deleter)(void *); zend_object std; } wc_native;',
         'static zend_object_handlers wc_handlers;',
         'static inline wc_native *wc_obj(zend_object *obj) { return (wc_native *)((char *)obj - XtOffsetOf(wc_native, std)); }',
         'static zend_object *wc_create(zend_class_entry *ce) {',
         '    wc_native *o = zend_object_alloc(sizeof(wc_native), ce);',
         '    o->ptr = NULL; o->deleter = NULL;',
         '    zend_object_std_init(&o->std, ce); object_properties_init(&o->std, ce);',
         '    o->std.handlers = &wc_handlers; return &o->std;', '}',
         'static void wc_free(zend_object *obj) {',
         '    wc_native *o = wc_obj(obj);',
         '    if (o->ptr && o->deleter) o->deleter(o->ptr);',
         '    o->ptr = NULL; zend_object_std_dtor(&o->std);', '}',
         *[f'static zend_class_entry *wc_ce_{c};' for c in classes],
         *[f'static void wc_delete_{c}(void *p) {{ TW{c}Delete((struct TW{c} *)p); }}' for c in classes if c in destructors], '']
    for owner in sorted(wrappers):
        for f, data in sorted(wrappers[owner], key=lambda x:x[1]['method']):
            src.append(generate_function(f, data))
            src.append('')
    for owner in classes:
        methods = sorted(wrappers.get(owner, []), key=lambda x:x[1]['method'])
        for f, data in methods:
            a = f'arginfo_WC_{owner}_{data["method"]}'
            src.append(f'ZEND_BEGIN_ARG_INFO_EX({a}, 0, 0, {len(data["params"])})')
            for p in data['params']:
                src.append(f'    ZEND_ARG_INFO({1 if p.get("byte_mutable") else 0}, {p["name"]})')
            src.append('ZEND_END_ARG_INFO()')
        src.append(f'static const zend_function_entry wc_methods_{owner}[] = {{')
        for f, data in methods:
            flags = 'ZEND_ACC_PUBLIC' + (' | ZEND_ACC_STATIC' if not data['instance'] else '')
            src.append(f'    PHP_ME(WC_{owner}, {data["method"]}, arginfo_WC_{owner}_{data["method"]}, {flags})')
        src.append('    PHP_FE_END')
        src.append('};')
    src.append('PHP_MINIT_FUNCTION(walletcore) {')
    src += ['    memcpy(&wc_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));',
            '    wc_handlers.offset = XtOffsetOf(wc_native, std);',
            '    wc_handlers.free_obj = wc_free;',
            '    wc_handlers.clone_obj = NULL;']
    for owner in classes:
        src.append('    { zend_class_entry ce;')
        src.append(f'      INIT_NS_CLASS_ENTRY(ce, "WalletCore", "{owner}", wc_methods_{owner});')
        src.append(f'      wc_ce_{owner} = zend_register_internal_class(&ce);')
        # Set create object only for classes with a destructor: this includes return objects.
        if owner in destructors:
            src.append(f'      wc_ce_{owner}->create_object = wc_create;')
        src.append(f'      wc_ce_{owner}->ce_flags |= ZEND_ACC_FINAL;')
        if owner in enums:
            for constant in enums[owner]:
                label = constant[len('TW'+owner):] if constant.startswith('TW'+owner) else constant
                src.append(f'      zend_declare_class_constant_long(wc_ce_{owner}, "{label}", sizeof("{label}")-1, (zend_long){constant});')
        src.append('    }')
    src += ['    return SUCCESS;', '}',
            # Protobuf allocates static-duration data during C++ DSO load.
            # The ASan-only shutdown hook checks whether its documented cleanup
            # removes the one-root LSan leak tree. Release lifecycle is untouched.
            '#ifdef WC_ASAN_PROTOBUF_CLEANUP',
            'extern void wc_asan_protobuf_cleanup(void);',
            'PHP_MSHUTDOWN_FUNCTION(walletcore) {',
            '    wc_asan_protobuf_cleanup();',
            '    return SUCCESS;',
            '}',
            '#define WC_MODULE_SHUTDOWN PHP_MSHUTDOWN(walletcore)',
            '#else',
            '#define WC_MODULE_SHUTDOWN NULL',
            '#endif',
            'PHP_MINFO_FUNCTION(walletcore) { php_info_print_table_start(); php_info_print_table_row(2, "WalletCore", "enabled"); php_info_print_table_end(); }',
            f'zend_module_entry walletcore_module_entry = {{ STANDARD_MODULE_HEADER, "walletcore", NULL, PHP_MINIT(walletcore), WC_MODULE_SHUTDOWN, NULL, NULL, PHP_MINFO(walletcore), {quoted(version)}, STANDARD_MODULE_PROPERTIES }};',
            '#ifdef COMPILE_DL_WALLETCORE', '#ifdef ZTS', 'ZEND_TSRMLS_CACHE_DEFINE()', '#endif', 'ZEND_GET_MODULE(walletcore)', '#endif']
    dest.mkdir(parents=True, exist_ok=True)
    (dest/'walletcore.c').write_text('\n'.join(src)+'\n')
    st = ['<?php', '// Generated PHP API surface. Only files built from same headers are compatible.', 'namespace WalletCore;', '']
    for owner in classes:
        st.append('class '+owner+' {')
        for const, value in enums.get(owner, {}).items():
            label = const[len('TW'+owner):] if const.startswith('TW'+owner) else const
            st.append(f'    public const {label} = {value};')
        for f, data in sorted(wrappers.get(owner, []), key=lambda x:x[1]['method']):
            ptypes = []
            for i, p in enumerate(data['params']):
                k, t = kind(p['type'])
                tt = 'string' if p.get('byte_buffer') or k in ('data', 'string') else ('bool' if k == 'bool' else ('int' if k in ('signed', 'unsigned', 'enum') else t))
                if '_Nullable' in p['type']:
                    tt = '?'+tt
                ptypes.append(f'{tt} {"&" if p.get("byte_mutable") else ""}${p["name"] or "arg"+str(i)}')
            retk, rett = data['return_kind'], data['return_type']
            rt = {'data':'?string', 'string':'?string', 'bool':'bool', 'signed':'int', 'unsigned':'int', 'enum':'int', 'void':'void'}.get(retk, '?'+str(rett))
            st.append(f'    public {"static " if not data["instance"] else ""}function {data["method"]}({", ".join(ptypes)}): {rt} {{}}')
        st.append('}')
        st.append('')
    (dest/'walletcore.stub.php').write_text('\n'.join(st)+'\n')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument(
        '--headers',
        type=Path,
        required=True,
        help='Prepared Wallet Core include/TrustWalletCore directory',
    )
    ap.add_argument('--output', type=Path, required=True)
    ap.add_argument('--clang', default='clang')
    ap.add_argument('--version', default='0.0.0')
    ap.add_argument('-I', '--include', action='append', default=[], type=Path)
    args = ap.parse_args()
    if not args.headers.is_dir():
        ap.error('Header directory not found')
    functions, enums, errors = parse_headers(args.headers, args.clang, [args.headers, *args.include])
    classes = set(enums) | {h.stem[2:] for h in args.headers.glob('TW*.h')}
    destructors = valid_destructors(functions)
    status = {}
    reserved = defaultdict(set)
    for name, f in sorted(functions.items()):
        info, why = classify(f, classes, destructors)
        if info:
            key = info['owner']
            short = info['method'].lower()
            if short in reserved[key]:
                info, why = None, 'case-insensitive PHP method collision'
            else:
                reserved[key].add(short)
        status[name] = (
            {'status': 'generated', **info}
            if info else {'status': 'excluded', 'reason': why}
        )
        status[name]['header'] = f['header']
    errors.extend(php_api_errors(functions, enums, status))
    args.output.mkdir(parents=True, exist_ok=True)
    report = {
        'generator': 'clang-zend',
        'parsed_header_count': len(list(args.headers.glob('*.h'))),
        'parsed_functions': len(functions),
        'header_errors': errors,
        'functions': status,
    }
    (args.output / 'abi.json').write_text(json.dumps(report, indent=2, sort_keys=True) + '\n')
    if errors or not functions:
        for stale in ('walletcore.c', 'walletcore.stub.php'):
            (args.output/stale).unlink(missing_ok=True)
        print(f'ERROR: {len(errors)} ABI generation errors; {len(functions)} declarations scanned. See {args.output}/abi.json', file=sys.stderr)
        sys.exit(2)
    emit(
        functions,
        enums,
        status,
        args.output,
        args.version,
        [p.name for p in args.headers.glob('*.h')],
        destructors,
    )
    print(
        'Generated:', sum(x['status'] == 'generated' for x in status.values()),
        'Excluded:', sum(x['status'] == 'excluded' for x in status.values()),
        'Headers:', len(list(args.headers.glob('*.h'))),
    )

if __name__ == '__main__':
    main()
