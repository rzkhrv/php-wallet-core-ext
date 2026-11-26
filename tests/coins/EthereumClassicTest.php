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
final class EthereumClassicTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::EthereumClassic;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('ETC', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('61', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::EthereumClassic);

        self::assertSame('0x078bA3228F3E6C08bEEac9A005de0b7e7089aD1c', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::EthereumClassic;
        $path = "m/44'/61'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0x078bA3228F3E6C08bEEac9A005de0b7e7089aD1c', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::EthereumClassic;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertSame('30f339d5435a6e6fbf0ed979b3f0b89a7c15a9c9', strtolower(substr($address, -40)));
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::EthereumClassic;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0x078bA3228F3E6C08bEEac9A005de0b7e7089aD1c', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::EthereumClassic;
        $raw = hex2bin('f400caeeb4b7385969473eb932fe7f1e03b0267685ab175642998a9969be7e21');

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0x078bA3228F3E6C08bEEac9A005de0b7e7089aD1c', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::EthereumClassic;
        $expected = '0x078bA3228F3E6C08bEEac9A005de0b7e7089aD1c';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::EthereumClassic);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::EthereumClassic;
        $privateKey = hex2bin('f400caeeb4b7385969473eb932fe7f1e03b0267685ab175642998a9969be7e21');
        $recipient = '0x80857c0c11b30003cf74f99ed4d68da41c7224aa';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::EthereumClassic)),
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
            'f86780843b9aca008252089480857c0c11b30003cf74f99ed4d68da41c7224aa830f424080819ea023482c93817ea907c2675e5fae690e00ebb9cf18e21b201ea007dfd50d0a0ce2a054aaefe036aee350624f8f3fbc2438a9be43d1f4a2bfa4b4ca695b5437005701',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('0230d0a7aba80a5153f760cd96afc04dea4e1a8f0e0230d79d9da0d4a389fed6', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20TransferAsERC20 (adapted for this chain)
        // Synthetic contract address: this is an offline ABI/signing fixture, not a deployed token.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::EthereumClassic)),
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

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::EthereumClassic);
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
            'f8ab808509c7652400830130b994111111111111111111111111111111111111111180b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000819da088ae6be4acfb89cd4823c5b74e057d6520ebc20ff0d5e01ab575db8eb5f3e8d6a0350b5df2a8869b454c5ec85290bd0b195c0df6337cf19b637dc46656ee841f44',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('b8424fc027c13ec2468d5a1b8a584ad035b41f79290dfbc60653966048feb8d4', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0x078bA3228F3E6C08bEEac9A005de0b7e7089aD1c', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
