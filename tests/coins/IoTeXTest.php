<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\IoTeX\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class IoTeXTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::IoTeX;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('IOTX', $symbol);
        self::assertSame(18, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::IoTeX);

        self::assertSame('io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::IoTeX;
        $path = "m/44'/304'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::IoTeX;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/304'/0'/0/1.
        self::assertSame('a1321beb00a1f606e70b2ad8f971c0b2ce0179bac1f8198e4fd4456b783913ce', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('io1f6qax4uwvnmr9jeuq0h3htsun7yljsuyvv58u9', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::IoTeX;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::IoTeX;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/304'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::IoTeX;
        $expected = 'io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::IoTeX);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::IoTeX;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/304'/0'/0/0");
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
            'privateKey' => $privateKey,
            'version' => 1,
            'nonce' => '1',
            'gasLimit' => '10000',
            'gasPrice' => '1000000000000',
            'chainID' => 1,
            'transfer' => new Proto\Transfer([
                'recipient' => $recipient,
                'amount' => '1000000',
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
        // Wallet Core 4.8.7: src/proto/IoTeX.proto: ContractCall.data (ABI transfer payload)
        $coin = CoinType::IoTeX;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk';
        $key = $wallet->getKey($coin, "m/44'/304'/0'/0/0");
        self::assertNotNull($key);

        $privateKey = $key->data();
        self::assertNotNull($privateKey);

        // transfer(address,uint256): recipient 0x5322...c84, one smallest token unit.
        // Synthetic contract address for an offline signing fixture.
        $calldata = hex2bin('a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000000000000000000001');
        $input = new Proto\SigningInput([
            'privateKey' => $privateKey,
            'version' => 1,
            'nonce' => '1',
            'gasLimit' => '100000',
            'gasPrice' => '1000000000000',
            'chainID' => 1,
            'call' => new Proto\ContractCall([
                'contract' => $sender,
                'amount' => '0',
                'data' => $calldata,
            ]),
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
            'valid address' => ['io1qw9cccecw09q7p5kzyqtuhfhvah2mhfrc84jfk', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
