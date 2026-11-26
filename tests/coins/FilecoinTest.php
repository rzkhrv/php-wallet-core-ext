<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Filecoin\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\FilecoinAddressConverter;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class FilecoinTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Filecoin;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('FIL', $symbol);
        self::assertSame(18, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Filecoin);

        self::assertSame('f1zzykebxldfcakj5wdb5n3n7priul522fnmjzori', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Filecoin;
        $path = "m/44'/461'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('f1zzykebxldfcakj5wdb5n3n7priul522fnmjzori', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Filecoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/461'/0'/0/1.
        self::assertSame('8b4ad35e5943b49ba5987293b2d34195a1125d8affa0cc328f19212120655fe8', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('f1xa6s7wz6bbm2hmajmuxyuvey3c4mcdu53utpoei', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('f1zzykebxldfcakj5wdb5n3n7priul522fnmjzori', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Filecoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('f1zzykebxldfcakj5wdb5n3n7priul522fnmjzori', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Filecoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/461'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('f1zzykebxldfcakj5wdb5n3n7priul522fnmjzori', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Filecoin;
        $expected = 'f1zzykebxldfcakj5wdb5n3n7priul522fnmjzori';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Filecoin);

        self::assertSame($expected, $valid);
    }

    public function testConvertToEthereumAddress(): void
    {
        // Wallet Core: Filecoin/TWAddressConverterTests.cpp.
        $address = 'f410frw6wy7w6sbsguyn3yzeygg34fgf72n5ao5sxyky';

        $converted = FilecoinAddressConverter::convertToEthereum($address);

        self::assertSame('0x8dbD6c7Ede90646a61Bbc649831b7c298BFd37A0', $converted);
    }

    public function testConvertFromEthereumAddress(): void
    {
        $address = '0x8dbD6c7Ede90646a61Bbc649831b7c298BFd37A0';

        $converted = FilecoinAddressConverter::convertFromEthereum($address);

        self::assertSame('f410frw6wy7w6sbsguyn3yzeygg34fgf72n5ao5sxyky', $converted);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Filecoin;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/461'/0'/0/0");
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
            'to' => $recipient,
            'nonce' => '0',
            'value' => ltrim(pack('J', 1_000_000), "\0"),
            'gas_limit' => '1000000',
            'gas_fee_cap' => pack('n', 1_000),
            'gas_premium' => pack('C', 100),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getJson());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['f1zzykebxldfcakj5wdb5n3n7priul522fnmjzori', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
