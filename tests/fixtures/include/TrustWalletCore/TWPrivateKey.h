#pragma once
#include "TWData.h"
TW_EXPORT_ENUM(uint32_t)
enum TWCoinType { TWCoinTypeBitcoin = 0, TWCoinTypeEthereum = 60 };
struct TWPrivateKey;
TW_EXPORT_STATIC_METHOD
struct TWPrivateKey * _Nullable TWPrivateKeyCreateWithData(TWData * _Nonnull data);
TW_EXPORT_METHOD
void TWPrivateKeyDelete(struct TWPrivateKey * _Nonnull key);
TW_EXPORT_PROPERTY
TWData * _Nonnull TWPrivateKeyData(struct TWPrivateKey * _Nonnull key);
TW_EXPORT_STATIC_METHOD
bool TWPrivateKeyIsValid(TWData * _Nonnull data, enum TWCoinType coin);
TW_EXPORT_STATIC_METHOD
TWData * _Nonnull TWPrivateKeySign(struct TWPrivateKey * _Nonnull key, TWData * _Nonnull digest);
TW_EXPORT_METHOD
bool TWCoinTypeIsEnabled(enum TWCoinType coin);
