<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Polkadot\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class AcalaTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Acala;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('ACA', $symbol);
        self::assertSame(12, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Acala);

        self::assertSame('25GGezx3LWFQj6HZpYzoWoVzLsHojGtybef3vthC9nd19ms3', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Acala;
        $path = "m/44'/787'/0'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('25GGezx3LWFQj6HZpYzoWoVzLsHojGtybef3vthC9nd19ms3', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Acala;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/787'/0'/0'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('25GGezx3LWFQj6HZpYzoWoVzLsHojGtybef3vthC9nd19ms3', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Acala;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('25GGezx3LWFQj6HZpYzoWoVzLsHojGtybef3vthC9nd19ms3', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Acala;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/787'/0'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('25GGezx3LWFQj6HZpYzoWoVzLsHojGtybef3vthC9nd19ms3', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Acala;
        $expected = '25GGezx3LWFQj6HZpYzoWoVzLsHojGtybef3vthC9nd19ms3';

        $address = AnyAddress::createSS58($expected, $coin, CoinType::sS58Prefix($coin));

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Acala);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Acala;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/787'/0'/0'/0'");
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

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'network' => 10,
            'block_hash' => $hash,
            'genesis_hash' => $hash,
            'nonce' => '1',
            'spec_version' => 1_000,
            'transaction_version' => 1,
            'era' => new Proto\Era([
                'block_number' => '1000000',
                'period' => '64',
            ]),
            'multi_address' => true,
            'balance_call' => new Proto\Balance([
                'transfer' => new Proto\Balance\Transfer([
                    'to_address' => $recipient,
                    'value' => ltrim(pack('J', 1_000_000), "\0"),
                    // Illustrative runtime call indices; obtain actual indices from chain metadata.
                    'call_indices' => new Proto\CallIndices([
                        'custom' => new Proto\CustomCallIndices([
                            'module_index' => 10,
                            'method_index' => 0,
                        ]),
                    ]),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['25GGezx3LWFQj6HZpYzoWoVzLsHojGtybef3vthC9nd19ms3', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
