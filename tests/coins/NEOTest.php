<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\NEO\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class NEOTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::NEO;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('NEO', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::NEO);

        self::assertSame('AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::NEO;
        $path = "m/44'/888'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::NEO;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/888'/0'/0/1.
        self::assertSame('a3b28463e468e8477cdd3bc8194db9fbe206d500fbf7503fe0df09c999609f74', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('AcsjHf1u23i8yK773B2Wt8tM47gonsDCRN', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::NEO;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::NEO;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/888'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::NEO;
        $expected = 'AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::NEO);

        self::assertSame($expected, $valid);
    }

    public function testPlanTransaction(): void
    {
        $coin = CoinType::NEO;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf';
        $key = $wallet->getKey($coin, "m/44'/888'/0'/0/0");
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
        // NEO Legacy asset identifiers (not N3).
        $neoAsset = '9b7cffdaa674beae0f930ebe6085af9093e5fe56b34a5c220ccdcf6efc336fc5';
        $gasAsset = 'e72d286979ee6cb1b7e65dfddfb2e384100b8d148e7758de42e4168b71792c60';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'fee' => '100000',
            'gas_asset_id' => $gasAsset,
            'gas_change_address' => $sender,
            'inputs' => [
                new Proto\TransactionInput([
                    'prev_hash' => $hash,
                    'prev_index' => 0,
                    'value' => '1000000000',
                    'asset_id' => $neoAsset,
                ]),
                new Proto\TransactionInput([
                    'prev_hash' => $hash,
                    'prev_index' => 1,
                    'value' => '100000000',
                    'asset_id' => $gasAsset,
                ]),
            ],
            'outputs' => [
                new Proto\TransactionOutput([
                    'asset_id' => $neoAsset,
                    'amount' => '100000000',
                    'to_address' => $recipient,
                    'change_address' => $sender,
                ]),
            ],
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);

        self::assertSame(SigningError::OK, $plan->getError());
        self::assertGreaterThan(0, (int) $plan->getFee());
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::NEO;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf';
        $key = $wallet->getKey($coin, "m/44'/888'/0'/0/0");
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
        // NEO Legacy asset identifiers (not N3).
        $neoAsset = '9b7cffdaa674beae0f930ebe6085af9093e5fe56b34a5c220ccdcf6efc336fc5';
        $gasAsset = 'e72d286979ee6cb1b7e65dfddfb2e384100b8d148e7758de42e4168b71792c60';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'fee' => '100000',
            'gas_asset_id' => $gasAsset,
            'gas_change_address' => $sender,
            'inputs' => [
                new Proto\TransactionInput([
                    'prev_hash' => $hash,
                    'prev_index' => 0,
                    'value' => '1000000000',
                    'asset_id' => $neoAsset,
                ]),
                new Proto\TransactionInput([
                    'prev_hash' => $hash,
                    'prev_index' => 1,
                    'value' => '100000000',
                    'asset_id' => $gasAsset,
                ]),
            ],
            'outputs' => [
                new Proto\TransactionOutput([
                    'asset_id' => $neoAsset,
                    'amount' => '100000000',
                    'to_address' => $recipient,
                    'change_address' => $sender,
                ]),
            ],
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);
        self::assertSame(SigningError::OK, $plan->getError());
        $input->setPlan($plan);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/NEO/TransactionCompilerTests.cpp: Nep5TokenCompileWithSignatures (adapted)
        $coin = CoinType::NEO;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf';
        $key = $wallet->getKey($coin, "m/44'/888'/0'/0/0");
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
        // NEO Legacy asset identifiers (not N3).
        $gasAsset = 'e72d286979ee6cb1b7e65dfddfb2e384100b8d148e7758de42e4168b71792c60';

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'fee' => '100000',
            'gas_asset_id' => $gasAsset,
            'gas_change_address' => $sender,
            'inputs' => [
                new Proto\TransactionInput([
                    'prev_hash' => $hash,
                    'prev_index' => 1,
                    'value' => '100000000',
                    'asset_id' => $gasAsset,
                ]),
            ],
            'transaction' => new Proto\Transaction([
                'nep5_transfer' => new Proto\Transaction\Nep5Transfer([
                    'asset_id' => 'f56c89be8bfcdec617e2402b5c3fd5b6d71b820d',
                    'from' => $sender,
                    'to' => $recipient,
                    'amount' => pack('N', 110_000_000),
                    'script_with_ret' => true,
                ]),
            ]),
            'outputs' => [
                new Proto\TransactionOutput([
                    'asset_id' => $gasAsset,
                    'amount' => '99900000',
                    'to_address' => $sender,
                    'change_address' => $sender,
                ]),
            ],
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);
        self::assertSame(SigningError::OK, $plan->getError());
        self::assertGreaterThan(0, (int) $plan->getFee());
        $input->setPlan($plan);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
        self::assertStringContainsString('7472616e73666572', bin2hex($output->getEncoded()));
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['AT6w7PJvwPcSqHvtbNBY2aHPDv12eW5Uuf', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
