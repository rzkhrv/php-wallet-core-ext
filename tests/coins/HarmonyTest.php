<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Harmony\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class HarmonyTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Harmony;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('ONE', $symbol);
        self::assertSame(18, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Harmony);

        self::assertSame('one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Harmony;
        $path = "m/44'/1023'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Harmony;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/1023'/0'/0/1.
        self::assertSame('1d0d74db27a697f29d99052e20f3769c5c1880e21f370782c716c979bff15d25', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('one1ke0fz50dpk5xshq2hjhdsgggc07j0egacv0rhx', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Harmony;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Harmony;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/1023'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Harmony;
        $expected = 'one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Harmony);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Harmony;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/1023'/0'/0/0");
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
            'chain_id' => pack('C', 1),
            'transaction_message' => new Proto\TransactionMessage([
                'nonce' => pack('C', 0),
                'gas_price' => pack('N', 1_000_000_000),
                'gas_limit' => pack('n', 21_000),
                'to_address' => $recipient,
                'amount' => ltrim(pack('J', 1_000_000), "\0"),
                'from_shard_id' => pack('C', 0),
                'to_shard_id' => pack('C', 0),
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
        // Wallet Core 4.8.7: src/proto/Harmony.proto: TransactionMessage.payload (ABI transfer payload)
        $coin = CoinType::Harmony;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09';
        $key = $wallet->getKey($coin, "m/44'/1023'/0'/0/0");
        self::assertNotNull($key);

        $privateKey = $key->data();
        self::assertNotNull($privateKey);

        // transfer(address,uint256): recipient 0x5322...c84, one smallest token unit.
        // Synthetic contract address for an offline signing fixture.
        $calldata = hex2bin('a9059cbb0000000000000000000000005322b34c88ed0691971bf52a7047448f0f4efc840000000000000000000000000000000000000000000000000000000000000001');
        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'chain_id' => pack('C', 1),
            'transaction_message' => new Proto\TransactionMessage([
                'nonce' => pack('C', 0),
                'gas_price' => pack('N', 1_000_000_000),
                'gas_limit' => ltrim(pack('J', 100_000), "\0"),
                'to_address' => $sender,
                'amount' => pack('C', 0),
                'payload' => $calldata,
                'from_shard_id' => pack('C', 0),
                'to_shard_id' => pack('C', 0),
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
            'valid address' => ['one12fk20wmvgypdkn59n4hq8e3aa5899xfx4vsu09', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
