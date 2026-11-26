<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Sui\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class SuiTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Sui;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('SUI', $symbol);
        self::assertSame(9, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Sui);

        self::assertSame('0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Sui;
        $path = "m/44'/784'/0'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Sui;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/784'/0'/0'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Sui;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Sui;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/784'/0'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Sui;
        $expected = '0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Sui);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Sui;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = '0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2';
        $key = $wallet->getKey($coin, "m/44'/784'/0'/0'/0'");
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
            'signer' => $sender,
            'gas_budget' => '10000000',
            'reference_gas_price' => '1000',
            'pay_sui' => new Proto\PaySui([
                'input_coins' => [
                    new Proto\ObjectRef([
                        'object_id' => '0x'.str_repeat('11',32),
                        'version' => '1',
                        'object_digest' => '11111111111111111111111111111111',
                    ]),
                ],
                'recipients' => [$recipient],
                'amounts' => ['1000000'],
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
        // Wallet Core 4.8.7: rust/tw_tests/tests/chains/sui/sui_sign.rs: test_sui_sign_transfer_token
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('7e6682f7bf479ef0f627823cffd4e1a940a7af33e5fb39d9e0f631d2ecc5daff'),
            'gas_budget' => 4_000_000,
            'reference_gas_price' => 750,
            'pay' => new Proto\Pay([
                // SUIA token object and separate SUI gas object.
                'input_coins' => [new Proto\ObjectRef([
                    'object_id' => '0x69e007e40d4b64528c3d9e519e3d1f3e5c9c962870748171f7b94c168c868161',
                    'version' => 85_619_063,
                    'object_digest' => 'HwJaXWsUXZnd1G8cQVtRmWvBvPKGAZMoTryoJqazNoNK',
                ])],
                'recipients' => ['0xa7175abdd5ed92ebe3ad390db366c6a706478cdf517cde6cf98630065cda377a'],
                'amounts' => [123000],
                'gas' => new Proto\ObjectRef([
                    'object_id' => '0x636020b3a7dc7b11c3aa6f419b17f8a9c12e7f79a31d1bdd2de670b4edd63005',
                    'version' => 85_619_065,
                    'object_digest' => '9zB7kRVtKQcxCfi47z22u6vQGcUtnYFBXeiuaDfHUudr',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Sui);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'AAADAQBp4AfkDUtkUow9nlGePR8+XJyWKHB0gXH3uUwWjIaBYXdxGgUAAAAAIPukOuVHEHFrj96jMXgsE8RmAU/Es2ejPAhD41bNXslEAAh44AEAAAAAAAAgpxdavdXtkuvjrTkNs2bGpwZHjN9RfN5s+YYwBlzaN3oCAgEAAAEBAQABAQMAAAAAAQIAVOgNdteQwnf1pE886S9T0m9YlIkr85Xe5jdZiIdr5rIBY2Ags6fcexHDqm9Bmxf4qcEuf3mjHRvdLeZwtO3WMAV5cRoFAAAAACCFgwpF3YPy9Px5R/K+WLDjMSiO7AsEa/4cNWn5P/nkIVToDXbXkMJ39aRPPOkvU9JvWJSJK/OV3uY3WYiHa+ay7gIAAAAAAAAACT0AAAAAAAA=',
            $output->getUnsignedTx(),
        );
        self::assertSame(
            'AAXK0so7TwO285ZhWKKRYs2MyFumsFSlOe4boampQKmrqhIZKhNnJKCTFEkarJTq5lIIvyTtgIu5S93gOsxN4guF69FEH+T5VPvl3GB3vwCOEZpeJpKXxvcIPQAdKsh2/g==',
            $output->getSignature(),
        );
    }

    public function testSignTokenFromAddressBalance(): void
    {
        // Upstream sui_sign_address_balance.rs (SIP-58); gas paid from the SUI address balance.

        $input = new Proto\SigningInput([
            'private_key' => hex2bin('7e6682f7bf479ef0f627823cffd4e1a940a7af33e5fb39d9e0f631d2ecc5daff'),
            'gas_budget' => 5_000_000,
            'reference_gas_price' => 100,
            'valid_during' => new Proto\ValidDuring([
                'min_epoch' => 1_288,
                'max_epoch' => 1_289,
                'chain' => '4btiuiMPvEENsttpZC7CZ53DruC3MAgfznDbASZ7DR6S',
                'nonce' => 12_345,
            ]),
            'pay' => new Proto\Pay([
                'recipients' => ['0xa7175abdd5ed92ebe3ad390db366c6a706478cdf517cde6cf98630065cda377a'],
                'amounts' => [7000000],
                'coin_type' => '0xdba34672e30cb065b1f93e3ab55318768fd6fef66c15942c9f7cb846e2f900e7::usdc::USDC',
                'address_balance_amount' => 7_000_000,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Sui);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'AAACAgDAz2oAAAAAAAAH26NGcuMMsGWx+T46tVMYdo/W/vZsFZQsn3y4RuL5AOcEdXNkYwRVU0RDAAAAIKcXWr3V7ZLr4605DbNmxqcGR4zfUXzebPmGMAZc2jd6AgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgRjb2luDHJlZGVlbV9mdW5kcwEH26NGcuMMsGWx+T46tVMYdo/W/vZsFZQsn3y4RuL5AOcEdXNkYwRVU0RDAAEBAAABAQIAAAEBAFToDXbXkMJ39aRPPOkvU9JvWJSJK/OV3uY3WYiHa+ayAFToDXbXkMJ39aRPPOkvU9JvWJSJK/OV3uY3WYiHa+ayZAAAAAAAAABAS0wAAAAAAAIBCAUAAAAAAAABCQUAAAAAAAAAACA1g0qKwXykj7FKyPmcF8mHR+ld0HKUrkGkazgiRqRJmzkwAAA=',
            $output->getUnsignedTx(),
        );
        self::assertSame(
            'AK5moGv2+NcANkUVDvQGsjNbUwYTP++hpPnJlnUgDhD7dfHkHOsv6SHj8Cwoh4ZbTJSQ8i8IYTISYaANmdQ7tQqF69FEH+T5VPvl3GB3vwCOEZpeJpKXxvcIPQAdKsh2/g==',
            $output->getSignature(),
        );
    }

    public function testSignStakeFromAddressBalance(): void
    {
        // Upstream sui_sign_address_balance.rs (SIP-58); gas paid from the SUI address balance.

        $input = new Proto\SigningInput([
            'private_key' => hex2bin('7e6682f7bf479ef0f627823cffd4e1a940a7af33e5fb39d9e0f631d2ecc5daff'),
            'gas_budget' => 5_000_000,
            'reference_gas_price' => 100,
            'valid_during' => new Proto\ValidDuring([
                'min_epoch' => 1_288,
                'max_epoch' => 1_289,
                'chain' => '4btiuiMPvEENsttpZC7CZ53DruC3MAgfznDbASZ7DR6S',
                'nonce' => 12_345,
            ]),
            'request_add_stake' => new Proto\RequestAddStake([
                'amount' => new Proto\Amount(['amount' => 1000000000]),
                'validator' => '0x61953ea72709eed72f4441dd944eec49a11b4acabfc8e04015e89c63be81b6ab',
                'address_balance_amount' => 1_000_000_000,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Sui);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'AAAEAQEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABQEAAAAAAAAAAQIAAMqaOwAAAAAABwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACA3N1aQNTVUkAAAAJAQDKmjsAAAAAACBhlT6nJwnu1y9EQd2UTuxJoRtKyr/I4EAV6JxjvoG2qwMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIEY29pbgxyZWRlZW1fZnVuZHMBBwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACA3N1aQNTVUkAAQEBAAUAAQIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwpzdWlfc3lzdGVtGnJlcXVlc3RfYWRkX3N0YWtlX211bF9jb2luAAQBAAACAQABAgABAwBU6A1215DCd/WkTzzpL1PSb1iUiSvzld7mN1mIh2vmsgBU6A1215DCd/WkTzzpL1PSb1iUiSvzld7mN1mIh2vmsmQAAAAAAAAAQEtMAAAAAAACAQgFAAAAAAAAAQkFAAAAAAAAAAAgNYNKisF8pI+xSsj5nBfJh0fpXdBylK5BpGs4IkakSZs5MAAA',
            $output->getUnsignedTx(),
        );
        self::assertSame(
            'AFbsYoHh3yjNs479Y42OEpp+0DYKcRQTxHcJk584SCZv6WAmAk2vTzGdu+/sKR3KP18MkYSDR2tigQfhuRxQgwmF69FEH+T5VPvl3GB3vwCOEZpeJpKXxvcIPQAdKsh2/g==',
            $output->getSignature(),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0xada112cfb90b44ba889cc5d39ac2bf46281e4a91f7919c693bcd9b8323e81ed2', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
