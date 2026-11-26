<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Tron\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;
use WalletCore\TronMessageSigner;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class TronTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Tron;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('TRX', $symbol);
        self::assertSame(6, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Tron);

        self::assertSame('TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujio', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Tron;
        $path = "m/44'/195'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujio', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Tron;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujio', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Tron;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujio', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Tron;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/195'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujio', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Tron;
        $expected = 'TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujio';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Tron);

        self::assertSame($expected, $valid);
    }

    public function testSignAndVerifyMessage(): void
    {
        // Wallet Core: TronMessageSignerTests.cpp.
        $key = PrivateKey::createWithData(hex2bin('75065f100e38d3f3b4c5c4235834ba8216de62272a4f03532c44b31a5734360a'));
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey(CoinType::Tron);
        self::assertNotNull($publicKey);
        $message = 'Hello World';

        $signature = TronMessageSigner::signMessage($key, $message);

        self::assertSame('bc0753c070cc55693097df11bc11e1a7c4bd5e1a40b9dc94c75568e59bcc9d6b50a7873ef25b469e494490a54de37327b4bc7fc825c81a377b555e34fb7261ba1c', $signature);
        self::assertTrue(TronMessageSigner::verifyMessage($publicKey, $message, $signature));
    }

    public function testSignTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/Tron/SignerTests.cpp: SignTransfer
        $coin = CoinType::Tron;
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('2d8f68944bdbfbc0769542fba8fc2d2a3de67393334471624364c7006da2aa54'),
            'transaction' => new Proto\Transaction([
                'transfer' => new Proto\TransferContract([
                    'owner_address' => 'TJRyWwFs9wTFGZg3JbrVriFbNfCug5tDeC',
                    'to_address' => 'THTR75o8xXAgCTQqpiot2AFRAjvW1tSbVV',
                    'amount' => 2_000_000,
                ]),
                'timestamp' => 1_539_295_479_000,
                'expiration' => 1539295479000 + 10 * 60 * 60 * 1000,
                'block_header' => new Proto\BlockHeader([
                    'timestamp' => 1_539_295_479_000,
                    'tx_trie_root' => hex2bin('64288c2db0641316762a99dbb02ef7c90f968b60f9f2e410835980614332f86d'),
                    'parent_hash' => hex2bin('00000000002f7b3af4f5f8b9e23a30c530f719f165b742e7358536b280eead2d'),
                    'number' => 3_111_739,
                    'witness_address' => hex2bin('415863f6091b8e71766da808b1dd3159790f61de7d'),
                    'version' => 3,
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('dc6f6d9325ee44ab3c00528472be16e1572ab076aa161ccd12515029869d0451', bin2hex($output->getId()));
        self::assertSame(
            'ede769f6df28aefe6a846be169958c155e23e7e5c9621d2e8dce1719b4d952b63e8a8bf9f00e41204ac1bf69b1a663dacdf764367e48e4a5afcd6b055a747fb200',
            bin2hex($output->getSignature()),
        );
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Tron/SignerTests.cpp: SignTransferTrc20Contract
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('2d8f68944bdbfbc0769542fba8fc2d2a3de67393334471624364c7006da2aa54'),
            'transaction' => new Proto\Transaction([
                'transfer_trc20_contract' => new Proto\TransferTRC20Contract([
                    'owner_address' => 'TJRyWwFs9wTFGZg3JbrVriFbNfCug5tDeC',
                    'contract_address' => 'THTR75o8xXAgCTQqpiot2AFRAjvW1tSbVV',
                    'to_address' => 'TW1dU4L3eNm7Lw8WvieLKEHpXWAussRG9Z',
                    'amount' => pack('n', 1_000),
                ]),
                'timestamp' => 1_539_295_479_000,
                'block_header' => new Proto\BlockHeader([
                    'timestamp' => 1_539_295_479_000,
                    'tx_trie_root' => hex2bin('64288c2db0641316762a99dbb02ef7c90f968b60f9f2e410835980614332f86d'),
                    'parent_hash' => hex2bin('00000000002f7b3af4f5f8b9e23a30c530f719f165b742e7358536b280eead2d'),
                    'number' => 3_111_739,
                    'witness_address' => hex2bin('415863f6091b8e71766da808b1dd3159790f61de7d'),
                    'version' => 3,
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Tron);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('0d644290e3cf554f6219c7747f5287589b6e7e30e1b02793b48ba362da6a5058', bin2hex($output->getId()));
        self::assertSame(
            'bec790877b3a008640781e3948b070740b1f6023c29ecb3f7b5835433c13fc5835e5cad3bd44360ff2ddad5ed7dc9d7dee6878f90e86a40355b7697f5954b88c01',
            bin2hex($output->getSignature()),
        );
    }

    public function testSignTokenTransferTrc10(): void
    {
        // Wallet Core 4.8.7: tests/chains/Tron/SignerTests.cpp: SignTransferAsset
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('2d8f68944bdbfbc0769542fba8fc2d2a3de67393334471624364c7006da2aa54'),
            'transaction' => new Proto\Transaction([
                'transfer_asset' => new Proto\TransferAssetContract([
                    'owner_address' => 'TJRyWwFs9wTFGZg3JbrVriFbNfCug5tDeC',
                    'to_address' => 'THTR75o8xXAgCTQqpiot2AFRAjvW1tSbVV',
                    'amount' => 4,
                    'asset_name' => '1000959',
                ]),
                'timestamp' => 1_539_295_479_000,
                'expiration' => 1_541_926_116_000,
                'block_header' => new Proto\BlockHeader([
                    'timestamp' => 1_541_890_116_000,
                    'tx_trie_root' => hex2bin('845ab51bf63c2c21ee71a4dc0ac3781619f07a7cd05e1e0bd8ba828979332ffa'),
                    'parent_hash' => hex2bin('00000000003cb800a7e69e9144e3d16f0cf33f33a95c7ce274097822c67243c1'),
                    'number' => 3_979_265,
                    'witness_address' => hex2bin('41b487cdc02de90f15ac89a68c82f44cbfe3d915ea'),
                    'version' => 3,
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Tron);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('546a3d07164c624809cf4e564a083a7a7974bb3c4eff6bb3e278b0ca21083fcb', bin2hex($output->getId()));
        self::assertSame(
            '77f5eabde31e739d34a66914540f1756981dc7d782c9656f5e14e53b59a15371603a183aa12124adeee7991bf55acc8e488a6ca04fb393b1a8ac16610eeafdfc00',
            bin2hex($output->getSignature()),
        );
    }

    public function testSignTransferWithMemo(): void
    {
        // Wallet Core 4.8.7: SignTransferWithMemo; timestamps are milliseconds.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('7c2108a30f6f69f8dce72a7df897eabadfe9810eee6976b43bdf8c0b0d35337d'),
            'transaction' => new Proto\Transaction([
                'transfer' => new Proto\TransferContract([
                    'owner_address' => 'TFnYQCt892UNjn67pjAULTSTkB7YvqsnPp',
                    'to_address' => 'TBUCzgc29vykkvFaEG2mgRtxKvaKe6skwX',
                    'amount' => 100_000, // sun
                ]),
                'timestamp' => 1_730_827_017_000,
                'expiration' => 1730827017000 + 36000000,
                'memo' => 'Test memo',
                'block_header' => new Proto\BlockHeader([
                    'timestamp' => 1_730_827_017_000,
                    'tx_trie_root' => hex2bin('a94f115089893f37336baf32dbf6cb7d06adc13cf6bf046d9bc22748bd72e7a6'),
                    'parent_hash' => hex2bin('0000000003fa27db7d67f93920f64733532412ab6a71eb4089dc48c8ff5e182c'),
                    'number' => 66_725_852,
                    'witness_address' => hex2bin('4167e39013be3cdd3814bed152d7439fb5b6791409'),
                    'version' => 30,
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Tron);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('20321755964d6ec5bcfc9ebfb15faeb043787ae599fff44442962e12e1c357f1', bin2hex($output->getId()));
        self::assertSame(
            '6fcee79c61f660ec689299f77924f32b5020b4c41593056052ef07d640cc799325103fab130c8691e8a224c96cd0704a698ac356ff789a543c284605668bf38000',
            bin2hex($output->getSignature()),
        );
    }

    public function testSignTokenTransferWithFeeLimit(): void
    {
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('2d8f68944bdbfbc0769542fba8fc2d2a3de67393334471624364c7006da2aa54'),
            'transaction' => new Proto\Transaction([
                'transfer_trc20_contract' => new Proto\TransferTRC20Contract([
                    'owner_address' => 'TJRyWwFs9wTFGZg3JbrVriFbNfCug5tDeC',
                    'contract_address' => 'THTR75o8xXAgCTQqpiot2AFRAjvW1tSbVV',
                    'to_address' => 'TW1dU4L3eNm7Lw8WvieLKEHpXWAussRG9Z',
                    'amount' => pack('n', 1_000),
                ]),
                'timestamp' => 1_539_295_479_000,
                'expiration' => 1_539_295_539_000, // milliseconds
                'fee_limit' => 100_000_000, // sun: maximum 100 TRX
                'block_header' => new Proto\BlockHeader([
                    'timestamp' => 1_539_295_479_000,
                    'tx_trie_root' => hex2bin('64288c2db0641316762a99dbb02ef7c90f968b60f9f2e410835980614332f86d'),
                    'parent_hash' => hex2bin('00000000002f7b3af4f5f8b9e23a30c530f719f165b742e7358536b280eead2d'),
                    'number' => 3_111_739,
                    'witness_address' => hex2bin('415863f6091b8e71766da808b1dd3159790f61de7d'),
                    'version' => 3,
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Tron);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        $transaction = json_decode($output->getJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100000000, (int) $transaction['raw_data']['fee_limit']);
        self::assertSame(1539295539000, (int) $transaction['raw_data']['expiration']);
        self::assertSame(32, strlen($output->getId()));
        self::assertSame(65, strlen($output->getSignature()));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujio', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['TQ5NMqJjhpQGK7YJbESKtNCo86PJ89ujiq', false],
        ];
    }
}
