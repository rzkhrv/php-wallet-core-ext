<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Polkadot\Proto as PolkadotProto;
use TW\Polymesh\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class PolymeshTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Polymesh;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('POLYX', $symbol);
        self::assertSame(6, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Polymesh);

        self::assertSame('2DHK8VhBpacs9quk78AVP9TmmcG5iXi2oKtZqneSNsVXxCKw', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Polymesh;
        $path = "m/44'/595'/0'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('2DHK8VhBpacs9quk78AVP9TmmcG5iXi2oKtZqneSNsVXxCKw', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Polymesh;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/595'/0'/0'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('2DHK8VhBpacs9quk78AVP9TmmcG5iXi2oKtZqneSNsVXxCKw', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Polymesh;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('2DHK8VhBpacs9quk78AVP9TmmcG5iXi2oKtZqneSNsVXxCKw', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Polymesh;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/595'/0'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('2DHK8VhBpacs9quk78AVP9TmmcG5iXi2oKtZqneSNsVXxCKw', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Polymesh;
        $expected = '2DHK8VhBpacs9quk78AVP9TmmcG5iXi2oKtZqneSNsVXxCKw';

        $address = AnyAddress::createSS58($expected, $coin, CoinType::sS58Prefix($coin));

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Polymesh);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Polymesh;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/595'/0'/0'/0'");
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
            'network' => 12,
            'block_hash' => $hash,
            'genesis_hash' => $hash,
            'nonce' => '1',
            'spec_version' => 1_000,
            'transaction_version' => 1,
            'era' => new PolkadotProto\Era([
                'block_number' => '1000000',
                'period' => '64',
            ]),
            'runtime_call' => new Proto\RuntimeCall([
                'balance_call' => new Proto\Balance([
                    'transfer' => new Proto\Balance\Transfer([
                        'to_address' => $recipient,
                        'value' => ltrim(pack('J', 1_000_000), "\0"),
                        // Illustrative runtime call indices; obtain actual indices from chain metadata.
                        'call_indices' => new PolkadotProto\CallIndices([
                            'custom' => new PolkadotProto\CustomCallIndices([
                                'module_index' => 5,
                                'method_index' => 1,
                            ]),
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
            'valid address' => ['2DHK8VhBpacs9quk78AVP9TmmcG5iXi2oKtZqneSNsVXxCKw', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
