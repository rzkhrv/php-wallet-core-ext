<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Ethereum\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base64;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\DataVector;
use WalletCore\DerivationPath;
use WalletCore\Eip7702;
use WalletCore\Ethereum;
use WalletCore\EthereumAbi;
use WalletCore\EthereumAbiFunction;
use WalletCore\EthereumAbiValue;
use WalletCore\EthereumMessageSigner;
use WalletCore\EthereumRlp;
use WalletCore\HDWallet;
use WalletCore\Hash;
use WalletCore\Mnemonic;
use WalletCore\PrivateKey;
use WalletCore\PublicKey;
use WalletCore\PublicKeyType;
use WalletCore\StoredKey;
use WalletCore\TransactionCompiler;
use WalletCore\TransactionUtil;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class EthereumTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Ethereum;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('ETH', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('1', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Ethereum);

        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Ethereum;
        $path = "m/44'/60'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Ethereum;
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
        $coin = CoinType::Ethereum;
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
        $coin = CoinType::Ethereum;
        $raw = hex2bin('9ee18daf8e463877aaf497282abc216852420101430482a28e246c179e2c5ef1');

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Ethereum;
        $expected = '0x8f348F300873Fd5DA36950B2aC75a26584584feE';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Ethereum);

        self::assertSame($expected, $valid);
    }

    public function testBuildDerivationPath(): void
    {
        $coin = CoinType::Ethereum;

        $path = DerivationPath::create(CoinType::purpose($coin), CoinType::slip44Id($coin), 0, 0, 7);

        self::assertNotNull($path);
        self::assertSame("m/44'/60'/0'/0/7", $path->description());
        self::assertSame(7, $path->address());
    }

    public function testInspectDerivationPath(): void
    {
        $path = DerivationPath::createWithString("m/44'/60'/0'/0/7");
        self::assertNotNull($path);

        $index = $path->indexAt(2);

        self::assertNotNull($index);
        self::assertSame(0, $index->value());
        self::assertTrue($index->hardened());
    }

    public function testChecksumAddress(): void
    {
        $address = '0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed';

        $checksummed = Ethereum::addressChecksummed($address);

        self::assertSame('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', $checksummed);
    }

    public function testEncodeERC20TransferCall(): void
    {
        $recipient = AnyAddress::createWithString('0x5322b34c88ed0691971bf52a7047448f0f4efc84', CoinType::Ethereum);
        self::assertNotNull($recipient);
        $function = EthereumAbiFunction::createWithString('transfer');
        self::assertNotNull($function);
        $function->addParamAddress($recipient->data(), false);
        $function->addParamUInt256(pack('J', 2_000_000_000_000_000_000), false);

        $encoded = EthereumAbi::encode($function);

        self::assertSame('transfer(address,uint256)', $function->getType());
        self::assertSame('a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000', bin2hex($encoded));
    }

    public function testDecodeUint256(): void
    {
        // ABI values are 32-byte words; the result is a decimal string.
        $encoded = hex2bin('0000000000000000000000000000000000000000000000010000000000000000');

        $decoded = EthereumAbiValue::decodeUInt256($encoded);

        self::assertSame('18446744073709551616', $decoded);
    }

    public function testEncodeUint32(): void
    {
        $amount = 69;

        $encoded = EthereumAbiValue::encodeUInt32($amount);

        self::assertSame('0000000000000000000000000000000000000000000000000000000000000045', bin2hex($encoded));
    }

    public function testEip7702AuthorizationHash(): void
    {
        // Wallet Core: eip7702_ffi.rs, verified with viem.
        $chainId = pack('J', 1);
        $contract = '0xB91aaa96B138A1B1D94c9df4628187132c5F2bf1';
        $nonce = pack('J', 1);

        $hash = Eip7702::getAuthorizationHash($chainId, $contract, $nonce);

        self::assertSame('3ae543b2fa103a39a6985d964a67caed05f6b9bb2430ad6d498cda743fe911d9', bin2hex($hash));
    }

    public function testSignEip7702Authorization(): void
    {
        // Wallet Core: eip7702_ffi.rs. The private key parameter is a HEX string.
        $chainId = pack('J', 1);
        $contract = '0xB91aaa96B138A1B1D94c9df4628187132c5F2bf1';
        $nonce = pack('J', 1);
        $privateKey = '0x947dd69af402e7f48da1b845dfc1df6be593d01a0d8274bd03ec56712e7164e8';

        $signed = Eip7702::signAuthorization($chainId, $contract, $nonce, $privateKey);
        self::assertNotNull($signed);
        $authorization = json_decode($signed, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('0x2c39f2f64441dd38c364ee175dc6b9a87f34ca330bce968f6c8e22811e3bb710', $authorization['r']);
        self::assertSame('0x5f1bcde93dee4b214e60bc0e63babcc13e4fecb8a23c4098fd89844762aa012c', $authorization['s']);
        self::assertSame($contract, $authorization['address']);
    }

    public function testCalculateTransactionHash(): void
    {
        // Wallet Core: ethereum_transaction_util.rs. The input is HEX text, not binary.
        $encoded = 'f8aa808509c7652400830130b9946b175474e89094c44da98b954eedeac495271d0f80b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec8000025a0724c62ad4fbf47346b02de06e603e013f26f26b56fdc0be7ba3d6273401d98cea0032131cae15da7ddcda66963e8bef51ca0d9962bfef0547d3f02597a4a58c931';

        $hash = TransactionUtil::calcTxHash(CoinType::Ethereum, $encoded);

        self::assertSame('0x199a7829fc5149e49b452c2cab76d8fa5a9682fee6e4891b8acb697ac142513e', $hash);
    }

    public function testWalletFromEntropy(): void
    {
        $entropy = str_repeat("\x00", 16);

        $wallet = HDWallet::createWithEntropy($entropy, '');

        self::assertNotNull($wallet);
        self::assertSame('abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about', $wallet->mnemonic());
        self::assertSame($entropy, $wallet->entropy());
    }

    public function testImportEncryptedWallet(): void
    {
        $mnemonic = 'shoot island position soft burden budget tooth cruel issue economy destroy above';
        $password = 'test-password';
        $coin = CoinType::Ethereum;
        $stored = StoredKey::importHDWallet($mnemonic, 'Test wallet', $password, $coin);
        self::assertNotNull($stored);
        $json = $stored->exportJSON();
        self::assertNotNull($json);

        $restored = StoredKey::importJSON($json);
        self::assertNotNull($restored);
        $account = $restored->accountForCoin($coin, null);

        self::assertNotNull($account);
        self::assertSame('0x8f348F300873Fd5DA36950B2aC75a26584584feE', $account->address());
        self::assertSame($mnemonic, $restored->decryptMnemonic($password));
    }

    public function testValidateMnemonic(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

        $valid = Mnemonic::isValid($mnemonic);

        self::assertTrue($valid);
    }

    public function testSignJsonTransaction(): void
    {
        // Protobuf JSON represents bytes as base64; this is not an RPC transaction object.
        $coin = CoinType::Ethereum;
        $key = hex2bin('17209af590a86462395d5881e60d11c7fa7d482cfb02b5a01b93c2eeef243543');
        $json = json_encode([
            'chainId' => Base64::encode(pack('J', 1)),
            'gasPrice' => Base64::encode(pack('J', 3_600_000_000)),
            'gasLimit' => Base64::encode(pack('J', 21_000)),
            'toAddress' => '0x7d8bf18C7cE84b3E175b339c4Ca93aEd1dD166F1',
            'transaction' => [
                'transfer' => ['amount' => Base64::encode(pack('J', 924_400_000_000_000))],
            ],
        ], JSON_THROW_ON_ERROR);
        self::assertTrue(AnySigner::supportsJSON($coin));

        $signed = AnySigner::signJSON($json, $key, $coin);

        self::assertSame('f86a8084d693a400825208947d8bf18c7ce84b3e175b339c4ca93aed1dd166f1870348bca5a160008025a0fe5802b49e04c6b1705088310e133605ed8b549811a18968ad409ea02ad79f21a05bf845646fb1e1b9365f63a7fd5eb5e984094e3ed35c3bed7361aebbcbf41f10', $signed);
    }

    public function testEncodeRlpList(): void
    {
        $input = new \TW\EthereumRlp\Proto\EncodingInput([
            'item' => new \TW\EthereumRlp\Proto\RlpItem([
                'list' => new \TW\EthereumRlp\Proto\RlpList([
                    'items' => [
                        new \TW\EthereumRlp\Proto\RlpItem(['string_item' => 'cat']),
                        new \TW\EthereumRlp\Proto\RlpItem(['string_item' => 'dog']),
                    ],
                ]),
            ]),
        ]);

        $data = EthereumRlp::encode(CoinType::Ethereum, $input->serializeToString());
        self::assertNotNull($data);
        $output = new \TW\EthereumRlp\Proto\EncodingOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('c88363617483646f67', bin2hex($output->getEncoded()));
    }

    public function testSignTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC1559Transfer_1442
        $coin = CoinType::Ethereum;
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('4f96ed80e9a7555a6f74b3d658afdd9c756b0a40d4ca30c42c2039eb449bb904'),
            'chain_id' => pack('J', 3),
            'nonce' => pack('J', 6),
            'tx_mode' => Proto\TransactionMode::Enveloped,
            'gas_limit' => pack('J', 21_100),
            'max_inclusion_fee_per_gas' => pack('J', 2_000_000_000),
            'max_fee_per_gas' => pack('J', 3_000_000_000),
            'to_address' => '0xB9F5771C27664bF2282D98E09D7F50cEc7cB01a7',
            'transaction' => new Proto\Transaction([
                'transfer' => new Proto\Transaction\Transfer([
                    'amount' => pack('J', 543_210_987_654_321),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '02f8710306847735940084b2d05e0082526c94b9f5771c27664bf2282d98e09d7f50cec7cb01a78701ee0c29f50cb180c080a092c336138f7d0231fe9422bb30ee9ef10bf222761fe9e04442e3a11e88880c64a06487026011dae03dc281bc21c7d7ede5c2226d197befb813a4ecad686b559e58',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('14429509307efebfdaa05227d84c147450d168c68539351fbc01ed87c916ab2e', bin2hex(Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20TransferAsERC20
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            // Account nonce from the network; zero for this fixture.
            'nonce' => pack('J', 0),
            'gas_price' => pack('J', 42_000_000_000),
            'gas_limit' => pack('J', 78_009),
            // Transaction destination is the token contract, not the recipient.
            'to_address' => '0x6b175474e89094c44da98b954eedeac495271d0f',
            'transaction' => new Proto\Transaction([
                'erc20_transfer' => new Proto\Transaction\ERC20Transfer([
                    'to' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84',
                    // 2 tokens with 18 decimals; unsigned big-endian amount.
                    'amount' => pack('J', 2_000_000_000_000_000_000),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Ethereum);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000',
            bin2hex($output->getData()),
        );
        self::assertSame(
            'f8aa808509c7652400830130b9946b175474e89094c44da98b954eedeac495271d0f80b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec8000025a0724c62ad4fbf47346b02de06e603e013f26f26b56fdc0be7ba3d6273401d98cea0032131cae15da7ddcda66963e8bef51ca0d9962bfef0547d3f02597a4a58c931',
            bin2hex($output->getEncoded()),
        );
        self::assertSame('199a7829fc5149e49b452c2cab76d8fa5a9682fee6e4891b8acb697ac142513e', bin2hex(Hash::keccak256($output->getEncoded())));
    }

    public function testSignTokenTransferEip1559(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TWAnySignerTests.cpp: SignERC20Transfer_1559
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            'nonce' => pack('J', 0),
            'tx_mode' => Proto\TransactionMode::Enveloped,
            'max_inclusion_fee_per_gas' => pack('J', 2_000_000_000),
            'max_fee_per_gas' => pack('J', 3_000_000_000),
            'gas_limit' => pack('J', 78_009),
            // Transaction destination is the token contract, not the recipient.
            'to_address' => '0x6b175474e89094c44da98b954eedeac495271d0f',
            'transaction' => new Proto\Transaction([
                'erc20_transfer' => new Proto\Transaction\ERC20Transfer([
                    'to' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84',
                    // 2 tokens with 18 decimals; unsigned big-endian amount.
                    'amount' => pack('J', 2_000_000_000_000_000_000),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Ethereum);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000',
            bin2hex($output->getData()),
        );
        self::assertSame(
            '02f8b00180847735940084b2d05e00830130b9946b175474e89094c44da98b954eedeac495271d0f80b844a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000c080a0adfcfdf98d4ed35a8967a0c1d78b42adb7c5d831cf5a3272654ec8f8bcd7be2ea011641e065684f6aa476f4fd250aa46cd0b44eccdb0a6e1650d658d1998684cdf',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignERC20Approve(): void
    {
        // Upstream TWAnySignerEthereum::SignERC20Approve, Wallet Core 4.8.7.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            'nonce' => pack('J', 0),
            'gas_price' => pack('J', 42_000_000_000),
            'gas_limit' => pack('J', 78_009),
            'to_address' => '0x6b175474e89094c44da98b954eedeac495271d0f',
            'transaction' => new Proto\Transaction([
                'erc20_approve' => new Proto\Transaction\ERC20Approve([
                    'spender' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84',
                    'amount' => pack('J', 2_000_000_000_000_000_000),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Ethereum);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '095ea7b30000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec80000',
            bin2hex($output->getData()),
        );
        self::assertSame(
            'f8aa808509c7652400830130b9946b175474e89094c44da98b954eedeac495271d0f80b844095ea7b30000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000001bc16d674ec8000025a0d8136d66da1e0ba8c7208d5c4f143167f54b89a0fe2e23440653bcca28b34dc1a049222a79339f1a9e4641cb4ad805c49c225ae704299ffc10627bf41c035c464a',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignERC721Transfer(): void
    {
        // Upstream TWAnySignerEthereum::SignERC721Transfer, Wallet Core 4.8.7.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            'nonce' => pack('J', 0),
            'gas_price' => pack('J', 42_000_000_000),
            'gas_limit' => pack('J', 78_009),
            'to_address' => '0x4e45e92ed38f885d39a733c14f1817217a89d425',
            'transaction' => new Proto\Transaction([
                'erc721_transfer' => new Proto\Transaction\ERC721Transfer([
                    'from' => '0x718046867b5b1782379a14eA4fc0c9b724DA94Fc',
                    'to' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84',
                    'token_id' => pack('J', 600_080_101),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Ethereum);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '23b872dd000000000000000000000000718046867b5b1782379a14ea4fc0c9b724da94fc0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000000000000023c47ee5',
            bin2hex($output->getData()),
        );
        self::assertSame(
            'f8ca808509c7652400830130b9944e45e92ed38f885d39a733c14f1817217a89d42580b86423b872dd000000000000000000000000718046867b5b1782379a14ea4fc0c9b724da94fc0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000000000000023c47ee526a0d38440a4dc140a4100d301eb49fcc35b64439e27d1d8dd9b55823dca04e6e659a03b5f56a57feabc3406f123d6f8198cd7d7e2ced7e2d58d375f076952ecd9ce88',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignERC1155Transfer(): void
    {
        // Upstream TWAnySignerEthereum::SignERC1155Transfer, Wallet Core 4.8.7.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            'nonce' => pack('J', 0),
            'gas_price' => pack('J', 42_000_000_000),
            'gas_limit' => pack('J', 78_009),
            'to_address' => '0x4e45e92ed38f885d39a733c14f1817217a89d425',
            'transaction' => new Proto\Transaction([
                'erc1155_transfer' => new Proto\Transaction\ERC1155Transfer([
                    'from' => '0x718046867b5b1782379a14eA4fc0c9b724DA94Fc',
                    'to' => '0x5322b34c88ed0691971bf52a7047448f0f4efc84',
                    'token_id' => pack('J', 600_080_101),
                    'value' => pack('J', 2_000_000_000_000_000_000),
                    'data' => hex2bin('01020304'),
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Ethereum);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'f242432a000000000000000000000000718046867b5b1782379a14ea4fc0c9b724da94fc0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000000000000023c47ee50000000000000000000000000000000000000000000000001bc16d674ec8000000000000000000000000000000000000000000000000000000000000000000a000000000000000000000000000000000000000000000000000000000000000040102030400000000000000000000000000000000000000000000000000000000',
            bin2hex($output->getData()),
        );
        self::assertSame(
            'f9014a808509c7652400830130b9944e45e92ed38f885d39a733c14f1817217a89d42580b8e4f242432a000000000000000000000000718046867b5b1782379a14ea4fc0c9b724da94fc0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000000000000023c47ee50000000000000000000000000000000000000000000000001bc16d674ec8000000000000000000000000000000000000000000000000000000000000000000a00000000000000000000000000000000000000000000000000000000000000004010203040000000000000000000000000000000000000000000000000000000026a010315488201ac801ce346bffd1570de147615462d7e7db3cf08cf558465c6b79a06643943b24593bc3904a9fda63bb169881730994c973ab80f07d66a698064573',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignAndVerifyMessage(): void
    {
        // EIP-191: message signatures are not transaction signatures.
        $key = PrivateKey::createWithData(hex2bin(str_repeat('46', 32)));
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeySecp256k1(false);
        self::assertNotNull($publicKey);
        $message = 'Wallet Core PHP example';

        $signature = EthereumMessageSigner::signMessage($key, $message);

        self::assertNotNull($signature);
        self::assertNotSame('', $signature);
        self::assertTrue(EthereumMessageSigner::verifyMessage($publicKey, $message, $signature));
        self::assertFalse(EthereumMessageSigner::verifyMessage($publicKey, $message . '!', $signature));
    }

    public function testCompileWithExternalSignature(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TransactionCompilerTests.cpp: CompileWithSignatures.
        // No private key is passed to the compiler; the signature can come from an external signer.
        $input = new Proto\SigningInput([
            'nonce' => pack('J', 11),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            'gas_price' => pack('J', 20_000_000_000),
            'gas_limit' => pack('J', 21_000),
            'to_address' => '0x3535353535353535353535353535353535353535',
            'transaction' => new Proto\Transaction([
                'transfer' => new Proto\Transaction\Transfer(['amount' => pack('J', 1_000_000_000_000_000_000)]),
            ]),
        ]);
        $serialized = $input->serializeToString();
        $preimage = TransactionCompiler::preImageHashes(CoinType::Ethereum, $serialized);
        self::assertNotNull($preimage);
        $pre = new \TW\TxCompiler\Proto\PreSigningOutput();
        $pre->mergeFromString($preimage);
        self::assertSame(SigningError::OK, $pre->getError(), $pre->getErrorMessage());
        self::assertSame('15e180a6274b2f6a572b9b51823fce25ef39576d10188ecdcd7de44526c47217', bin2hex($pre->getDataHash()));
        $publicKeyBytes = hex2bin('044bc2a31265153f07e70e0bab08724e6b85e217f8cd628ceb62974247bb493382ce28cab79ad7119ee1ad3ebcdb98a16805211530ecc6cfefa1b88e6dff99232a');
        $signature = hex2bin('360a84fb41ad07f07c845fedc34cde728421803ebbaae392fc39c116b29fc07b53bd9d1376e15a191d844db458893b928f3efbfee90c9febf51ab84c9796677900');
        $publicKey = PublicKey::createWithData($publicKeyBytes, PublicKeyType::SECP256k1Extended);
        self::assertNotNull($publicKey);
        self::assertTrue($publicKey->verify($signature, $pre->getDataHash()));
        $signatures = DataVector::createWithData($signature);
        $publicKeys = DataVector::createWithData($publicKeyBytes);
        self::assertNotNull($signatures);
        self::assertNotNull($publicKeys);

        $compiled = TransactionCompiler::compileWithSignatures(CoinType::Ethereum, $serialized, $signatures, $publicKeys);
        self::assertNotNull($compiled);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($compiled);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'f86c0b8504a817c800825208943535353535353535353535353535353535353535880de0b6b3a76400008025a0360a84fb41ad07f07c845fedc34cde728421803ebbaae392fc39c116b29fc07ba053bd9d1376e15a191d844db458893b928f3efbfee90c9febf51ab84c97966779',
            bin2hex($output->getEncoded()),
        );
    }

    public function testRejectMissingExternalSignature(): void
    {
        // Wallet Core 4.8.7: tests/chains/Ethereum/TransactionCompilerTests.cpp: CompileWithSignatures.
        // No private key is passed to the compiler; the signature can come from an external signer.
        $input = new Proto\SigningInput([
            'nonce' => pack('J', 11),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            'gas_price' => pack('J', 20_000_000_000),
            'gas_limit' => pack('J', 21_000),
            'to_address' => '0x3535353535353535353535353535353535353535',
            'transaction' => new Proto\Transaction([
                'transfer' => new Proto\Transaction\Transfer(['amount' => pack('J', 1_000_000_000_000_000_000)]),
            ]),
        ]);
        $serialized = $input->serializeToString();
        $empty = DataVector::create();
        self::assertNotNull($empty);

        $failed = TransactionCompiler::compileWithSignatures(CoinType::Ethereum, $serialized, $empty, $empty);
        self::assertNotNull($failed);
        $rejected = new Proto\SigningOutput();
        $rejected->mergeFromString($failed);

        self::assertSame(SigningError::Error_signatures_count, $rejected->getError());
        self::assertSame('', $rejected->getEncoded());
    }

    public function testRejectInvalidTokenAddress(): void
    {
        // Wallet Core 4.8.7: SignERC20TransferInvalidAddress.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('608dcb1742bb3fb7aec002074e3420e4fab7d00cced79ccdac53ed5b27138151'),
            'chain_id' => pack('J', (int) CoinType::chainId(CoinType::Ethereum)),
            'gas_price' => pack('J', 42_000_000_000),
            'gas_limit' => pack('J', 78_009),
            'to_address' => '0xdeadbeef',
            'transaction' => new Proto\Transaction([
                'erc20_transfer' => new Proto\Transaction\ERC20Transfer([
                    'to' => '0xdeadbeef',
                    'amount' => pack('J', 2_000_000_000_000_000_000),
                ]),
            ]),
        ]);

        $data = AnySigner::sign($input->serializeToString(), CoinType::Ethereum);
        self::assertNotNull($data);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($data);

        self::assertSame('', $output->getEncoded());
    }

    #[DataProvider('invalidPrivateKeyProvider')]
    public function testRejectInvalidPrivateKey(string $raw): void
    {
        $valid = PrivateKey::isValid($raw, \WalletCore\Curve::SECP256k1);

        self::assertFalse($valid);
    }

    public function testRejectImportZeroPrivateKey(): void
    {
        $raw = str_repeat("\0", 32);

        $key = PrivateKey::createWithData($raw);

        self::assertNull($key);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0x8f348F300873Fd5DA36950B2aC75a26584584feE', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }

    public static function invalidPrivateKeyProvider(): array
    {
        return [
            'zero key' => [str_repeat("\0", 32)],
            'invalid length' => ["\x01"],
        ];
    }
}
