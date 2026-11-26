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
final class TerraTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Terra;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('LUNC', $symbol);
        self::assertSame(6, $decimals);
        self::assertSame('columbus-5', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Terra);

        self::assertSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Terra;
        $path = "m/44'/330'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Terra;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/330'/0'/0/1.
        self::assertSame('226b73898a900d3e187d5da2a8c5eb3c0670bfc846b80e03a67e621e4a37e523', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('terra1gsgu4qpm0k5t7k0wz39ne0skuh0dukfsxnf73y', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Terra;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Terra;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/330'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Terra;
        $expected = 'terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Terra);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Terra;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug';
        $key = $wallet->getKey($coin, "m/44'/330'/0'/0/0");
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
            'chain_id' => CoinType::chainId(CoinType::Terra),
            'account_number' => '1',
            'sequence' => '0',
            'fee' => new Proto\Fee([
                'gas' => '200000',
                'amounts' => [
                    new Proto\Amount([
                        'denom' => 'uluna',
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
                                'denom' => 'uluna',
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
            'c13537081290da3dcd5b200ce7ce95bc734339cd32e7e0d867a16006f238533773e82e4275c64f3b4ca9d1ed8400aeac8176c24bef2e3f06e96e0d849887e0d3',
            bin2hex($output->getSignature()),
        );
        self::assertSame('e778cb80b1da38c43d5975692c6815f9c9bd73ff93f433cba18a62004795ce5f', hash('sha256', $bytes));
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Cosmos/Terra/SignerTests.cpp: SignWasmTransferTxProtobuf_9FF3F0
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('cf08ee8493e6f6a53f9721b9045576e80f371c0e36d08fdaf78b27a7afd8e616'),
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::Terra),
            'account_number' => 3_407_705,
            'sequence' => 3,
            'fee' => new Proto\Fee([
                'gas' => 200_000,
                'amounts' => [new Proto\Amount(['denom' => 'uluna', 'amount' => '3000'])],
            ]),
            'messages' => [new Proto\Message([
                'wasm_terra_execute_contract_transfer_message' => new Proto\Message\WasmTerraExecuteContractTransfer([
                    'sender_address' => 'terra18wukp84dq227wu4mgh0jm6n9nlnj6rs82pp9wf',
                    'contract_address' => 'terra14z56l0fp2lsf86zy3hty2z47ezkhnthtr9yq76',
                    'recipient_address' => 'terra1jlgaqy9nvn2hf5t2sra9ycz8s77wnf9l0kmgcp',
                    'amount' => ltrim(pack('J', 250_000), "\0"),
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Terra);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '1aa6b20430b3c7d87985770146a1a8a96f6188ca15edea899bcfe58c9c6da6391048ea016dd0cf88ca6620cd9cfac35496e7647ddb6c9a7c39e7ef25dfbad470',
            bin2hex($output->getSignature()),
        );
        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            'CucBCuQBCiYvdGVycmEud2FzbS52MWJldGExLk1zZ0V4ZWN1dGVDb250cmFjdBK5AQosdGVycmExOHd1a3A4NGRxMjI3d3U0bWdoMGptNm45bmxuajZyczgycHA5d2YSLHRlcnJhMTR6NTZsMGZwMmxzZjg2enkzaHR5Mno0N2V6a2hudGh0cjl5cTc2Glt7InRyYW5zZmVyIjp7ImFtb3VudCI6IjI1MDAwMCIsInJlY2lwaWVudCI6InRlcnJhMWpsZ2FxeTludm4yaGY1dDJzcmE5eWN6OHM3N3duZjlsMGttZ2NwIn19EmcKUApGCh8vY29zbW9zLmNyeXB0by5zZWNwMjU2azEuUHViS2V5EiMKIQNwZjrHsPmJKW/rXOWfukpQ1+lOHOJW3/IlFFnKLNmsABIECgIIARgDEhMKDQoFdWx1bmESBDMwMDAQwJoMGkAaprIEMLPH2HmFdwFGoaipb2GIyhXt6ombz+WMnG2mORBI6gFt0M+IymYgzZz6w1SW52R922yafDnn7yXfutRw',
            $transaction['tx_bytes'],
        );
    }

    public function testSignTokenTransferBank(): void
    {
        // Wallet Core 4.8.7: src/proto/Cosmos.proto: Message.Send and Amount (adapted offline bank transfer)
        $coin = CoinType::Terra;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug';
        $key = $wallet->getKey($coin, "m/44'/330'/0'/0/0");
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
            'chain_id' => CoinType::chainId(CoinType::Terra),
            'account_number' => '1',
            'sequence' => '0',
            'fee' => new Proto\Fee([
                'gas' => '200000',
                'amounts' => [
                    new Proto\Amount([
                        'denom' => 'uluna',
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
            'fa525e4cd7077d69825d4de54fd3a8248f7089eca9c35de70dbcf1fb62474e3f29c6c11edded108ccfc444dc3b2b01de2c2bd82c06195e3a8a082041d3fc2b8f',
            bin2hex($output->getSignature()),
        );
        self::assertSame('0e0540cc1082de21f371b6467d95c9f3952e388f9561fc383891647a7798140b', hash('sha256', $txBytes));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
