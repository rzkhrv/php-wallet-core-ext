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
final class POANetworkTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::POANetwork;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('POA', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('99', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::POANetwork);

        self::assertSame('0xe8a3e8bE17E172B6926130eAfB521e9D2849aca9', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::POANetwork;
        $path = "m/44'/178'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0xe8a3e8bE17E172B6926130eAfB521e9D2849aca9', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::POANetwork;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertSame('835cffc9e3541dc2b6f6420d7389da351bba45cf', strtolower(substr($address, -40)));
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::POANetwork;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0xe8a3e8bE17E172B6926130eAfB521e9D2849aca9', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::POANetwork;
        $raw = hex2bin('1504d0e2b20557ab04aa87328833a65701a5789188a1d01d760235b3663e538a');

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0xe8a3e8bE17E172B6926130eAfB521e9D2849aca9', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::POANetwork;
        $expected = '0xe8a3e8bE17E172B6926130eAfB521e9D2849aca9';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::POANetwork);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::POANetwork;
        $privateKey = hex2bin('1504d0e2b20557ab04aa87328833a65701a5789188a1d01d760235b3663e538a');
        $recipient = '0x57808a3b91017a6d2a7bb98d1e5918d8b0c03799';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::POANetwork)),
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
            'f86780843b9aca008252089457808a3b91017a6d2a7bb98d1e5918d8b0c03799830f42408081eaa00b94b7a14131a0b064baa2fdea55e64089a2fa6315a5f8e7bea92a7817524a95a04c3c4493078baf1a64aa518da70ceca4a3861831c5437309b4021b3f9050c7ec',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('45acbfc47ea30f96745f79ac522e51fc1037610c4721a8143d7350524fb91a53', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20TransferAsERC20 (adapted for this chain)
        // Synthetic contract address: this is an offline ABI/signing fixture, not a deployed token.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::POANetwork)),
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

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::POANetwork);
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
            'f8ab808509c7652400830130b994111111111111111111111111111111111111111180b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec8000081e9a064e8ef259c0a3b7bc29b3b7f10a51bffd20fbfd1bc80eefd6b6425ab740ddb63a043af05f5690285de381aa1cbd3b31930bdf4da4426bb311f4ba9ae204ee32b4f',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('c5c0016bb5c9f80189029864ff6c652c95abc39ec8f647a832677e6f4e74b880', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0xe8a3e8bE17E172B6926130eAfB521e9D2849aca9', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
