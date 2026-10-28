#pragma once
#include "TWData.h"
struct TWDataVector;
TW_EXPORT_STATIC_METHOD
struct TWDataVector * TWDataVectorCreate(void);
TW_EXPORT_METHOD
void TWDataVectorDelete(struct TWDataVector * vector);
TW_EXPORT_METHOD
void TWDataVectorAdd(struct TWDataVector * vector, TWData * data);
TW_EXPORT_METHOD
TWData * TWDataVectorGet(struct TWDataVector * vector, size_t index);
