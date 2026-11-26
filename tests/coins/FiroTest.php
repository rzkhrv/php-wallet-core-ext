<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Bitcoin\Proto;
use TW\Common\Proto\SigningError;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\BitcoinScript;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\FiroAddressType;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;
use WalletCore\PublicKey;
use WalletCore\PublicKeyType;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class FiroTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Firo;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('FIRO', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Firo);

        self::assertSame('aEd5XFChyXobvEics2ppAqgK3Bgusjxtik', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Firo;
        $path = "m/44'/136'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('aEd5XFChyXobvEics2ppAqgK3Bgusjxtik', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Firo;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/136'/0'/0/1.
        self::assertSame('9262da2532eec370d9cbda5876d0a2310b29ee2bcbabf9501c7aa2ed6eff97e5', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('aDNjmkYATkMGa4sJDiaSpDjRZUKuqVaV5m', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('aEd5XFChyXobvEics2ppAqgK3Bgusjxtik', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Firo;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('aEd5XFChyXobvEics2ppAqgK3Bgusjxtik', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Firo;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/136'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('aEd5XFChyXobvEics2ppAqgK3Bgusjxtik', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Firo;
        $expected = 'aEd5XFChyXobvEics2ppAqgK3Bgusjxtik';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Firo);

        self::assertSame($expected, $valid);
    }

    public function testPlanTransaction(): void
    {
        $coin = CoinType::Firo;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'aEd5XFChyXobvEics2ppAqgK3Bgusjxtik';
        $key = $wallet->getKey($coin, "m/44'/136'/0'/0/0");
        self::assertNotNull($key);

        $recipientWallet = HDWallet::createWithMnemonic(
            'legal winner thank year wave sausage worth useful legal winner thank yellow',
            '',
        );
        self::assertNotNull($recipientWallet);
        $recipient = $recipientWallet->getAddressForCoin($coin);
        self::assertNotNull($recipient);
        $privateKey = $key->data();
        self::assertNotNull($privateKey);

        $hash = str_repeat("\x11", 32);
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);

        $input = new Proto\SigningInput([
            'private_key' => [$privateKey],
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'amount' => '100000',
            'byte_fee' => '2',
            'to_address' => $recipient,
            'change_address' => $sender,
            'utxo' => [
                new Proto\UnspentTransaction([
                    // Synthetic previous transaction hash (wire byte order).
                    'out_point' => new Proto\OutPoint([
                        'hash' => $hash,
                        'index' => 0,
                        'sequence' => 4_294_967_295,
                    ]),
                    'amount' => '1000000',
                    'script' => $script->data(),
                ]),
            ],
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);

        self::assertSame(SigningError::OK, $plan->getError());
        self::assertSame(100000, (int) $plan->getAmount());
        self::assertSame(1000000, (int) $plan->getAvailableAmount());
        self::assertGreaterThan(0, (int) $plan->getFee());
        self::assertSame(1000000, (int) $plan->getAmount() + (int) $plan->getFee() + (int) $plan->getChange());
        self::assertGreaterThan(0, (int) $plan->getChange());
        self::assertCount(1, $plan->getUtxos());
        $selected = $plan->getUtxos()[0];
        self::assertSame(1000000, (int) $selected->getAmount());
        self::assertSame($hash, $selected->getOutPoint()->getHash());
        self::assertSame(0, $selected->getOutPoint()->getIndex());
    }

    public function testCreateExchangeAddress(): void
    {
        // Wallet Core: TWFiroAddressTests.cpp.
        $key = PublicKey::createWithData(hex2bin('034cc1963365aa67d35643f419d6601eca6ef7f62e46bf7f8b6ffa64e2f44fd0bf'), PublicKeyType::SECP256k1);
        self::assertNotNull($key);

        $address = AnyAddress::createWithPublicKeyFiroAddressType($key, FiroAddressType::Exchange);

        self::assertNotNull($address);
        self::assertSame('EXXWKhUtcaFKVW1NeRFuqPq33zAJMtQJwR4y', $address->description());
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Firo;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'aEd5XFChyXobvEics2ppAqgK3Bgusjxtik';
        $key = $wallet->getKey($coin, "m/44'/136'/0'/0/0");
        self::assertNotNull($key);

        $recipientWallet = HDWallet::createWithMnemonic(
            'legal winner thank year wave sausage worth useful legal winner thank yellow',
            '',
        );
        self::assertNotNull($recipientWallet);
        $recipient = $recipientWallet->getAddressForCoin($coin);
        self::assertNotNull($recipient);
        $privateKey = $key->data();
        self::assertNotNull($privateKey);

        $hash = str_repeat("\x11", 32);
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);

        $input = new Proto\SigningInput([
            'private_key' => [$privateKey],
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'amount' => '100000',
            'byte_fee' => '2',
            'to_address' => $recipient,
            'change_address' => $sender,
            'utxo' => [
                new Proto\UnspentTransaction([
                    // Synthetic previous transaction hash (wire byte order).
                    'out_point' => new Proto\OutPoint([
                        'hash' => $hash,
                        'index' => 0,
                        'sequence' => 4_294_967_295,
                    ]),
                    'amount' => '1000000',
                    'script' => $script->data(),
                ]),
            ],
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);
        self::assertSame(SigningError::OK, $plan->getError());
        $input->setPlan($plan);

        $recipientScript = BitcoinScript::lockScriptForAddress($recipient, $coin);
        self::assertNotNull($recipientScript);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
        self::assertMatchesRegularExpression('/^[0-9a-fA-F]{64}$/', $output->getTransactionId());
        $transaction = $output->getTransaction();
        self::assertNotNull($transaction);
        self::assertCount(1, $transaction->getInputs());
        self::assertCount(2, $transaction->getOutputs());
        self::assertSame(
            ['value' => 100000, 'script' => $recipientScript->data()],
            ['value' => (int) $transaction->getOutputs()[0]->getValue(), 'script' => $transaction->getOutputs()[0]->getScript()],
        );
        self::assertSame(
            ['value' => (int) $plan->getChange(), 'script' => $script->data()],
            ['value' => (int) $transaction->getOutputs()[1]->getValue(), 'script' => $transaction->getOutputs()[1]->getScript()],
        );
        $change = (int) $transaction->getOutputs()[1]->getValue();
        self::assertGreaterThan(0, $change);
        self::assertGreaterThan(0, 1000000 - 100000 - $change);
        self::assertSame((int) $plan->getFee(), 1000000 - 100000 - $change);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['aEd5XFChyXobvEics2ppAqgK3Bgusjxtik', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
