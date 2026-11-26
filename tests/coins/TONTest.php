<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\TheOpenNetwork\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;
use WalletCore\TONAddressConverter;
use WalletCore\TONMessageSigner;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class TONTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::TON;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('GRAM', $symbol);
        self::assertSame(9, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::TON);

        self::assertSame('UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaT4', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::TON;
        $path = "m/44'/607'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaT4', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::TON;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/607'/1'");
        self::assertNotNull($key);
        // Independent BIP39/SLIP-0010 reference: m/44'/607'/1'.
        self::assertSame('b009f78b35efc2c35989e222a955a06351e10464f62915278a43e3ab14a46bcd', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaT4', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::TON;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaT4', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::TON;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/607'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaT4', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::TON;
        $expected = 'UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaT4';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::TON);

        self::assertSame($expected, $valid);
    }

    public function testAddressToBoc(): void
    {
        // Wallet Core: TWTONAddressConverterTests.cpp.
        $address = 'UQBjKqthWBE6GEcqb_epTRFrQ1niS6Z1Z1MHMwR-mnAYRoYr';

        $boc = TONAddressConverter::toBoc($address);

        self::assertSame('te6cckEBAQEAJAAAQ4AMZVVsKwInQwjlTf71KaItaGs8SXTOrOpg5mCP004DCNAptHQU', $boc);
    }

    public function testAddressFromBoc(): void
    {
        $boc = 'te6cckEBAQEAJAAAQ4AFvT5rqwxcbKfITqnkwL+go4Zi9bulRHAtLt4cjjFdK7B8L+Cq';

        $address = TONAddressConverter::fromBoc($boc);

        self::assertSame('UQAt6fNdWGLjZT5CdU8mBf0FHDMXrd0qI4FpdvDkcYrpXV5H', $address);
    }

    public function testSignMessage(): void
    {
        // Wallet Core: TWTONMessageSignerTests.cpp.
        $key = PrivateKey::createWithData(hex2bin('112d4e2e700a468f1eae699329202f1ee671d6b665caa2d92dea038cf3868c18'));
        self::assertNotNull($key);

        $signature = TONMessageSigner::signMessage($key, 'Hello world');

        self::assertSame('2490fbaa72aec0b77b19162bbbe0b0e3f7afd42cc9ef469f0494cd4a366a4bf76643300cd5991f66bce6006336742b8d1d435d541d244dcc013d428472e89504', $signature);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::TON;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/607'/0'");
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
            'sequence_number' => 1,
            'expire_at' => 1_700_000_600,
            'wallet_version' => Proto\WalletVersion::WALLET_V4_R2,
            'messages' => [
                new Proto\Transfer([
                    'dest' => $recipient,
                    'amount' => pack('N', 100_000_000),
                    'mode' => 3,
                    'bounceable' => true,
                ]),
            ],
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
        // Wallet Core 4.8.7: rust/tw_tests/tests/chains/ton/ton_sign.rs: test_ton_sign_transfer_jettons
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('c054900a527538c1b4325688a421c0469b171c29f23a62da216e90b0df2412ee'),
            'sequence_number' => 0,
            'expire_at' => 1_787_693_046,
            'wallet_version' => Proto\WalletVersion::WALLET_V4_R2,
            'messages' => [new Proto\Transfer([
                // Destination is the sender's jetton wallet, not the recipient's owner address.
                'dest' => 'EQBiaD8PO1NwfbxSkwbcNT9rXDjqhiIvXWymNO-edV0H5lja',
                'amount' => pack('N', 100_000_000),
                'mode' => 3,
                'bounceable' => true,
                'jetton_transfer' => new Proto\JettonTransfer([
                    'query_id' => 69,
                    'jetton_amount' => pack('N', 1_000_000_000),
                    'to_owner' => 'EQAFwMs5ha8OgZ9M4hQr80z9NkE7rGxUpE1hCFndiY6JnDx8',
                    'response_address' => 'EQBaKIMq5Am2p_rfR1IFTwsNWHxBkOpLTmwUain5Fj4llTXk',
                    'forward_amount' => pack('C', 1),
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::TON);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('3e4dac37acdc99ca670b3747ab2730e818727d9d25c80d3987abe501356d0da0', bin2hex($output->getHash()));
        self::assertNotSame('', $output->getEncoded());
    }

    public function testSignWalletV5R1WithComment(): void
    {
        // Upstream test_ton_sign_wallet_v5r1_transfer_with_ascii_comment.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('3570e35f54cfb843f2cfaf2b8cae7ceeb7b32225d7dbbd86f611056d74d9073e'),
            'sequence_number' => 3,
            'expire_at' => 1_723_472_681,
            'wallet_version' => Proto\WalletVersion::WALLET_V5_R1,
            'messages' => [new Proto\Transfer([
                'dest' => 'EQBe6DtCpJZe8M4t-crMXe93JlEYgSl30S5OUuMSLOfeQfBu',
                'amount' => "\x0a", // nanoton
                'mode' => 3, // pay fees separately, ignore action-phase errors
                'bounceable' => true,
                'comment' => 'test comment',
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::TON);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('b9cf6c325f44cb7c90eac5f8f0428cd27152789a2ad897048b4c4ccd32d857a3', bin2hex($output->getHash()));
        self::assertNotSame('', $output->getEncoded());
    }

    public function testDeployWalletV5R1(): void
    {
        // Upstream test_ton_sign_wallet_v5r1_transfer_and_deploy: seqno zero includes the wallet state init.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('3570e35f54cfb843f2cfaf2b8cae7ceeb7b32225d7dbbd86f611056d74d9073e'),
            'sequence_number' => 0,
            'expire_at' => 4_294_967_295,
            'wallet_version' => Proto\WalletVersion::WALLET_V5_R1,
            'messages' => [new Proto\Transfer([
                'dest' => 'EQBe6DtCpJZe8M4t-crMXe93JlEYgSl30S5OUuMSLOfeQfBu',
                'amount' => "\x0a",
                'mode' => 3,
                'bounceable' => true,
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::TON);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('437dae441a95a6bccdcdcea2560c313de24f13dd85c76d5d7ecaab1e70a1e52b', bin2hex($output->getHash()));
        self::assertNotSame('', $output->getEncoded());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaT4', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['UQDgEMqToTacHic7SnvnPFmvceG5auFkCcAw0mSCvzvKUaA4', false],
        ];
    }
}
