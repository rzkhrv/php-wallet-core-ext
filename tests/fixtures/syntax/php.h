/* TEST-ONLY Zend signatures for C syntax checking; not real PHP development headers. */
#pragma once
#include <stddef.h>
#include <stdint.h>
#include <stdarg.h>
#include <limits.h>
#include <string.h>
typedef long zend_long;
typedef struct _zend_class_entry zend_class_entry;
typedef struct _zend_object_handlers zend_object_handlers;
typedef struct _zend_object { zend_object_handlers *handlers; zend_class_entry *ce; } zend_object;
typedef struct _zend_string { char *val; size_t len; } zend_string;
typedef struct _zval { int type; zend_long lval; zend_object *obj; zend_string *str; } zval;
typedef struct _zend_function_entry { const char *name; } zend_function_entry;
struct _zend_class_entry { zend_object *(*create_object)(zend_class_entry *); uint32_t ce_flags; };
struct _zend_object_handlers { size_t offset; void (*free_obj)(zend_object*); zend_object *(*clone_obj)(zend_object*); };
typedef struct { int dummy; } zend_module_entry;
extern zend_class_entry *zend_ce_exception;
#define SUCCESS 0
#define IS_NULL 1
#define IS_LONG 4
#define IS_TRUE 6
#define IS_FALSE 7
#define IS_STRING 8
#define IS_OBJECT 9
#define ZVAL_DEREF(z) do {} while(0)
#define ZVAL_STR(z,s) do { (void)(z); (void)(s); } while(0)
#define ZEND_LONG_MAX LONG_MAX
#define ZEND_LONG_MIN LONG_MIN
#define ZEND_ACC_PUBLIC 1
#define ZEND_ACC_STATIC 2
#define ZEND_ACC_FINAL 4
#define XtOffsetOf(s,m) offsetof(s,m)
#define Z_TYPE_P(z) ((z)->type)
#define Z_OBJ_P(z) ((z)->obj)
#define Z_OBJCE_P(z) (Z_OBJ_P(z)->ce)
#define Z_STRVAL_P(z) ((z)->str->val)
#define Z_STRLEN_P(z) ((z)->str->len)
#define Z_LVAL_P(z) ((z)->lval)
#define RETVAL_NULL() do {} while(0)
#define RETVAL_BOOL(v) do { (void)(v); } while(0)
#define RETVAL_LONG(v) do { (void)(v); } while(0)
#define RETVAL_STRINGL(v,n) do { (void)(v); (void)(n); } while(0)
#define PHP_METHOD(c,m) void zim_##c##_##m(zval *return_value, zval *this_ptr)
#define PHP_MINIT_FUNCTION(m) int zm_startup_##m(void)
#define PHP_MSHUTDOWN_FUNCTION(m) int zm_shutdown_##m(void)
#define PHP_MSHUTDOWN(m) zm_shutdown_##m
#define PHP_MINFO_FUNCTION(m) void zm_info_##m(void)
#define PHP_MINIT(m) zm_startup_##m
#define PHP_MINFO(m) zm_info_##m
#define ZEND_GET_MODULE(m) void *get_module(void) { return &m##_module_entry; }
#define STANDARD_MODULE_HEADER 0
#define STANDARD_MODULE_PROPERTIES 0
#define getThis() (this_ptr)
#define ZEND_PARSE_PARAMETERS_START(a,b) do {
#define Z_PARAM_ZVAL(v) (v) = (zval*)0;
#define ZEND_PARSE_PARAMETERS_END() } while(0);
#define ZEND_PARSE_PARAMETERS_NONE() do {} while(0)
#define ZEND_BEGIN_ARG_INFO_EX(name, ...) static int name[] = {
#define ZEND_ARG_INFO(pass,name) 0,
#define ZEND_END_ARG_INFO() 0 };
#define PHP_ME(c,m,a,f) {#m},
#define PHP_FE_END {0}
#define INIT_NS_CLASS_ENTRY(ce,ns,name,methods) do {} while (0)
void *zend_object_alloc(size_t, zend_class_entry *);
void zend_object_std_init(zend_object*, zend_class_entry*);
void object_properties_init(zend_object*,zend_class_entry*);
void zend_object_std_dtor(zend_object*);
zend_object_handlers *zend_get_std_object_handlers(void);
zend_class_entry *zend_register_internal_class(zend_class_entry*);
void zend_declare_class_constant_long(zend_class_entry*, const char*, size_t, zend_long);
void object_init_ex(zval*,zend_class_entry*);
void zend_throw_exception(zend_class_entry*,const char*,zend_long);
void zend_type_error(const char*,...);
void zend_value_error(const char*,...);
int instanceof_function(const zend_class_entry*,const zend_class_entry*);

void *emalloc(size_t);
void efree(void *);
zend_string *zend_string_init(const char *, size_t, int);
void zval_ptr_dtor(zval *);
