<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Tezos\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;
use WalletCore\TezosMessageSigner;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class TezosTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Tezos;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('XTZ', $symbol);
        self::assertSame(6, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Tezos);

        self::assertSame('tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Tezos;
        $path = "m/44'/1729'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Tezos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/1729'/0'/1'");
        self::assertNotNull($key);
        // Independent BIP39/SLIP-0010 reference: m/44'/1729'/0'/1'.
        self::assertSame('fe85640873060ca5612858365873d5c7e235bf2f100eb1122ea3df7ff2e5616c', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('tz1NQfTTCCQsTxe3CuE7AyYAW4sBTpvXXCAx', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Tezos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Tezos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/1729'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Tezos;
        $expected = 'tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Tezos);

        self::assertSame($expected, $valid);
    }

    public function testSignAndVerifyMessage(): void
    {
        // Wallet Core: Tezos/MessageSignerTests.cpp.
        $key = PrivateKey::createWithData(hex2bin('91b4fb8d7348db2e7de2693f58ce1cceb966fa960739adac1d9dba2cbaa0940a'));
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyEd25519();
        self::assertNotNull($publicKey);
        $payload = TezosMessageSigner::inputToPayload('Tezos Signed Message: testUrl 2023-02-08T10:36:18.454Z Hello World');
        self::assertNotNull($payload);

        $signature = TezosMessageSigner::signMessage($key, $payload);

        self::assertSame('edsigu3se2fcEJUCm1aqxjzbHdf7Wsugr4mLaA9YM2UVZ9Yy5meGv87VqHN3mmDeRwApTj1JKDaYjqmLZifSFdWCqBoghqaowwJ', $signature);
        self::assertTrue(TezosMessageSigner::verifyMessage($publicKey, $payload, $signature));
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Tezos;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX';
        $key = $wallet->getKey($coin, "m/44'/1729'/0'/0'");
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
            'operation_list' => new Proto\OperationList([
                'branch' => 'BL8euoCWqNCny9AR3AKjnpi38haYMxjei1ZqNHuXMn19JSQnoWp',
                'operations' => [
                    new Proto\Operation([
                        'source' => $sender,
                        'counter' => '1',
                        'fee' => '1000',
                        'gas_limit' => '10400',
                        'storage_limit' => '0',
                        'kind' => Proto\Operation\OperationKind::TRANSACTION,
                        'transaction_operation_data' => new Proto\TransactionOperationData([
                            'destination' => $recipient,
                            'amount' => '1000000',
                        ]),
                    ]),
                ],
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Tezos/TWAnySignerTests.cpp: SignFA12
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('363265a0b3f06661001cab8b4f3ca8fd97ae70608184979cf7300836f57ec2d6'),
            'operation_list' => new Proto\OperationList([
                'branch' => 'BL8euoCWqNCny9AR3AKjnpi38haYMxjei1ZqNHuXMn19JSQnoWp',
                'operations' => [new Proto\Operation([
                    'source' => 'tz1ioz62kDw6Gm5HApeQtc1PGmN2wPBtJKUP',
                    'fee' => 100_000,
                    'counter' => 2_993_172,
                    'gas_limit' => 100_000,
                    'storage_limit' => 0,
                    'kind' => Proto\Operation\OperationKind::TRANSACTION,
                    'transaction_operation_data' => new Proto\TransactionOperationData([
                        'amount' => 0,
                        'destination' => 'KT1EwXFWoG9bYebmF4pYw72aGjwEnBWefgW5',
                        'parameters' => new Proto\OperationParameters([
                            'fa12_parameters' => new Proto\FA12Parameters([
                                'entrypoint' => 'transfer',
                                'from' => 'tz1ioz62kDw6Gm5HApeQtc1PGmN2wPBtJKUP',
                                'to' => 'tz1ioz62kDw6Gm5HApeQtc1PGmN2wPBtJKUP',
                                'value' => '123',
                            ]),
                        ]),
                    ]),
                ])],
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Tezos);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '3756ef37b1be849e3114643f0aa5847cabf9a896d3bfe4dd51448de68e91da016c00fe2ce0cccc0214af521ad60c140c5589b4039247a08d0694d8b601a08d0600000145bd8a65cc48159d8ea60a55df735b7c5ad45f0e00ffff087472616e736665720000005907070100000024747a31696f7a36326b447736476d35484170655174633150476d4e32775042744a4b555007070100000024747a31696f7a36326b447736476d35484170655174633150476d4e32775042744a4b555000bb012914d768155fba2df319a81136e8e3e573b9cadb1676834490c90212615d271da029b6b0531e290e9063bcdb40bea43627af048b18e036f02be2b6b22fc8b307',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignTokenTransferFa2(): void
    {
        // Wallet Core 4.8.7: tests/chains/Tezos/TWAnySignerTests.cpp: SignFA2
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('363265a0b3f06661001cab8b4f3ca8fd97ae70608184979cf7300836f57ec2d6'),
            'operation_list' => new Proto\OperationList([
                'branch' => 'BKvEAX9HXfJZWYfTQbR1C7B3ADoKY6a1aKVRF7qQqvc9hS8Rr3m',
                'operations' => [new Proto\Operation([
                    'source' => 'tz1ioz62kDw6Gm5HApeQtc1PGmN2wPBtJKUP',
                    'fee' => 100_000,
                    'counter' => 2_993_173,
                    'gas_limit' => 100_000,
                    'storage_limit' => 0,
                    'kind' => Proto\Operation\OperationKind::TRANSACTION,
                    'transaction_operation_data' => new Proto\TransactionOperationData([
                        'destination' => 'KT1DYk1XDzHredJq1EyNkDindiWDqZyekXGj',
                        'amount' => 0,
                        'parameters' => new Proto\OperationParameters([
                            'fa2_parameters' => new Proto\FA2Parameters([
                                'entrypoint' => 'transfer',
                                'txs_object' => [new Proto\TxObject([
                                    'from' => 'tz1ioz62kDw6Gm5HApeQtc1PGmN2wPBtJKUP',
                                    'txs' => [new Proto\Txs([
                                        'to' => 'tz1ioz62kDw6Gm5HApeQtc1PGmN2wPBtJKUP',
                                        'token_id' => '0',
                                        'amount' => '10',
                                    ])],
                                ])],
                            ]),
                        ]),
                    ]),
                ])],
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Tezos);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '1b1f9345dc9f77bd24b09034d1d2f9a28f02ac837f49db54b8d68341f53dc4b76c00fe2ce0cccc0214af521ad60c140c5589b4039247a08d0695d8b601a08d0600000136767f88850bae28bfb9f46b73c5e87ede4de12700ffff087472616e7366657200000066020000006107070100000024747a31696f7a36326b447736476d35484170655174633150476d4e32775042744a4b5550020000003107070100000024747a31696f7a36326b447736476d35484170655174633150476d4e32775042744a4b555007070000000a552d24710d6c59383286700c6c2917b25a6c1fa8b587e593c289dd47704278796792f1e522c1623845ec991e292b0935445e6994850bd03f035a006c5ed93806',
            bin2hex($output->getEncoded()),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['tz1acnY9VbMagps26Kj3RfoGRWD9nYG5qaRX', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
