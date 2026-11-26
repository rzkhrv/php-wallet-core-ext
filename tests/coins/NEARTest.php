<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\NEAR\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base58;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\NEARAccount;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class NEARTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::NEAR;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('NEAR', $symbol);
        self::assertSame(24, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::NEAR);

        self::assertSame('0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::NEAR;
        $path = "m/44'/397'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::NEAR;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/397'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::NEAR;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::NEAR;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/397'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::NEAR;
        $expected = '0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::NEAR);

        self::assertSame($expected, $valid);
    }

    public function testParseNamedAccount(): void
    {
        $name = 'test.near';

        $account = NEARAccount::createWithString($name);

        self::assertNotNull($account);
        self::assertSame($name, $account->description());
    }

    public function testAccountFromPublicAddress(): void
    {
        // Wallet Core: TWNEARAccountTests.cpp.
        $publicAddress = 'NEARTDDWrUMdoC2rA1eU6gNrSU2zyGKdR71TNucTvsQHyfAXjKcJb';

        $account = NEARAccount::createWithString($publicAddress);

        self::assertNotNull($account);
        self::assertSame('3b83b07cab54824a59c3d3f2e203a7cd913b7fcdc4439595983e2402c2cf791d', $account->description());
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::NEAR;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = '0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec';
        $key = $wallet->getKey($coin, "m/44'/397'/0'");
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

        $hash = str_repeat("\x11", 32);

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'signer_id' => $sender,
            'receiver_id' => $recipient,
            'nonce' => '1',
            'block_hash' => $hash,
            // Borsh u128: little-endian, exactly 16 bytes.
            'actions' => [
                new Proto\Action([
                    'transfer' => new Proto\Transfer([
                        'deposit' => pack('P2', 1_000_000, 0),
                    ]),
                ]),
            ],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getSignedTransaction());
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/NEAR/TWAnySignerTests.cpp: SignTokenTransfer
        $blockHash = Base58::decodeNoCheck('2dQBYs8XjprLLgtH7eVsJ3e58A5QuRcbuqFisSk9fFWQ');
        self::assertNotNull($blockHash);
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('77006e227658c18da47546413926a26b839204b1b19e807c4a13d994d661c72e'),
            'signer_id' => '105396228ac2e0ef144b93bcc5322fca1167d524422bb73d17440d35c714a58f',
            'nonce' => '93062928000003',
            'receiver_id' => 'token.paras.near',
            'block_hash' => $blockHash,
            'actions' => [new Proto\Action([
                'token_transfer' => new Proto\TokenTransfer([
                    'token_amount' => '100000000000000000',
                    'receiver_id' => 'c6d5e3e8f328436f595856a598239b691d3d136b24c05a4614f9e9716edc14fe',
                    'gas' => '15000000000000',
                    // One yoctoNEAR, Borsh u128 little-endian.
                    'deposit' => pack('P2', 1, 0),
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::NEAR);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('ABQY6nfLdNrRVynHYNjYkfUM6Up5pDHHpuhRJe6FCMRu', Base58::encodeNoCheck($output->getHash()));
        self::assertSame(
            'QAAAADEwNTM5NjIyOGFjMmUwZWYxNDRiOTNiY2M1MzIyZmNhMTE2N2Q1MjQ0MjJiYjczZDE3NDQwZDM1YzcxNGE1OGYAEFOWIorC4O8US5O8xTIvyhFn1SRCK7c9F0QNNccUpY8D5MPmo1QAABAAAAB0b2tlbi5wYXJhcy5uZWFyGC7O0jXN2b4SH1XfMtNISEnU8XATKOhZwxx0pLLZqTEBAAAAAgsAAABmdF90cmFuc2ZlcnAAAAB7ImFtb3VudCI6IjEwMDAwMDAwMDAwMDAwMDAwMCIsInJlY2VpdmVyX2lkIjoiYzZkNWUzZThmMzI4NDM2ZjU5NTg1NmE1OTgyMzliNjkxZDNkMTM2YjI0YzA1YTQ2MTRmOWU5NzE2ZWRjMTRmZSJ9APCrdaQNAAABAAAAAAAAAAAAAAAAAAAAANUjO7fmnTebSNW9EcHHwYwPNlQJcReGWJfJUuxWzPDAGEeo4JTcLB8pLCkqxKKsI0NE1Szv2+GAt5mCBum5mQY=',
            base64_encode($output->getSignedTransaction()),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0c91f6106ff835c0195d5388565a2d69e25038a7e23d26198f85caf6594117ec', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
