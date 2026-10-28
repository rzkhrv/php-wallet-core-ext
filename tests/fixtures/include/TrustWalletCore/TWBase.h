#pragma once
#include <stdint.h>
#include <stddef.h>
#include <stdbool.h>
#define TW_EXPORT_STATIC_METHOD extern
#define TW_EXPORT_METHOD extern
#define TW_EXPORT_PROPERTY extern
#define TW_EXPORT_STATIC_PROPERTY extern
#define TW_EXPORT_ENUM(type)
#ifndef __clang__
#define _Nonnull
#define _Nullable
#endif
