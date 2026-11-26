<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\VeChain\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class VeChainTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::VeChain;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('VET', $symbol);
        self::assertSame(18, $decimals);
        self::assertSame('74', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::VeChain);

        self::assertSame('0x1a553275dF34195eAf23942CB7328AcF9d48c160', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::VeChain;
        $path = "m/44'/818'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0x1a553275dF34195eAf23942CB7328AcF9d48c160', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::VeChain;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/818'/0'/0/1.
        self::assertSame('3d4825c1d69d71fb0cd199d22555c937464681a9b329287e0748bdf17082c485', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('0x967981CB5a331bDe9E3674fa5cc1810869E84219', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('0x1a553275dF34195eAf23942CB7328AcF9d48c160', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::VeChain;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0x1a553275dF34195eAf23942CB7328AcF9d48c160', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::VeChain;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/818'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0x1a553275dF34195eAf23942CB7328AcF9d48c160', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::VeChain;
        $expected = '0x1a553275dF34195eAf23942CB7328AcF9d48c160';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::VeChain);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::VeChain;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/818'/0'/0/0");
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
            'chain_tag' => 74,
            'block_ref' => '12345678',
            'expiration' => 32,
            'gas' => '21000',
            'nonce' => '1',
            'clauses' => [
                new Proto\Clause([
                    'to' => $recipient,
                    'value' => ltrim(pack('J', 1_000_000), "\0"),
                ]),
            ],
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
        // Wallet Core 4.8.7: src/proto/VeChain.proto: Clause.data (ABI transfer payload)
        $coin = CoinType::VeChain;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/818'/0'/0/0");
        self::assertNotNull($key);

        $privateKey = $key->data();
        self::assertNotNull($privateKey);

        // transfer(address,uint256): recipient 0x5322...c84, one smallest token unit.
        // VTHO contract address.
        $calldata = hex2bin('a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000000000000000000001');
        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_tag' => 74,
            'block_ref' => '12345678',
            'expiration' => 32,
            'gas' => '100000',
            'nonce' => '1',
            'clauses' => [
                new Proto\Clause([
                    'to' => '0x0000000000000000000000000000456e65726779',
                    'value' => pack('C', 0),
                    'data' => $calldata,
                ]),
            ],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
        self::assertStringContainsString($calldata, $output->getEncoded());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0x1a553275dF34195eAf23942CB7328AcF9d48c160', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
