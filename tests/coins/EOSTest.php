<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\EOS\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class EOSTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::EOS;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('EOS', $symbol);
        self::assertSame(4, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::EOS);

        self::assertSame('EOS6hs8sRvGSzuQtq223zwJipMzqTJpXUVjyvHPvPwBSZWWrJTJkg', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::EOS;
        $path = "m/44'/194'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('EOS6hs8sRvGSzuQtq223zwJipMzqTJpXUVjyvHPvPwBSZWWrJTJkg', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::EOS;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/194'/0'/0/1.
        self::assertSame('ca1dd376ffff07a1bddcf4daff3e4bba3d90ce321caf25716abf3d66f8e1f519', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('EOS8jyAeujkNS7iywBNMKdN8Uogzacsu3ub5nDcHZtia3SsVqYLf1', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('EOS6hs8sRvGSzuQtq223zwJipMzqTJpXUVjyvHPvPwBSZWWrJTJkg', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::EOS;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('EOS6hs8sRvGSzuQtq223zwJipMzqTJpXUVjyvHPvPwBSZWWrJTJkg', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::EOS;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/194'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('EOS6hs8sRvGSzuQtq223zwJipMzqTJpXUVjyvHPvPwBSZWWrJTJkg', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::EOS;
        $expected = 'EOS6hs8sRvGSzuQtq223zwJipMzqTJpXUVjyvHPvPwBSZWWrJTJkg';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::EOS);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::EOS;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/194'/0'/0/0");
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
            'private_key_type' => Proto\KeyType::MODERNK1,
            'chain_id' => $hash,
            'reference_block_id' => $hash,
            'reference_block_time' => 1_700_000_000,
            // EOSIO account names are not the derived public-key addresses.
            'currency' => 'eosio.token',
            'sender' => 'examplealice',
            'recipient' => 'examplebob',
            'asset' => new Proto\Asset([
                'amount' => '10000',
                'decimals' => 4,
                'symbol' => 'EOS',
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getJsonEncoded());
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/EOS/TWAnySignerTests.cpp: Sign
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('559aead08264d5795d3909718cdd05abd49572e84fe55590eef31a88a08fdffd'),
            'private_key_type' => Proto\KeyType::MODERNK1,
            'chain_id' => hex2bin('cf057bbfb72640471fd910bcb67639c22df9f92470936cddc1ade0e2f2e7dc4f'),
            'reference_block_id' => hex2bin('000067d6f6a7e7799a1f3d487439a679f8cf95f1c986f35c0d2fa320f51a7144'),
            'reference_block_time' => 1_554_209_118,
            'currency' => 'token',
            'sender' => 'token',
            'recipient' => 'eosio',
            'memo' => 'my second transfer',
            'asset' => new Proto\Asset([
                'amount' => 300_000,
                'decimals' => 4,
                'symbol' => 'TKN',
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::EOS);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        $transaction = json_decode($output->getJsonEncoded(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            '6e67a35cd6679a1f3d4800000000010000000080a920cd000000572d3ccdcd010000000080a920cd00000000a8ed3232330000000080a920cd0000000000ea3055e09304000000000004544b4e00000000126d79207365636f6e64207472616e7366657200',
            $transaction['packed_trx'],
        );
        self::assertSame(['SIG_K1_K9RdLC7DEDWjTfR64GU8BtDHcAjzR1ntcT651JMcfHNTpdsvDrUwfyzF1FkvL9fxEi2UCtGJZ9zYoNbJoMF1fbU64cRiJ7'], $transaction['signatures']);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['EOS6hs8sRvGSzuQtq223zwJipMzqTJpXUVjyvHPvPwBSZWWrJTJkg', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
