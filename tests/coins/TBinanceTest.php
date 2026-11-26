<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Binance\Proto;
use TW\Common\Proto\SigningError;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class TBinanceTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::TBinance;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('BNB', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::TBinance);

        self::assertSame('tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::TBinance;
        $path = "m/44'/714'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::TBinance;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::TBinance;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::TBinance;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/714'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::TBinance;
        $expected = 'tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::TBinance);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::TBinance;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl';
        $key = $wallet->getKey($coin, "m/44'/714'/0'/0/0");
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
            'chain_id' => 'Binance-Chain-Nile',
            'account_number' => '1',
            'sequence' => '0',
            'send_order' => new Proto\SendOrder([
                'inputs' => [
                    new Proto\SendOrder\Input([
                        'address' => AnyAddress::createWithString($sender, $coin)->data(),
                        'coins' => [
                            new Proto\SendOrder\Token([
                                'denom' => 'BNB',
                                'amount' => '100000',
                            ]),
                        ],
                    ]),
                ],
                'outputs' => [
                    new Proto\SendOrder\Output([
                        'address' => AnyAddress::createWithString($recipient, $coin)->data(),
                        'coins' => [
                            new Proto\SendOrder\Token([
                                'denom' => 'BNB',
                                'amount' => '100000',
                            ]),
                        ],
                    ]),
                ],
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
            'valid address' => ['tbnb12vtaxl9952zm6rwf7v8jerq74pvaf77fkw9xhl', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
