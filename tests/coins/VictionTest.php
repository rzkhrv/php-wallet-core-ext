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
final class VictionTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Viction;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('VIC', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('88', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Viction);

        self::assertSame('0xC74b6D8897cBa9A4b659d43fEF73C9cA852cE424', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Viction;
        $path = "m/44'/889'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0xC74b6D8897cBa9A4b659d43fEF73C9cA852cE424', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Viction;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertSame('20b973906d77359c5020f015116fdff99c7780bb', strtolower(substr($address, -40)));
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Viction;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0xC74b6D8897cBa9A4b659d43fEF73C9cA852cE424', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Viction;
        $raw = hex2bin('8027de9d98241e9b9c7d6eb09b2f81d53cb11fa96604512e854d09e0ff8b84d9');

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0xC74b6D8897cBa9A4b659d43fEF73C9cA852cE424', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Viction;
        $expected = '0xC74b6D8897cBa9A4b659d43fEF73C9cA852cE424';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Viction);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Viction;
        $privateKey = hex2bin('8027de9d98241e9b9c7d6eb09b2f81d53cb11fa96604512e854d09e0ff8b84d9');
        $recipient = '0xcbb4aa69a8e1424732a41566aefb41b8fac4d0ef';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Viction)),
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
            'f86780843b9aca0082520894cbb4aa69a8e1424732a41566aefb41b8fac4d0ef830f42408081d3a0c0584b601a308159c15edb9d8479244ce0496ee53dfc7b2b0dee19a7ee7c8ee7a063413c3367263c70f30089fbf741cf5eaec7b4cf13b653c578a93161b3bdedcc',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('d5f33a7a080a8dccab81972be8d38540fdcf81a9b835bdbecc5d53190904dff3', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20TransferAsERC20 (adapted for this chain)
        // Synthetic contract address: this is an offline ABI/signing fixture, not a deployed token.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Viction)),
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

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Viction);
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
            'f8ab808509c7652400830130b994111111111111111111111111111111111111111180b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec8000081d3a013d5fa7d71069be52155525ca8431369d9366d06f644029635cc960322d69b75a0117b476d916eacf316ec947f69ba24cb9af6c3b32bd4d720aa183b11f5fde6f0',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('9b0c8c3a174a68205f8a4e5b3acd3e9203b76c8ff5e5f49c778dfde15967a82d', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0xC74b6D8897cBa9A4b659d43fEF73C9cA852cE424', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
