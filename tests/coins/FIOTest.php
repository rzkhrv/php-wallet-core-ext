<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\FIO\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\FIOAccount;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class FIOTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::FIO;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('FIO', $symbol);
        self::assertSame(9, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::FIO);

        self::assertSame('FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::FIO;
        $path = "m/44'/235'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::FIO;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::FIO;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::FIO;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/235'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::FIO;
        $expected = 'FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::FIO);

        self::assertSame($expected, $valid);
    }

    public function testAccountFromPublicAddress(): void
    {
        // Wallet Core: TWFIOAccountTests.cpp.
        $publicAddress = 'FIO6XKiobBGrmPZDaHne47TF25CamjFc3cRe8hs7oAiCN59TbJzes';

        $account = FIOAccount::createWithString($publicAddress);

        self::assertNotNull($account);
        self::assertSame('rpxtnpsr3gxd', $account->description());
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::FIO;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3';
        $key = $wallet->getKey($coin, "m/44'/235'/0'/0/0");
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
            'expiry' => 1_700_000_600,
            'chain_params' => new Proto\ChainParams([
                'chain_id' => $hash,
                'head_block_number' => '123456',
                'ref_block_prefix' => '12345678',
            ]),
            'owner_public_key' => $sender,
            'action' => new Proto\Action([
                'transfer_message' => new Proto\Action\Transfer([
                    'payee_public_key' => $recipient,
                    'amount' => '1000000000',
                    'fee' => '100000000',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getJson());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['FIO7MN1LuSfFgrbVHmrt9cVa2FYAs857Ppr9dzvEXoD1miKSxm3n3', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
