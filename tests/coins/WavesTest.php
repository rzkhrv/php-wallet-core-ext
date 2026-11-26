<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Waves\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base58;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class WavesTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Waves;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('WAVES', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Waves);

        self::assertSame('3P63vkaHhyE9pPv9EfsjwGKqmZYcCRHys4n', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Waves;
        $path = "m/44'/5741564'/0'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('3P63vkaHhyE9pPv9EfsjwGKqmZYcCRHys4n', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Waves;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/5741564'/0'/0'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('3P63vkaHhyE9pPv9EfsjwGKqmZYcCRHys4n', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Waves;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('3P63vkaHhyE9pPv9EfsjwGKqmZYcCRHys4n', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Waves;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/5741564'/0'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('3P63vkaHhyE9pPv9EfsjwGKqmZYcCRHys4n', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Waves;
        $expected = '3P63vkaHhyE9pPv9EfsjwGKqmZYcCRHys4n';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Waves);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Waves;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/5741564'/0'/0'/0'");
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
            'timestamp' => '1700000000000',
            'transfer_message' => new Proto\TransferMessage([
                'to' => $recipient,
                'amount' => '1000000',
                'fee' => '100000',
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
        // Wallet Core 4.8.7: tests/chains/Waves/TWAnySignerTests.cpp: Sign
        $privateKey = Base58::decodeNoCheck('83mqJpmgB5Mko1567sVAdqZxVKsT6jccXt3eFSi4G1zE');
        self::assertNotNull($privateKey);
        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'timestamp' => 1_559_146_613,
            'transfer_message' => new Proto\TransferMessage([
                'amount' => 100_000_000,
                'asset' => 'DacnEpaUVFRCYk8Fcd1F3cqUZuT4XG7qW9mRyoZD81zq',
                'fee' => 100_000,
                'fee_asset' => 'DacnEpaUVFRCYk8Fcd1F3cqUZuT4XG7qW9mRyoZD81zq',
                'to' => '3PPCZQkvdMJpmx7Zrz1cnYsPe9Bt1XT2Ckx',
                'attachment' => 'hello',
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Waves);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '5d6a77b1fd9b53d9735cd2543ba94215664f2b07d6c7befb081221fcd49f5b6ad6b9ac108582e8d3e74943bdf35fd80d985edf4b4de1fb1c5c427e84d0879f8f',
            bin2hex($output->getSignature()),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['3P63vkaHhyE9pPv9EfsjwGKqmZYcCRHys4n', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
