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
final class IoTeXEVMTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::IoTeXEVM;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('IOTX', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('4689', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::IoTeXEVM);

        self::assertSame('0x038B8C633873Ca0f06961100BE5d37676EADDD23', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::IoTeXEVM;
        $path = "m/44'/304'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0x038B8C633873Ca0f06961100BE5d37676EADDD23', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::IoTeXEVM;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/304'/0'/0/1");
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/304'/0'/0/1.
        self::assertSame('a1321beb00a1f606e70b2ad8f971c0b2ce0179bac1f8198e4fd4456b783913ce', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertSame('0x4e81d3578E64F632cB3c03ef1baE1C9F89f94384', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::IoTeXEVM;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0x038B8C633873Ca0f06961100BE5d37676EADDD23', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::IoTeXEVM;
        $raw = hex2bin('43098b91dd7a6cf6abfec6601c239ac4c0b05e43b4bbb3f1a90c089c80170cf9');

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0x038B8C633873Ca0f06961100BE5d37676EADDD23', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::IoTeXEVM;
        $expected = '0x038B8C633873Ca0f06961100BE5d37676EADDD23';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::IoTeXEVM);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::IoTeXEVM;
        $privateKey = hex2bin('43098b91dd7a6cf6abfec6601c239ac4c0b05e43b4bbb3f1a90c089c80170cf9');
        $recipient = '0x31089b034c0853848d8e466fd973dbee51247620';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::IoTeXEVM)),
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
            'f86880843b9aca008252089431089b034c0853848d8e466fd973dbee51247620830f4240808224c6a0ed1de504dc9a99713b6aa7c5c902b1adb6689143abd1c254112862c321e7d580a067273291bdb952371e959853e66380469c88e523ff342a7b8b1f203d10505c78',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('3cbe69bd6b267ac3c10730674ac32e9872fd7a24b08fca265ab79249021be97a', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20TransferAsERC20 (adapted for this chain)
        // Synthetic contract address: this is an offline ABI/signing fixture, not a deployed token.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::IoTeXEVM)),
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

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::IoTeXEVM);
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
            'f8ac808509c7652400830130b994111111111111111111111111111111111111111180b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec800008224c5a06faf04683409990d1c7b2f47c769260026192eee632f58c3b69960c1bfba5523a0260be5a8c25263ae497a23364d6caf9b3749bb26eb22ed5e2785c2b74d6d505f',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('238009bd306f8321be736a5dba51a5c30755b92416426584fcf6119300be0dc1', bin2hex(\WalletCore\Hash::keccak256($output->getEncoded())));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0x038B8C633873Ca0f06961100BE5d37676EADDD23', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
