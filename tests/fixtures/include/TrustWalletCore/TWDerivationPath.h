#pragma once
#include "TWDerivationPathIndex.h"
struct TWDerivationPath;
TW_EXPORT_METHOD
void TWDerivationPathDelete(struct TWDerivationPath * _Nonnull path);
TW_EXPORT_METHOD
struct TWDerivationPathIndex * _Nullable TWDerivationPathIndexAt(struct TWDerivationPath * _Nonnull path, uint32_t index);
