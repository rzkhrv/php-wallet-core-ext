#pragma once
#include "TWData.h"
// A zero-argument static unsigned-return function exercises the generator's
// error path for a value greater than PHP_INT_MAX (even when there are no
// temporary native inputs that ordinarily require a cleanup label).
TW_EXPORT_STATIC_METHOD
uint64_t TWCounterCount(void);
