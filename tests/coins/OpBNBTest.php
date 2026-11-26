<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Ethereum\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class OpBNBTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::OpBNB;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('BNB', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('204', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::OpBNB);

        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::OpBNB;
        $path = "m/44'/60'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::OpBNB;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/60'/0'/0/1");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertSame('209233bf3f36ce7f0e6c09534e113a0b700ee8b0', strtolower(substr($address, -40)));
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::OpBNB;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::OpBNB;
        $raw = hex2bin('9ee18daf8e463877aaf497282abc216852420101430482a28e246c179e2c5ef1');

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::OpBNB;
        $expected = '0x8f348F300873Fd5DA36950B2aC75a26584584feE';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::OpBNB);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::OpBNB;
        $privateKey = hex2bin('9ee18daf8e463877aaf497282abc216852420101430482a28e246c179e2c5ef1');
        $recipient = '0x58a57ed9d8d624cbd12e2c467d34787555bb1b25';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::OpBNB)),
            // Account nonce from the network; zero for this fixture.
            'nonce' => pack('J', 0),
            // 1 gwei per gas (wei, unsigned big-endian).
            'gas_price' => pack('J', 1_000_000_000),
            'gas_limit' => pack('J', 21_000),
            'to_address' => $recipient,
            'transaction' => new Proto\Transaction([
                'transfer' => new Proto\Transaction\Transfer([
                    // 1,000,000 wei, not whole native coins.
                    'amount' => pack('J', 1_000_000),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        // Independent EIP-155 reference: OpenSSL Keccak-256 and deterministic secp256k1.
        self::assertSame(
            'f86880843b9aca008252089458a57ed9d8d624cbd12e2c467d34787555bb1b25830f4240808201bca0204c4668c494eedf6ae059e3847f6d105567403ebd0860d99ac585db3d25e272a069b3cae916b1ba7ce9ced63da54d41a49ea055ea71b0c2562bd69772f1745838',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('e0e6a7e6fa5c2682a49c77b81904384a139104253f1df999d0e4fed6437bb822', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20TransferAsERC20 (adapted for this chain)
        // Synthetic contract address: this is an offline ABI/signing fixture, not a deployed token.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::OpBNB)),
            'nonce' => pack('J', 0),
            'gas_price' => pack('J', 42_000_000_000),
            'gas_limit' => pack('J', 78_009),
            // Transaction destination is the token contract, not the recipient.
            'to_address' => '0x1111111111111111111111111111111111111111',
            'transaction' => new Proto\Transaction([
                'erc20_transfer' => new Proto\Transaction\ERC20Transfer([
                    'to' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84',
                    // 2 tokens with 18 decimals; unsigned big-endian amount.
                    'amount' => pack('J', 2_000_000_000_000_000_000),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::OpBNB);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000',
            bin2hex($output->getData()),
        );
        // Independent EIP-155 reference: OpenSSL Keccak-256 and deterministic secp256k1.
        self::assertSame(
            'f8ac808509c7652400830130b994111111111111111111111111111111111111111180b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec800008201bca062b2c38adca347cb6f5a1294d92abb29b5dc9710218e527ec1c79c8f2cd31d9ca00eac7e6a85903c819122fc373a3979d0560ef7c2194bb9a0a3ae83f75652f87a',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('c327179098d517b99c759a077d9d8dff8e438812d4d3fb30b1c03ac22e070866', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0x8f348F300873Fd5DA36950B2aC75a26584584feE', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
