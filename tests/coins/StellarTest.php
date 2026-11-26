<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Stellar\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base64;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class StellarTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Stellar;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('XLM', $symbol);
        self::assertSame(7, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Stellar);

        self::assertSame('GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZ2P', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Stellar;
        $path = "m/44'/148'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZ2P', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Stellar;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/148'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZ2P', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Stellar;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZ2P', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Stellar;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/148'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZ2P', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Stellar;
        $expected = 'GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZ2P';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Stellar);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        // Upstream Sign_Payment_66b5: 0.1 XLM (1,000,000 stroops).
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('3c0635f8638605aed6e461cf3fa2d508dd895df1a1655ff92c79bfbeaf88d4b9'),
            'account' => 'GDFEKJIFKUZP26SESUHZONAUJZMBSODVN2XBYN4KAGNHB7LX2OIXLPUL',
            'fee' => 1_000,
            'sequence' => '144098454883270657',
            'passphrase' => 'Public Global Stellar Network ; September 2015',
            'op_payment' => new Proto\OperationPayment([
                'destination' => 'GA3ISGYIE2ZTH3UAKEKBVHBPKUSL3LT4UQ6C5CUGP2IM5F467O267KI7',
                'amount' => 1_000_000,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Stellar);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'AAAAAMpFJQVVMv16RJUPlzQUTlgZOHVurhw3igGacP1305F1AAAD6AH/8MgAAAABAAAAAAAAAAAAAAABAAAAAAAAAAEAAAAANokbCCazM+6AURQanC9VJL2ufKQ8LoqGfpDOl577te8AAAAAAAAAAAAPQkAAAAAAAAAAAXfTkXUAAABAM9Nhzr8iWKzqnHknrxSVoa4b2qzbTzgyE2+WWxg6XHH50xiFfmvtRKVhzp0Jg8PfhatOb6KNheKRWEw4OvqEDw==',
            $output->getSignature(),
        );
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Stellar/TWAnySignerTests.cpp: Sign_Payment_Asset_ea50
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('3c0635f8638605aed6e461cf3fa2d508dd895df1a1655ff92c79bfbeaf88d4b9'),
            'account' => 'GDFEKJIFKUZP26SESUHZONAUJZMBSODVN2XBYN4KAGNHB7LX2OIXLPUL',
            'fee' => 1_000,
            'sequence' => '144098454883270661',
            'passphrase' => 'Public Global Stellar Network ; September 2015',
            'op_payment' => new Proto\OperationPayment([
                'destination' => 'GA3ISGYIE2ZTH3UAKEKBVHBPKUSL3LT4UQ6C5CUGP2IM5F467O267KI7',
                'amount' => 12_000_000,
                'asset' => new Proto\Asset([
                    'issuer' => 'GA6HCMBLTZS5VYYBCATRBRZ3BZJMAFUDKYYF6AH6MVCMGWMRDNSWJPIH',
                    'alphanum4' => 'MOBI',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Stellar);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'AAAAAMpFJQVVMv16RJUPlzQUTlgZOHVurhw3igGacP1305F1AAAD6AH/8MgAAAAFAAAAAAAAAAAAAAABAAAAAAAAAAEAAAAANokbCCazM+6AURQanC9VJL2ufKQ8LoqGfpDOl577te8AAAABTU9CSQAAAAA8cTArnmXa4wEQJxDHOw5SwBaDVjBfAP5lRMNZkRtlZAAAAAAAtxsAAAAAAAAAAAF305F1AAAAQEuWZZvKZuF6SMuSGIyfLqx5sn5O55+Kd489uP4g9jZH4UE7zZ4ME0+74I0BU8YDsYOmmxcfp/vdwTd+n3oGCQw=',
            $output->getSignature(),
        );
    }

    public function testSignChangeTrust(): void
    {
        // Upstream Sign_Change_Trust_ad9c: establish a trustline for an issued asset.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('3c0635f8638605aed6e461cf3fa2d508dd895df1a1655ff92c79bfbeaf88d4b9'),
            'passphrase' => 'Public Global Stellar Network ; September 2015',
            'account' => 'GDFEKJIFKUZP26SESUHZONAUJZMBSODVN2XBYN4KAGNHB7LX2OIXLPUL',
            'fee' => 10_000,
            'sequence' => '144098454883270659',
            'op_change_trust' => new Proto\OperationChangeTrust([
                'asset' => new Proto\Asset(['issuer' => 'GA6HCMBLTZS5VYYBCATRBRZ3BZJMAFUDKYYF6AH6MVCMGWMRDNSWJPIH', 'alphanum4' => 'MOBI']),
                'valid_before' => 1_613_336_576,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Stellar);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'AAAAAMpFJQVVMv16RJUPlzQUTlgZOHVurhw3igGacP1305F1AAAnEAH/8MgAAAADAAAAAQAAAAAAAAAAAAAAAGApkAAAAAAAAAAAAQAAAAAAAAAGAAAAAU1PQkkAAAAAPHEwK55l2uMBECcQxzsOUsAWg1YwXwD+ZUTDWZEbZWR//////////wAAAAAAAAABd9ORdQAAAEAnfyXyaNQX5Bq3AEQVBIaYd+cLib+y2sNY7DF/NYVSE51dZ6swGGElz094ObsPefmVmeRrkGsSc/fF5pmth+wJ',
            $output->getSignature(),
        );
    }

    public function testSignCreateAccountWithMemo(): void
    {
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('3c0635f8638605aed6e461cf3fa2d508dd895df1a1655ff92c79bfbeaf88d4b9'),
            'account' => 'GDFEKJIFKUZP26SESUHZONAUJZMBSODVN2XBYN4KAGNHB7LX2OIXLPUL',
            'fee' => 1_000,
            'sequence' => '144098454883270657',
            'passphrase' => 'Public Global Stellar Network ; September 2015',
            'memo_text' => new Proto\MemoText(['text' => 'invoice-42']),
            'op_create_account' => new Proto\OperationCreateAccount([
                'destination' => 'GA3ISGYIE2ZTH3UAKEKBVHBPKUSL3LT4UQ6C5CUGP2IM5F467O267KI7',
                'amount' => 10_000_000, // 1 XLM in stroops
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Stellar);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        $envelope = Base64::decode($output->getSignature());
        self::assertNotFalse($envelope);
        // XDR text memo discriminator, length, and text.
        self::assertStringContainsString(hex2bin('000000010000000a').'invoice-42', $envelope);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZ2P', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['GA3H6I4C5XUBYGVB66KXR27JV5KS3APSTKRUWOIXZ5MVWZKVTLXWKZAP', false],
        ];
    }
}
