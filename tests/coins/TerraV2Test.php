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
final class TerraV2Test extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::TerraV2;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('LUNA', $symbol);
        self::assertSame(6, $decimals);
        self::assertSame('phoenix-1', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::TerraV2);

        self::assertSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::TerraV2;
        $path = "m/44'/330'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::TerraV2;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/330'/0'/0/1");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::TerraV2;
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
        $coin = CoinType::TerraV2;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/330'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::TerraV2;
        $expected = 'terra1rh402g98t7sly8trzqw5cyracntlep6qe3smug';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::TerraV2);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::TerraV2;
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
            'chain_id' => CoinType::chainId(CoinType::TerraV2),
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
        self::assertNotNull($bytes);
        $send = $input->getMessages()[0]->getSendCoinsMessage();
        self::assertStringContainsString($send->getFromAddress(), $bytes);
        self::assertStringContainsString($send->getToAddress(), $bytes);
        self::assertStringContainsString($send->getAmounts()[0]->getDenom(), $bytes);
        self::assertStringContainsString($send->getAmounts()[0]->getAmount(), $bytes);
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Cosmos/TerraV2/SignerTests.cpp: SignWasmTransferTx
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('cf08ee8493e6f6a53f9721b9045576e80f371c0e36d08fdaf78b27a7afd8e616'),
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::TerraV2),
            'account_number' => 3_407_705,
            'sequence' => 3,
            'fee' => new Proto\Fee([
                'gas' => 200_000,
                'amounts' => [new Proto\Amount(['denom' => 'uluna', 'amount' => '3000'])],
            ]),
            'messages' => [new Proto\Message([
                'wasm_execute_contract_transfer_message' => new Proto\Message\WasmExecuteContractTransfer([
                    'sender_address' => 'terra18wukp84dq227wu4mgh0jm6n9nlnj6rs82pp9wf',
                    'contract_address' => 'terra14z56l0fp2lsf86zy3hty2z47ezkhnthtr9yq76',
                    'recipient_address' => 'terra1jlgaqy9nvn2hf5t2sra9ycz8s77wnf9l0kmgcp',
                    'amount' => ltrim(pack('J', 250_000), "\0"),
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::TerraV2);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '88119b41a8fe8ec5c4ebf16cb03ddf0bbed03b1a658bd1aab0f79af8aa0dc8c2048158fcf478a2fa85356c01104b8a95d12684d95e91372db8b827054c845b71',
            bin2hex($output->getSignature()),
        );
        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            'CuUBCuIBCiQvY29zbXdhc20ud2FzbS52MS5Nc2dFeGVjdXRlQ29udHJhY3QSuQEKLHRlcnJhMTh3dWtwODRkcTIyN3d1NG1naDBqbTZuOW5sbmo2cnM4MnBwOXdmEix0ZXJyYTE0ejU2bDBmcDJsc2Y4Nnp5M2h0eTJ6NDdlemtobnRodHI5eXE3NhpbeyJ0cmFuc2ZlciI6eyJhbW91bnQiOiIyNTAwMDAiLCJyZWNpcGllbnQiOiJ0ZXJyYTFqbGdhcXk5bnZuMmhmNXQyc3JhOXljejhzNzd3bmY5bDBrbWdjcCJ9fRJnClAKRgofL2Nvc21vcy5jcnlwdG8uc2VjcDI1NmsxLlB1YktleRIjCiEDcGY6x7D5iSlv61zln7pKUNfpThziVt/yJRRZyizZrAASBAoCCAEYAxITCg0KBXVsdW5hEgQzMDAwEMCaDBpAiBGbQaj+jsXE6/FssD3fC77QOxpli9GqsPea+KoNyMIEgVj89Hii+oU1bAEQS4qV0SaE2V6RNy24uCcFTIRbcQ==',
            $transaction['tx_bytes'],
        );
    }

    public function testSignTokenTransferBank(): void
    {
        // Wallet Core 4.8.7: src/proto/Cosmos.proto: Message.Send and Amount (adapted offline bank transfer)
        $coin = CoinType::TerraV2;
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
            'chain_id' => CoinType::chainId(CoinType::TerraV2),
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
        self::assertStringContainsString('/cosmos.bank.v1beta1.MsgSend', $txBytes);
        self::assertStringContainsString($tokenDenom, $txBytes);
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
