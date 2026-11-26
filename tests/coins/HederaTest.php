<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Hedera\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class HederaTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Hedera;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('HBAR', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Hedera);

        self::assertSame('0.0.302a300506032b657003210049eba62f64d0d941045595d9433e65d84ecc46bcdb1421de55e05fcf2d8357d5', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Hedera;
        $path = "m/44'/3030'/0'/0'/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0.0.302a300506032b657003210049eba62f64d0d941045595d9433e65d84ecc46bcdb1421de55e05fcf2d8357d5', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        // Change a hardened account index; preserve the network's legacy default path structure.
        $coin = CoinType::Hedera;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/3030'/1'/0'/0");
        self::assertNotNull($key);
        // Independent BIP39/SLIP-0010, hardened components of the legacy path reference: m/44'/3030'/1'/0'/0.
        self::assertSame('577eba92b22c4bda14b3c83397edf6f07f4963b16837d551fdd1bff209169024', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('0.0.302a300506032b657003210018ed1baf2c8e5d6e201ffd5352fc065f7b2d0b00bbb18c14d2b30160ffeecee4', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('0.0.302a300506032b657003210049eba62f64d0d941045595d9433e65d84ecc46bcdb1421de55e05fcf2d8357d5', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Hedera;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0.0.302a300506032b657003210049eba62f64d0d941045595d9433e65d84ecc46bcdb1421de55e05fcf2d8357d5', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Hedera;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/3030'/0'/0'/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0.0.302a300506032b657003210049eba62f64d0d941045595d9433e65d84ecc46bcdb1421de55e05fcf2d8357d5', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Hedera;
        $expected = '0.0.302a300506032b657003210049eba62f64d0d941045595d9433e65d84ecc46bcdb1421de55e05fcf2d8357d5';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Hedera);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Hedera;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/3030'/0'/0'/0");
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
            // Account IDs are assigned on-chain; a mnemonic derives an alias, not a numeric ID.
            'body' => new Proto\TransactionBody([
                'transactionID' => new Proto\TransactionID([
                    'transactionValidStart' => new Proto\Timestamp([
                        'seconds' => '1700000000',
                        'nanos' => 0,
                    ]),
                    'accountID' => '0.0.1001',
                ]),
                'nodeAccountID' => '0.0.3',
                'transactionFee' => '100000000',
                'transactionValidDuration' => '120',
                'transfer' => new Proto\TransferMessage([
                    'from' => '0.0.1001',
                    'to' => '0.0.1002',
                    'amount' => '100000000',
                ]),
            ]),
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
            'valid address' => ['0.0.302a300506032b657003210049eba62f64d0d941045595d9433e65d84ecc46bcdb1421de55e05fcf2d8357d5', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
