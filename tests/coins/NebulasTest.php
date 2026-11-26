<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Nebulas\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class NebulasTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Nebulas;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('NAS', $symbol);
        self::assertSame(18, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Nebulas);

        self::assertSame('n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Nebulas;
        $path = "m/44'/2718'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Nebulas;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Nebulas;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Nebulas;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/2718'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Nebulas;
        $expected = 'n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Nebulas);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Nebulas;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ';
        $key = $wallet->getKey($coin, "m/44'/2718'/0'/0/0");
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
            'from_address' => $sender,
            'to_address' => $recipient,
            'chain_id' => pack('C', 1),
            'nonce' => pack('C', 1),
            'gas_price' => ltrim(pack('J', 1_000_000), "\0"),
            'gas_limit' => pack('n', 20_000),
            'amount' => ltrim(pack('J', 1_000_000), "\0"),
            'timestamp' => pack('N', 1_700_000_000),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getRaw());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['n1ZVgEidtdseYv9ogmGz69Cz4mbqmHYSNqJ', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
