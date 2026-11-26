<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Ripple\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class XRPTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::XRP;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('XRP', $symbol);
        self::assertSame(6, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::XRP);

        self::assertSame('rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXV3', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::XRP;
        $path = "m/44'/144'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXV3', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::XRP;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXV3', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::XRP;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXV3', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::XRP;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/144'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXV3', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::XRP;
        $expected = 'rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXV3';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::XRP);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/XRP/TWAnySignerTests.cpp: SignXrpPayment
        $coin = CoinType::XRP;
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('a5576c0f63da10e584568c8d134569ff44017b0a249eb70657127ae04f38cc77'),
            'account' => 'rfxdLwsZnoespnTDDb1Xhvbc8EFNdztaoq',
            'fee' => 10,
            'sequence' => 32_268_248,
            'last_ledger_sequence' => 32_268_269,
            'op_payment' => new Proto\OperationPayment([
                'destination' => 'rU893viamSnsfP3zjzM2KPxjqZjXSXK6VF',
                'amount' => 10,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '12000022000000002401ec5fd8201b01ec5fed61400000000000000a68400000000000000a732103d13e1152965a51a4a9fd9a8b4ea3dd82a4eba6b25fcad5f460a2342bb650333f74463044022037d32835c9394f39b2cfd4eaf5b0a80e0db397ace06630fa2b099ff73e425dbc02205288f780330b7a88a1980fa83c647b5908502ad7de9a44500c08f0750b0d9e8481144c55f5a78067206507580be7bb2686c8460adff983148132e4e20aecf29090ac428a9c43f230a829220d',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/XRP/TWAnySignerTests.cpp: SignTokenPayment0
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('4ba5fd2ebf0f5d7e579b3c354c263ebb39cda4093845125786a280301af14e21'),
            'account' => 'raPAA61ca99bdwNiZs5JJukR5rvkHWvkBX',
            'fee' => 10,
            'sequence' => 32_268_645,
            'last_ledger_sequence' => 32_268_666,
            'op_payment' => new Proto\OperationPayment([
                'destination' => 'rU893viamSnsfP3zjzM2KPxjqZjXSXK6VF',
                'currency_amount' => new Proto\CurrencyAmount([
                    'currency' => 'USD',
                    'value' => '10',
                    'issuer' => 'rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::XRP);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '12000022000000002401ec6165201b01ec617a61d4c38d7ea4c6800000000000000000000000000055534400000000004b4e9c06f24296074f7bc48f92a97916c6dc5ea968400000000000000a7321020652a477b0cca8b74d6e68a6a386a836b226101617481b95180eaffbe841b3227446304402203e925caeb05006afb135254e9ae4e46de2019db6c6f68614ef969885063a777602206af110fc29775256fcad8b14974c6a838141d82193192d3b57324fe1079afa1781143b2fa4f36553e5b7a4f54ff9e6883e44b4b0dbb383148132e4e20aecf29090ac428a9c43f230a829220d',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignTrustSet(): void
    {
        // Upstream SignTrustSetPayment: authorize holding an issued currency.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('8753e78ee2963f301f82e5eeab2754f593fc242ce94273dd2fb0684e3b0f2b91'),
            'account' => 'rnRkLPni2Q5yMxSqyJSJEkKUfQNFkaAspS',
            'fee' => 10, // drops
            'sequence' => 32_268_473,
            'last_ledger_sequence' => 32_268_494,
            'op_trust_set' => new Proto\OperationTrustSet([
                'limit_amount' => new Proto\CurrencyAmount([
                    'currency' => 'USD', 'value' => '10', 'issuer' => 'rf1BiGeXwwQoi8Z2ueFYTEXSwuJYfV2Jpn',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::XRP);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '12001422000000002401ec60b9201b01ec60ce63d4c38d7ea4c6800000000000000000000000000055534400000000004b4e9c06f24296074f7bc48f92a97916c6dc5ea968400000000000000a732103dc4a0dae2d550de7cace9c26c1a331a114e3e7efee5577204b476d27e2dc683a7446304402206ebcc7a689845df373dd2566cd3789862d426d9ad4e6a09c2d2772b57e82696a022066b1f217a0f0d834d167613a313f74097423a9ccd11f1ae7f90ffab0d2fc26b58114308ea8e515b64f2e6616a33b42e1bbb9fa00bbd2',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignPaymentWithDestinationTag(): void
    {
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('a5576c0f63da10e584568c8d134569ff44017b0a249eb70657127ae04f38cc77'),
            'account' => 'rfxdLwsZnoespnTDDb1Xhvbc8EFNdztaoq',
            'fee' => 10,
            'sequence' => 32_268_248,
            'last_ledger_sequence' => 32_268_269,
            'op_payment' => new Proto\OperationPayment([
                'destination' => 'rU893viamSnsfP3zjzM2KPxjqZjXSXK6VF',
                'amount' => 10,
                'destination_tag' => 12_345,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::XRP);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        // DestinationTag: UInt32 field (0x2e) followed by a four-byte big-endian value.
        self::assertStringContainsString('2e00003039', bin2hex($output->getEncoded()));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXV3', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['rPwE3gChNKtZ1mhH3Ko8YFGqKmGRWLWXVr', false],
        ];
    }
}
