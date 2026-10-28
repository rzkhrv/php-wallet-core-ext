#pragma once
#include "TWStoredKeyEncryption.h"
#include "TWHDWallet.h"
#include "TWAccount.h"
#include "TWDerivation.h"
struct TWStoredKey;
TW_EXPORT_METHOD
void TWStoredKeyDelete(struct TWStoredKey * _Nonnull key);
TW_EXPORT_PROPERTY
TWString * _Nullable TWStoredKeyEncryptionParameters(struct TWStoredKey * _Nonnull key);

// Wallet Core signatures with nullable native object inputs. A Zend object
// with ptr == NULL is not the same thing as an explicitly passed PHP null.
TW_EXPORT_METHOD
struct TWAccount * _Nullable TWStoredKeyAccountForCoin(struct TWStoredKey * _Nonnull key, enum TWCoinType coin, struct TWHDWallet * _Nullable wallet);
TW_EXPORT_METHOD
struct TWAccount * _Nullable TWStoredKeyAccountForCoinDerivation(struct TWStoredKey * _Nonnull key, enum TWCoinType coin, enum TWDerivation derivation, struct TWHDWallet * _Nullable wallet);
