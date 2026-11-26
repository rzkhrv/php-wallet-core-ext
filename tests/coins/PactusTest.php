<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Pactus\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\Derivation;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class PactusTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Pactus;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('PAC', $symbol);
        self::assertSame(9, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Pactus);

        self::assertSame('pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Pactus;
        $path = "m/44'/21888'/3'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Pactus;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/21888'/3'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Pactus;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Pactus;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/21888'/3'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Pactus;
        $expected = 'pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Pactus);

        self::assertSame($expected, $valid);
    }

    #[DataProvider('derivationProvider')]
    public function testNamedDerivation(
        int $derivation,
        string $path,
        string $defaultAddress,
        bool $matchesDefaultAddress,
    ): void {
        $coin = CoinType::Pactus;
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
        self::assertSame($matchesDefaultAddress, $address === $defaultAddress);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Pactus;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg';
        $key = $wallet->getKey($coin, "m/44'/21888'/3'/0'");
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
            'transaction' => new Proto\TransactionMessage([
                'lock_time' => 1_000_000,
                'fee' => '1000',
                'transfer' => new Proto\TransferPayload([
                    'sender' => $sender,
                    'receiver' => $recipient,
                    'amount' => '1000000',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getSignedTransactionData());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }

    public static function derivationProvider(): array
    {
        return [
            'PactusMainnet' => [
                Derivation::PactusMainnet,
                "m/44'/21888'/3'/0'",
                'pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg',
                true,
            ],
            'PactusTestnet' => [
                Derivation::PactusTestnet,
                "m/44'/21777'/3'/0'",
                'pc1r7ys2g5a4xc2qtm0t4q987m4mvs57w5g0v4pvzg',
                false,
            ],
        ];
    }
}
