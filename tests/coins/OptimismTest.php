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
final class OptimismTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Optimism;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('ETH', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('10', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Optimism);

        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Optimism;
        $path = "m/44'/60'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Optimism;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertSame('209233bf3f36ce7f0e6c09534e113a0b700ee8b0', strtolower(substr($address, -40)));
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Optimism;
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
        $coin = CoinType::Optimism;
        $raw = hex2bin('9ee18daf8e463877aaf497282abc216852420101430482a28e246c179e2c5ef1');

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Optimism;
        $expected = '0x8f348F300873Fd5DA36950B2aC75a26584584feE';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Optimism);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Optimism;
        $privateKey = hex2bin('9ee18daf8e463877aaf497282abc216852420101430482a28e246c179e2c5ef1');
        $recipient = '0x58a57ed9d8d624cbd12e2c467d34787555bb1b25';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Optimism)),
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
            'f86680843b9aca008252089458a57ed9d8d624cbd12e2c467d34787555bb1b25830f42408037a0c2bb04ab249bc9684522b2b063e3442d5efe586028eaacb7bcbff3dfc0a8e3faa07d025a60f03170383c1303f65a55e4adb8a6e1efb6eb7a5f08b532f0692f8c71',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('29687ac636a3e4e781df4fbaf5105e04209c5b4ebdfb393c10a9556f2d6cf119', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20TransferAsERC20 (adapted for this chain)
        // Synthetic contract address: this is an offline ABI/signing fixture, not a deployed token.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Optimism)),
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

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Optimism);
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
            'f8aa808509c7652400830130b994111111111111111111111111111111111111111180b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec8000037a00cc99cd63fc70b513a07d2f237f387e6722691d4d54d1662f9b68274c8f6a66ea02ae13be3afd685645ee52c41bc73350cc46e6b0f68f8c5c544c422fc6dc41ad1',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('b90e4a5a79bef0cc87c438163626ddd0b65088b1a73a36ca00c809500339514f', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransferEip1559(): void
    {
        // Synthetic ERC-20 contract; fee fields are in wei. Independent EIP-1559 byte vector.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Optimism)),
            'nonce' => pack('J', 0),
            'tx_mode' => Proto\TransactionMode::Enveloped,
            'gas_limit' => pack('J', 78_009),
            'max_inclusion_fee_per_gas' => pack('J', 2_000_000_000),
            'max_fee_per_gas' => pack('J', 3_000_000_000),
            'to_address' => '0x1111111111111111111111111111111111111111',
            'transaction' => new Proto\Transaction([
                'erc20_transfer' => new Proto\Transaction\ERC20Transfer([
                    'to' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84',
                    'amount' => pack('J', 2_000_000_000_000_000_000),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Optimism);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000',
            bin2hex($output->getData()),
        );
        self::assertSame(
            '02f8b00a80847735940084b2d05e00830130b994111111111111111111111111111111111111111180b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000c001a0ccfe53ab9a9ef413b6776c22eea758632f75f93856f91f0e7d1fea990587e648a0365285c5015d521c433f548e103dbc37e236a2ad1aef017ea719e224b95d46a5',
            bin2hex($output->getEncoded()),
        );
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
