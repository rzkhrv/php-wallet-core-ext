<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Aptos\Proto;
use TW\Common\Proto\SigningError;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class AptosTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Aptos;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('APT', $symbol);
        self::assertSame(8, $decimals);
        self::assertSame('1', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Aptos);

        self::assertSame('0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Aptos;
        $path = "m/44'/637'/0'/0'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Aptos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/637'/0'/0'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Aptos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Aptos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/637'/0'/0'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Aptos;
        $expected = '0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Aptos);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/Aptos/TWAnySignerTests.cpp: TxSign
        $coin = CoinType::Aptos;
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('5d996aa76b3212142792d9130796cd2e11e3c445a93118c08414df4f66bc60ec'),
            'sender' => '0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30',
            'sequence_number' => 99,
            'max_gas_amount' => 3_296_766,
            'gas_unit_price' => 100,
            'expiration_timestamp_secs' => 3_664_390_082,
            'chain_id' => 33,
            'transfer' => new Proto\TransferMessage([
                'to' => '0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30',
                'amount' => 1_000,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f3063000000000000000200000000000000000000000000000000000000000000000000000000000000010d6170746f735f6163636f756e74087472616e7366657200022007968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f3008e803000000000000fe4d3200000000006400000000000000c2276ada00000000210020ea526ba1710343d953461ff68641f1b7df5f23b9042ffa2d2a798d3adb3f3d6c405707246db31e2335edc4316a7a656a11691d1d1647f6e864d1ab12f43428aaaf806cf02120d0b608cdd89c5c904af7b137432aacdd60cc53f9fad7bd33578e01',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: rust/chains/tw_aptos/tests/signer.rs: test_aptos_sign_coin_transfer
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('5d996aa76b3212142792d9130796cd2e11e3c445a93118c08414df4f66bc60ec'),
            'sender' => '0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30',
            'sequence_number' => 24,
            'chain_id' => 32,
            'max_gas_amount' => 3_296_766,
            'expiration_timestamp_secs' => 3_664_390_082,
            'gas_unit_price' => 100,
            'token_transfer' => new Proto\TokenTransferMessage([
                'to' => '0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30',
                'amount' => 100_000,
                'function' => new Proto\StructTag([
                    'account_address' => '0x43417434fd869edee76cca2a4d2301e528a1551b1d719b75c350c3c97d15b8b9',
                    'module' => 'coins',
                    'name' => 'BTC',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Aptos);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30180000000000000002000000000000000000000000000000000000000000000000000000000000000104636f696e087472616e73666572010743417434fd869edee76cca2a4d2301e528a1551b1d719b75c350c3c97d15b8b905636f696e730342544300022007968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f3008a086010000000000fe4d3200000000006400000000000000c2276ada00000000200020ea526ba1710343d953461ff68641f1b7df5f23b9042ffa2d2a798d3adb3f3d6c407643ec8aae6198bd13ca6ea2962265859cba5a228e7d181131f6c022700dd02a7a04dc0345ad99a0289e5ab80b130b3864e6404079980bc226f1a13aee7d280a',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignTokenTransferFungibleAsset(): void
    {
        // Wallet Core 4.8.7: rust/chains/tw_aptos/tests/signer.rs: test_aptos_sign_fungible_asset_transfer
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('5d996aa76b3212142792d9130796cd2e11e3c445a93118c08414df4f66bc60ec'),
            'sender' => '0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30',
            'sequence_number' => 74,
            'chain_id' => 1,
            'max_gas_amount' => 20,
            'expiration_timestamp_secs' => 1_736_060_099,
            'gas_unit_price' => 100,
            'fungible_asset_transfer' => new Proto\FungibleAssetTransferMessage([
                'metadata_address' => '0x2ebb2ccac5e027a87fa0e2e5f656a3a4238d6a48d93ec9b610d570fc0aa0df12',
                'to' => '0x2d92d71078f11d923c2b703b95a288c0e2ae63c0d29154e6278bf8004f9b4e52',
                'amount' => 100_000_000,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Aptos);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f304a00000000000000020000000000000000000000000000000000000000000000000000000000000001167072696d6172795f66756e6769626c655f73746f7265087472616e73666572010700000000000000000000000000000000000000000000000000000000000000010e66756e6769626c655f6173736574084d657461646174610003202ebb2ccac5e027a87fa0e2e5f656a3a4238d6a48d93ec9b610d570fc0aa0df12202d92d71078f11d923c2b703b95a288c0e2ae63c0d29154e6278bf8004f9b4e520800e1f5050000000014000000000000006400000000000000c32c7a6700000000010020ea526ba1710343d953461ff68641f1b7df5f23b9042ffa2d2a798d3adb3f3d6c402d4c5cbb710b6ef92813597054dbf8d3014529a7d85f6393f01e2a3e978c461c6aa656475b98b453ed3faebf7aa1fdd912bfc59a0c1b6fc44330793994b2e40c',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignTokenTransferCoins(): void
    {
        // Wallet Core 4.8.7: rust/chains/tw_aptos/tests/signer.rs: test_implicit_aptos_sign_coin_transfer
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('e7f56c77189e03699a75d8ec5c090e41f3d9d4783bc49c33df8a93d915e10de8'),
            'sender' => '0x1869b853768f0ba935d67f837a66b172dd39a60ca2315f8d4e0e669bbd35cf25',
            'sequence_number' => 2,
            'chain_id' => 1,
            'max_gas_amount' => 2_000,
            'expiration_timestamp_secs' => 3_664_390_082,
            'gas_unit_price' => 100,
            'token_transfer_coins' => new Proto\TokenTransferCoinsMessage([
                'to' => '0xb7c7d12080209e9dc14498c80200706e760363fb31782247e82cf57d1d6e5d6c',
                'amount' => 10_000,
                'function' => new Proto\StructTag([
                    'account_address' => '0xe9c192ff55cffab3963c695cff6dbf9dad6aff2bb5ac19a6415cad26a81860d9',
                    'module' => 'mee_coin',
                    'name' => 'MeeCoin',
                ]),
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Aptos);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '1869b853768f0ba935d67f837a66b172dd39a60ca2315f8d4e0e669bbd35cf2502000000000000000200000000000000000000000000000000000000000000000000000000000000010d6170746f735f6163636f756e740e7472616e736665725f636f696e730107e9c192ff55cffab3963c695cff6dbf9dad6aff2bb5ac19a6415cad26a81860d9086d65655f636f696e074d6565436f696e000220b7c7d12080209e9dc14498c80200706e760363fb31782247e82cf57d1d6e5d6c081027000000000000d0070000000000006400000000000000c2276ada0000000001002062e7a6a486553b56a53e89dfae3f780693e537e5b0a7ed33290780e581ca83694030ebd7e95cb464677f411868e2cbfcb22bc01cc63cded36c459dff45e6d2f1354ae4e090e7dfbb509851c0368b343e0e5ecaf6b08e7c1b94c186530b0f7dee0d',
            bin2hex($output->getEncoded()),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['0x07968dab936c1bad187c60ce4082f307d030d780e91e694ae03aef16aba73f30', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
