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
final class StrideTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Stride;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('STRD', $symbol);
        self::assertSame(6, $decimals);
        self::assertSame('stride-1', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Stride);

        self::assertSame('stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Stride;
        $path = "m/44'/118'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Stride;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/118'/0'/0/1");
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/118'/0'/0/1.
        self::assertSame('6c9c31dbacc911664b4683c16956ca708be217d96f4b9b7a0db6fc35413fb4ae', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('stride1tmvlpttlwfmtatycgdyvyeuh989udrdrzhpt97', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Stride;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Stride;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/118'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Stride;
        $expected = 'stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Stride);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Stride;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl';
        $key = $wallet->getKey($coin, "m/44'/118'/0'/0/0");
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
            'chain_id' => CoinType::chainId(CoinType::Stride),
            'account_number' => '1',
            'sequence' => '0',
            'fee' => new Proto\Fee([
                'gas' => '200000',
                'amounts' => [
                    new Proto\Amount([
                        'denom' => 'ustrd',
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
                                'denom' => 'ustrd',
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
            'a89a3250c9a96e69e7428526fb3e7553b51107c025465b23cc942cdb5f8f24ca135d1669060f5752d2939323471abbfbe08810e580995fd01c75a34c3bf4d051',
            bin2hex($output->getSignature()),
        );
        self::assertSame('15929d98347eefb40fedeb3a5d048accba40d8c7d7f2b2e954bb512dbcfd59e9', hash('sha256', $bytes));
    }

    public function testSignTokenTransferBank(): void
    {
        // Wallet Core 4.8.7: src/proto/Cosmos.proto: Message.Send and Amount (adapted offline bank transfer)
        $coin = CoinType::Stride;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl';
        $key = $wallet->getKey($coin, "m/44'/118'/0'/0/0");
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
            'chain_id' => CoinType::chainId(CoinType::Stride),
            'account_number' => '1',
            'sequence' => '0',
            'fee' => new Proto\Fee([
                'gas' => '200000',
                'amounts' => [
                    new Proto\Amount([
                        'denom' => 'ustrd',
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
            'a72d3b7164be4967b428a186b9c6bcdf360b3a2ead1cddeda47579ee48f8732033c2862af98531c2e6d9a30991a72d7b9a3ae31404632c8128551da76039ef2a',
            bin2hex($output->getSignature()),
        );
        self::assertSame('d9cdcde9d2797d25b6721010b6cf50510fa76c4b1133c6ec6be30dc1a85cb818', hash('sha256', $txBytes));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['stride142j9u5eaduzd7faumygud6ruhdwme98qn029zl', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
