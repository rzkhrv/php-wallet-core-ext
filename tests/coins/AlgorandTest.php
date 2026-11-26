<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Algorand\Proto;
use TW\Common\Proto\SigningError;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base64;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class AlgorandTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Algorand;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('ALGO', $symbol);
        self::assertSame(6, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Algorand);

        self::assertSame('JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67BQ', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Algorand;
        $path = "m/44'/283'/0'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67BQ', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Algorand;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/283'/0'/0'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67BQ', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Algorand;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67BQ', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Algorand;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/283'/0'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67BQ', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Algorand;
        $expected = 'JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67BQ';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Algorand);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Algorand;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/283'/0'/0'/0'");
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
            'genesis_id' => 'mainnet-v1.0',
            'genesis_hash' => $hash,
            'first_round' => '1000000',
            'last_round' => '1001000',
            'fee' => '1000',
            'transfer' => new Proto\Transfer([
                'to_address' => $recipient,
                'amount' => '1000000',
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
        // Wallet Core 4.8.7: tests/chains/Algorand/SignerTests.cpp: ProtoSignerAssetTransaction
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('5a6a3cfe5ff4cc44c19381d15a0d16de2a76ee5c9b9d83b232e38cb5a2c84b04'),
            'genesis_id' => 'testnet-v1.0',
            'genesis_hash' => Base64::decode('SGO1GKSzyE7IEPItTxCByw9x8FmnrCDexi9/cOUJOiI='),
            'first_round' => 15_775_683,
            'last_round' => 15_776_683,
            'fee' => 2_340,
            'asset_transfer' => new Proto\AssetTransfer([
                'asset_id' => 13_379_146,
                'amount' => 1_000_000,
                'to_address' => 'GJIWJSX2EU5RC32LKTDDXWLA2YICBHKE35RV2ZPASXZYKWUWXFLKNFSS4U',
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Algorand);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '82a3736967c440412720eff99a17280a437bdb8eeba7404b855d6433fffd5dde7f7966c1f9ae531a1af39e18b8a58b4a6c6acb709cca92f8a18c36d8328be9520c915311027005a374786e8aa461616d74ce000f4240a461726376c420325164cafa253b116f4b54c63bd960d610209d44df635d65e095f3855a96b956a3666565cd0924a26676ce00f0b7c3a367656eac746573746e65742d76312e30a26768c4204863b518a4b3c84ec810f22d4f1081cb0f71f059a7ac20dec62f7f70e5093a22a26c76ce00f0bbaba3736e64c42082872d60c338cb928006070e02ec0942addcb79e7fbd01c76458aea526899bd3a474797065a56178666572a478616964ce00cc264a',
            bin2hex($output->getEncoded()),
        );
    }

    public function testAssetOptIn(): void
    {
        // Upstream tests/chains/Algorand/SignerTests.cpp: ProtoSignerOptIn.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('5a6a3cfe5ff4cc44c19381d15a0d16de2a76ee5c9b9d83b232e38cb5a2c84b04'),
            'genesis_id' => 'testnet-v1.0',
            'genesis_hash' => Base64::decode('SGO1GKSzyE7IEPItTxCByw9x8FmnrCDexi9/cOUJOiI='),
            'first_round' => 15_775_553,
            'last_round' => 15_776_553,
            'fee' => 2_340, // microALGO
            'asset_opt_in' => new Proto\AssetOptIn(['asset_id' => 13379146]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Algorand);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '82a3736967c440f3a29d9a40271c00b542b38ab2ccb4967015ae6609368d4b8eb2f5e2b5348577cf9e0f62b0777ccb2d8d9b943b15c24c0cf1db312cb01a3c198d9d9c6c5bb00ba374786e89a461726376c42082872d60c338cb928006070e02ec0942addcb79e7fbd01c76458aea526899bd3a3666565cd0924a26676ce00f0b741a367656eac746573746e65742d76312e30a26768c4204863b518a4b3c84ec810f22d4f1081cb0f71f059a7ac20dec62f7f70e5093a22a26c76ce00f0bb29a3736e64c42082872d60c338cb928006070e02ec0942addcb79e7fbd01c76458aea526899bd3a474797065a56178666572a478616964ce00cc264a',
            bin2hex($output->getEncoded()),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67BQ', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['JTJWO524JXIHVPGBDWFLJE7XUIA32ECOZOBLF2QP3V5TQBT3NKZSCG67AQ', false],
        ];
    }
}
