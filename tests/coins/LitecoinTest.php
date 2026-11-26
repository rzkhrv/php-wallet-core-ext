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
use WalletCore\Derivation;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class LitecoinTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Litecoin;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('LTC', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Litecoin);

        self::assertSame('ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Litecoin;
        $path = "m/84'/2'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Litecoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/84'/2'/0'/0/1.
        self::assertSame('21628734ebd72524a76c8ed7917a2926e95ad691d7c0a17c07e30b835092d0f0', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('ltc1qlmayxrh3dpxyxssynspqj7s3ysgctmh32qs53h', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Litecoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Litecoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/84'/2'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Litecoin;
        $expected = 'ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Litecoin);

        self::assertSame($expected, $valid);
    }

    public function testDerivationLitecoinLegacy(): void
    {
        $coin = CoinType::Litecoin;
        $derivation = Derivation::LitecoinLegacy;
        $path = "m/44'/2'/0'/0/0";
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, $path);
        $namedKey = $wallet->getKeyDerivation($coin, $derivation);
        self::assertNotNull($key);
        self::assertNotNull($namedKey);
        self::assertSame($key->data(), $namedKey->data());
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = $wallet->getAddressDerivation($coin, $derivation);

        self::assertSame($path, CoinType::derivationPathWithDerivation($coin, $derivation));
        self::assertNotEmpty($address);
        self::assertSame($address, CoinType::deriveAddressFromPublicKeyAndDerivation($coin, $publicKey, $derivation));
        self::assertNotSame('ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u', $address);
    }

    public function testPlanTransaction(): void
    {
        $coin = CoinType::Litecoin;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u';
        $key = $wallet->getKey($coin, "m/84'/2'/0'/0/0");
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

    public function testSignTransaction(): void
    {
        $coin = CoinType::Litecoin;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u';
        $key = $wallet->getKey($coin, "m/84'/2'/0'/0/0");
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
            'valid address' => ['ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0u', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['ltc1qhd8fxxp2dx3vsmpac43z6ev0kllm4n53t5sk0q', false],
        ];
    }
}
