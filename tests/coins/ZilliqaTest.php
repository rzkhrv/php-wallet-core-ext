<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Zilliqa\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class ZilliqaTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Zilliqa;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('ZIL', $symbol);
        self::assertSame(12, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Zilliqa);

        self::assertSame('zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Zilliqa;
        $path = "m/44'/313'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Zilliqa;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Zilliqa;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Zilliqa;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/313'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Zilliqa;
        $expected = 'zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Zilliqa);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Zilliqa;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/313'/0'/0/0");
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

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'version' => 65_537,
            'nonce' => '1',
            'to' => $recipient,
            'gas_price' => pack('N', 2_000_000_000),
            'gas_limit' => '50',
            'transaction' => new Proto\Transaction([
                'transfer' => new Proto\Transaction\Transfer([
                    'amount' => ltrim(pack('J', 1_000_000), "\0"),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getSignature());
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: src/proto/Zilliqa.proto: Transaction.Raw (ZRC-2 Transfer payload)
        $coin = CoinType::Zilliqa;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6';
        $key = $wallet->getKey($coin, "m/44'/313'/0'/0/0");
        self::assertNotNull($key);

        $privateKey = $key->data();
        self::assertNotNull($privateKey);

        // Synthetic ZRC-2 contract and explicit Scilla Transfer invocation.
        $payload = json_encode([
            '_tag' => 'Transfer',
            'params' => [
                ['vname' => 'to', 'type' => 'ByStr20', 'value' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84'],
                ['vname' => 'amount', 'type' => 'Uint128', 'value' => '1'],
            ],
        ], JSON_THROW_ON_ERROR);
        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'version' => 65_537,
            'nonce' => '1',
            'to' => $sender,
            'gas_price' => pack('N', 2_000_000_000),
            'gas_limit' => '10000',
            'transaction' => new Proto\Transaction([
                'raw_transaction' => new Proto\Transaction\Raw([
                    'amount' => pack('C', 0),
                    'data' => $payload,
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getSignature());
        $transaction = json_decode($output->getJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload, $transaction['data']);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['zil1mk6pqphhkmaguhalq6n3cq0h38ltcehg0rfmv6', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
