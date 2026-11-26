<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Ontology\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class OntologyTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Ontology;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('ONT', $symbol);
        self::assertSame(0, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Ontology);

        self::assertSame('AHKTnybvnWo3TeY8uvNXekvYxMrXogUjeT', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Ontology;
        $path = "m/44'/1024'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('AHKTnybvnWo3TeY8uvNXekvYxMrXogUjeT', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Ontology;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('AHKTnybvnWo3TeY8uvNXekvYxMrXogUjeT', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Ontology;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('AHKTnybvnWo3TeY8uvNXekvYxMrXogUjeT', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Ontology;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/1024'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('AHKTnybvnWo3TeY8uvNXekvYxMrXogUjeT', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Ontology;
        $expected = 'AHKTnybvnWo3TeY8uvNXekvYxMrXogUjeT';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Ontology);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Ontology;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/1024'/0'/0/0");
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
            'owner_private_key' => $privateKey,
            'payer_private_key' => $privateKey,
            'contract' => 'ONT',
            'method' => 'transfer',
            'to_address' => $recipient,
            'amount' => '1',
            'gas_price' => '500',
            'gas_limit' => '20000',
            'nonce' => 1,
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
        // Wallet Core 4.8.7: tests/chains/Ontology/TWAnySignerTests.cpp: Oep4TokenTransfer
        $input = new Proto\SigningInput([
            'owner_private_key' => hex2bin('4646464646464646464646464646464646464646464646464646464646464646'),
            'payer_private_key' => hex2bin('4646464646464646464646464646464646464646464646464646464646464652'),
            'contract' => '2f34b28eb98a1dd901d303f5294c87546fb37fe7',
            'method' => 'transfer',
            'nonce' => 2_232_822_985,
            'to_address' => 'ARR6PsaBwRttzCmyxCMhL7NmFk1LqExD7L',
            'amount' => 1_000,
            'gas_price' => 2_500,
            'gas_limit' => 200,
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Ontology);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '00d1c92c1685c409000000000000c80000000000000057e9d1a61f9aafa798b6c7fbeae35639681d7df64d02e8031469c329fbb30a490979ea1a6f0b6a3a91235f6bd714fbacc8214765d457c8e3f2b5a1d3c4981a2e9d2a53c1087472616e7366657267e77fb36f54874c29f503d301d91d8ab98eb2342f000241402b62b4c6bc07667019e5c9a1fa1b83ca71ee23ddb763446406b1b03706bf50a6180b13e255a08ade7da376dfd34faee7f51c4f0056325fa79aaf7de0ef25d64e2321031bec1250aa8f78275f99a6663688f31085848d0ed92f1203e447125f927b7486ac41408aa88ae92ea30a9e5059de8594f462af7dfa7545fffa6654e94eedfb910bcd5452a26d1554d5d980db84d00dd330aab2fc68316660c8ae5af2c806085157e8ce232103d9fd62df332403d9114f3fa3da0d5aec9dfa42948c2f50738d52470469a1a1eeac',
            bin2hex($output->getEncoded()),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['AHKTnybvnWo3TeY8uvNXekvYxMrXogUjeT', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
