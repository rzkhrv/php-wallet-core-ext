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
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class KomodoTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Komodo;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('KMD', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Komodo);

        self::assertSame('RCWJLXE5CSXydxdSnwcghzPgkFswERegyb', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Komodo;
        $path = "m/44'/141'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('RCWJLXE5CSXydxdSnwcghzPgkFswERegyb', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Komodo;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/141'/0'/0/1.
        self::assertSame('155879f874b29e9392e44425db316e4f6a50ee1ab4b0004398c81f6615eec834', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('RWiFLZLxCsSJKRwFX4jp4nukBU8YDwLFy1', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('RCWJLXE5CSXydxdSnwcghzPgkFswERegyb', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Komodo;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('RCWJLXE5CSXydxdSnwcghzPgkFswERegyb', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Komodo;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/141'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('RCWJLXE5CSXydxdSnwcghzPgkFswERegyb', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Komodo;
        $expected = 'RCWJLXE5CSXydxdSnwcghzPgkFswERegyb';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Komodo);

        self::assertSame($expected, $valid);
    }

    public function testPlanTransaction(): void
    {
        $coin = CoinType::Komodo;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'RCWJLXE5CSXydxdSnwcghzPgkFswERegyb';
        $key = $wallet->getKey($coin, "m/44'/141'/0'/0/0");
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
        $coin = CoinType::Komodo;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'RCWJLXE5CSXydxdSnwcghzPgkFswERegyb';
        $key = $wallet->getKey($coin, "m/44'/141'/0'/0/0");
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
            'valid address' => ['RCWJLXE5CSXydxdSnwcghzPgkFswERegyb', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
