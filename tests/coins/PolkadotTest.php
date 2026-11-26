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
use WalletCore\PublicKey;
use WalletCore\PublicKeyType;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class PolkadotTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Polkadot;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('DOT', $symbol);
        self::assertSame(10, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Polkadot);

        self::assertSame('13nN6BGAoJwd7Nw1XxeBCx5YcBXuYnL94Mh7i3xBprqVSsFk', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Polkadot;
        $path = "m/44'/354'/0'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('13nN6BGAoJwd7Nw1XxeBCx5YcBXuYnL94Mh7i3xBprqVSsFk', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Polkadot;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/354'/0'/0'/1'");
        self::assertNotNull($key);
        // Independent BIP39/SLIP-0010 reference: m/44'/354'/0'/0'/1'.
        self::assertSame('15805c1131e1d9122f7aa99d9dadcf5594522b2fe71168882ef4f8b17ccb043b', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('12w1cY6jhxBgLjAuzu8dfKEPAgxBDS7PJHC9yJqUSdhAR9ws', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('13nN6BGAoJwd7Nw1XxeBCx5YcBXuYnL94Mh7i3xBprqVSsFk', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Polkadot;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('13nN6BGAoJwd7Nw1XxeBCx5YcBXuYnL94Mh7i3xBprqVSsFk', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Polkadot;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/354'/0'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('13nN6BGAoJwd7Nw1XxeBCx5YcBXuYnL94Mh7i3xBprqVSsFk', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Polkadot;
        $expected = '13nN6BGAoJwd7Nw1XxeBCx5YcBXuYnL94Mh7i3xBprqVSsFk';

        $address = AnyAddress::createSS58($expected, $coin, CoinType::sS58Prefix($coin));

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Polkadot);

        self::assertSame($expected, $valid);
    }

    public function testImportPublicKey(): void
    {
        // Wallet Core: Polkadot/TWAnyAddressTests.cpp.
        $key = PublicKey::createWithData(hex2bin('beff0e5d6f6e6e6d573d3044f3e2bfb353400375dc281da3337468d4aa527908'), PublicKeyType::ED25519);
        self::assertNotNull($key);

        $address = AnyAddress::createSS58WithPublicKey($key, CoinType::Polkadot, 0);

        self::assertNotNull($address);
        self::assertSame('15KRsCq9LLNmCxNFhGk55s5bEyazKefunDxUH24GFZwsTxyu', $address->description());
        self::assertTrue(AnyAddress::isValidSS58($address->description(), CoinType::Polkadot, 0));
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Polkadot;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/354'/0'/0'/0'");
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
            'network' => 0,
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
                            'module_index' => 5,
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

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: rust/tw_tests/tests/chains/polkadot/polkadot_sign.rs: test_statemint_encode_transaction_usdt_transfer_keep_alive (adapted)
        $coin = CoinType::Polkadot;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/354'/0'/0'/0'");
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
            'network' => 0,
            'block_hash' => $hash,
            'genesis_hash' => $hash,
            'nonce' => '1',
            'spec_version' => 9_320,
            'transaction_version' => 9,
            'era' => new Proto\Era([
                'block_number' => '1000000',
                'period' => '64',
            ]),
            'multi_address' => true,
            'balance_call' => new Proto\Balance([
                'asset_transfer' => new Proto\Balance\AssetTransfer([
                    'asset_id' => 1_984,
                    'fee_asset_id' => 1_984,
                    'to_address' => $recipient,
                    'value' => ltrim(pack('J', 1_000_000), "\0"),
                    // Illustrative runtime call indices; obtain actual indices from chain metadata.
                    'call_indices' => new Proto\CallIndices([
                        'custom' => new Proto\CustomCallIndices([
                            'module_index' => 0x32,
                            'method_index' => 0x06,
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
        self::assertStringContainsString('3206', bin2hex($output->getEncoded()));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['13nN6BGAoJwd7Nw1XxeBCx5YcBXuYnL94Mh7i3xBprqVSsFk', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
