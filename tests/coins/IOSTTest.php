<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\IOST\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class IOSTTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::IOST;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('IOST', $symbol);
        self::assertSame(2, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::IOST);

        self::assertSame('4av8w81EyzUgHonsVWqfs15WM4Vrpgox4BYYQWhNQDVu', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::IOST;
        $path = "m/44'/899'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('4av8w81EyzUgHonsVWqfs15WM4Vrpgox4BYYQWhNQDVu', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        // Change a hardened account index; preserve the network's legacy default path structure.
        $coin = CoinType::IOST;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/899'/1'/0/0");
        self::assertNotNull($key);
        // Independent BIP39/SLIP-0010, hardened components of the legacy path reference: m/44'/899'/1'/0/0.
        self::assertSame('5dbf8144e23f52e634497c1538b64da045d978c5f413ac81267fb291a90c92a8', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('2NFQSGJe9CjhcatLL28dPAUurXD5s93a9SnaSV1YRyD6', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('4av8w81EyzUgHonsVWqfs15WM4Vrpgox4BYYQWhNQDVu', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::IOST;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('4av8w81EyzUgHonsVWqfs15WM4Vrpgox4BYYQWhNQDVu', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::IOST;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/899'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('4av8w81EyzUgHonsVWqfs15WM4Vrpgox4BYYQWhNQDVu', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::IOST;
        $expected = '4av8w81EyzUgHonsVWqfs15WM4Vrpgox4BYYQWhNQDVu';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::IOST);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::IOST;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/899'/0'/0/0");
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
            // Account names are assigned separately from the derived public key.
            'account' => new Proto\AccountInfo([
                'name' => 'examplealice',
                'active_key' => $privateKey,
            ]),
            'transaction_template' => new Proto\Transaction([
                'time' => '1700000000000000000',
                'expiration' => '1700000060000000000',
                'gas_ratio' => 1,
                'gas_limit' => 100_000,
                'chain_id' => 1_024,
                'publisher' => 'examplealice',
                'amount_limit' => [
                    new Proto\AmountLimit([
                        'token' => 'iost',
                        'value' => '1',
                    ]),
                ],
            ]),
            'transfer_destination' => 'examplebob',
            'transfer_amount' => '1',
            'transfer_memo' => 'Offline example',
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['4av8w81EyzUgHonsVWqfs15WM4Vrpgox4BYYQWhNQDVu', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
