<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Bitcoin\Proto;
use TW\Common\Proto\SigningError;
use TW\Decred\Proto\SigningOutput;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\BitcoinScript;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class DecredTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Decred;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('DCR', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Decred);

        self::assertSame('DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Decred;
        $path = "m/44'/42'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Decred;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Decred;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Decred;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/42'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Decred;
        $expected = 'DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Decred);

        self::assertSame($expected, $valid);
    }

    public function testPlanTransaction(): void
    {
        $coin = CoinType::Decred;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG';
        $key = $wallet->getKey($coin, "m/44'/42'/0'/0/0");
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
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);

        $input = new Proto\SigningInput([
            'private_key' => [$privateKey],
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'amount' => '100000',
            'byte_fee' => '2',
            'to_address' => $recipient,
            'change_address' => $sender,
            'utxo' => [
                new Proto\UnspentTransaction([
                    // Synthetic previous transaction hash (wire byte order).
                    'out_point' => new Proto\OutPoint([
                        'hash' => $hash,
                        'index' => 0,
                        'sequence' => 4_294_967_295,
                    ]),
                    'amount' => '1000000',
                    'script' => $script->data(),
                ]),
            ],
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);

        self::assertSame(SigningError::OK, $plan->getError());
        self::assertSame(100000, (int) $plan->getAmount());
        self::assertSame(1000000, (int) $plan->getAvailableAmount());
        self::assertGreaterThan(0, (int) $plan->getFee());
        self::assertSame(1000000, (int) $plan->getAmount() + (int) $plan->getFee() + (int) $plan->getChange());
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Decred;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG';
        $key = $wallet->getKey($coin, "m/44'/42'/0'/0/0");
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
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);

        $input = new Proto\SigningInput([
            'private_key' => [$privateKey],
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'amount' => '100000',
            'byte_fee' => '2',
            'to_address' => $recipient,
            'change_address' => $sender,
            'utxo' => [
                new Proto\UnspentTransaction([
                    // Synthetic previous transaction hash (wire byte order).
                    'out_point' => new Proto\OutPoint([
                        'hash' => $hash,
                        'index' => 0,
                        'sequence' => 4_294_967_295,
                    ]),
                    'amount' => '1000000',
                    'script' => $script->data(),
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

        $output = new SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
        self::assertMatchesRegularExpression('/^[0-9a-fA-F]{64}$/', $output->getTransactionId());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['DsidJiDGceqHTyqiejABy1ZQ3FX4SiWZkYG', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
