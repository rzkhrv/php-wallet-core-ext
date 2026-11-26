<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Cosmos\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base64;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class NativeEvmosTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::NativeEvmos;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('EVMOS', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('evmos_9001-2', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::NativeEvmos);

        self::assertSame('evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::NativeEvmos;
        $path = "m/44'/60'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::NativeEvmos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/60'/0'/0/1");
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/60'/0'/0/1.
        self::assertSame('729e54c42afc8b7aa78c4e025291f5cb3421ba50b3a26e53c97d30fbf89405c9', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('evmos1yzfr80elxm887rnvp9f5uyf6pdcqa69s9vkn7w', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::NativeEvmos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::NativeEvmos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/60'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::NativeEvmos;
        $expected = 'evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::NativeEvmos);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::NativeEvmos;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d';
        $key = $wallet->getKey($coin, "m/44'/60'/0'/0/0");
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
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::NativeEvmos),
            'account_number' => '1',
            'sequence' => '0',
            'fee' => new Proto\Fee([
                'gas' => '200000',
                'amounts' => [
                    new Proto\Amount([
                        'denom' => 'aevmos',
                        'amount' => '1000',
                    ]),
                ],
            ]),
            'messages' => [
                new Proto\Message([
                    'send_coins_message' => new Proto\Message\Send([
                        'from_address' => $sender,
                        'to_address' => $recipient,
                        'amounts' => [
                            new Proto\Amount([
                                'denom' => 'aevmos',
                                'amount' => '1000000',
                            ]),
                        ],
                    ]),
                ]),
            ],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(64, strlen($output->getSignature()));
        $envelope = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        $bytes = Base64::decode($envelope['tx_bytes']);
        self::assertIsString($bytes);
        // Independent Cosmos SDK SignDoc / TxRaw reference (deterministic secp256k1).
        self::assertSame(
            '44c4a6b8bda2487529f6ea957a67ba608957acf48693f23618dbcf5fc826b71c4b830be1d2f3c7105256b4b13dcd22d362c3fc115fc8fc3209c7b7fff8dde1e3',
            bin2hex($output->getSignature()),
        );
        self::assertSame('3717d386cc8a56a01e8b09fd148c61382d2f62467f6b9fa1bd8d9e4f3a3bb337', hash('sha256', $bytes));
    }

    public function testSignTokenTransferBank(): void
    {
        // Wallet Core 4.8.7: src/proto/Cosmos.proto: Message.Send and Amount (adapted offline bank transfer)
        $coin = CoinType::NativeEvmos;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d';
        $key = $wallet->getKey($coin, "m/44'/60'/0'/0/0");
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

        // Local bank transfer of an IBC token, not an IBC packet transfer.
        // The denomination hash is synthetic; obtain the real denom from chain state.
        $tokenDenom = 'ibc/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::NativeEvmos),
            'account_number' => '1',
            'sequence' => '0',
            'fee' => new Proto\Fee([
                'gas' => '200000',
                'amounts' => [
                    new Proto\Amount([
                        'denom' => 'aevmos',
                        'amount' => '1000',
                    ]),
                ],
            ]),
            'messages' => [
                new Proto\Message([
                    'send_coins_message' => new Proto\Message\Send([
                        'from_address' => $sender,
                        'to_address' => $recipient,
                        'amounts' => [
                            new Proto\Amount([
                                'denom' => $tokenDenom,
                                'amount' => '1000000',
                            ]),
                        ],
                    ]),
                ]),
            ],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getSignature());
        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        $txBytes = Base64::decode($transaction['tx_bytes']);
        self::assertIsString($txBytes);
        // Independent Cosmos SDK SignDoc / TxRaw reference (deterministic secp256k1).
        self::assertSame(
            '0dec30affcdc5c9047f553c8b52aa07632b90d1c0af6bf5f002659eafa55393864a2b972851069ac27a9a8e6e169a1b62eeafd62ac858d1cbc62c31ba69193a1',
            bin2hex($output->getSignature()),
        );
        self::assertSame('58cd401d0f5b07b28aefcce78fde051677c689df51617a4e86bca87192455d3f', hash('sha256', $txBytes));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['evmos13u6g7vqgw074mgmf2ze2cadzvkz9snlwstd20d', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
