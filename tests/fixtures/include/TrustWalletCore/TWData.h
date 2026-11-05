#pragma once
#include "TWBase.h"
typedef const void TWData;
typedef const void TWString;
TWData * TWDataCreateWithBytes(const uint8_t * bytes, size_t len);
uint8_t * TWDataBytes(TWData * data);
size_t TWDataSize(TWData * data);
void TWDataDelete(TWData * data);
TWString * TWStringCreateWithRawBytes(const uint8_t * bytes, size_t len);
const char * TWStringUTF8Bytes(TWString * data);
size_t TWStringSize(TWString * data);
void TWStringDelete(TWString * data);
