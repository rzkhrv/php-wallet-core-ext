<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Cosmos\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class THORChainTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::THORChain;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('RUNE', $symbol);
        self::assertSame(8, $decimals);
        self::assertSame('thorchain-1', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::THORChain);

        self::assertSame('thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::THORChain;
        $path = "m/44'/931'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::THORChain;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::THORChain;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65', $address);
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::THORChain;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/931'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::THORChain;
        $expected = 'thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::THORChain);

        self::assertSame($expected, $valid);
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::THORChain;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65';
        $key = $wallet->getKey($coin, "m/44'/931'/0'/0/0");
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
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::THORChain),
            'account_number' => '1',
            'sequence' => '0',
            'fee' => new Proto\Fee([
                'gas' => '200000',
                'amounts' => [
                    new Proto\Amount([
                        'denom' => 'rune',
                        'amount' => '1000',
                    ]),
                ],
            ]),
            'messages' => [
                new Proto\Message([
                    'thorchain_send_message' => new Proto\Message\THORChainSend([
                        'from_address' => AnyAddress::createWithString($sender, $coin)->data(),
                        'to_address' => AnyAddress::createWithString($recipient, $coin)->data(),
                        'amounts' => [
                            new Proto\Amount([
                                'denom' => 'rune',
                                'amount' => '1000000',
                            ]),
                        ],
                    ]),
                ]),
            ],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getSignature());
    }

    public function testBuildAndSignBtcToEthSwap(): void
    {
        // Wallet Core 4.8.7: tests/chains/Cosmos/THORChain/TWSwapTests.cpp: SwapBtcToEth.
        // Vault/router and quote data must be obtained afresh before a real swap.
        $input = new \TW\THORChainSwap\Proto\SwapInput([
            'from_asset' => new \TW\THORChainSwap\Proto\Asset(['chain' => \TW\THORChainSwap\Proto\Chain::BTC]),
            'from_address' => 'bc1qpjult34k9spjfym8hss2jrwjgf0xjf40ze0pp8',
            'to_asset' => new \TW\THORChainSwap\Proto\Asset(['chain' => \TW\THORChainSwap\Proto\Chain::ETH, 'symbol' => 'ETH']),
            'to_address' => '0xb9f5771c27664bf2282d98e09d7f50cec7cb01a7',
            'vault_address' => 'bc1q6m9u2qsu8mh8y7v8rr2ywavtj8g5arzlyhcej7',
            'from_amount' => '1000000', // satoshis
            'to_amount_limit' => '140000000000000000', // wei, as in the historical vector
        ]);
        $data = \WalletCore\THORChainSwap::buildSwap($input->serializeToString());
        self::assertNotNull($data);
        $swap = new \TW\THORChainSwap\Proto\SwapOutput();
        $swap->mergeFromString($data);
        self::assertSame(\TW\THORChainSwap\Proto\ErrorCode::OK, $swap->getError()?->getCode() ?? 0, $swap->getError()?->getMessage() ?? '');
        self::assertSame(\TW\THORChainSwap\Proto\Chain::BTC, $swap->getFromChain());
        self::assertSame(\TW\THORChainSwap\Proto\Chain::ETH, $swap->getToChain());
        $tx = $swap->getBitcoin();
        self::assertNotNull($tx);
        self::assertSame(1000000, (int) $tx->getAmount());
        self::assertSame('bc1q6m9u2qsu8mh8y7v8rr2ywavtj8g5arzlyhcej7', $tx->getToAddress());
        self::assertSame('=:ETH.ETH:0xb9f5771c27664bf2282d98e09d7f50cec7cb01a7:140000000000000000', $tx->getOutputOpReturn());
        $script = \WalletCore\BitcoinScript::lockScriptForAddress($tx->getChangeAddress(), CoinType::Bitcoin);
        self::assertNotNull($script);
        $tx->setByteFee(20);
        $tx->setPrivateKey([hex2bin('13fcaabaf9e71ffaf915e242ec58a743d55f102cf836968e5bd4881135e0c52c')]);
        $tx->setUtxo([new \TW\Bitcoin\Proto\UnspentTransaction([
            'out_point' => new \TW\Bitcoin\Proto\OutPoint([
                'hash' => hex2bin('1234000000000000000000000000000000000000000000000000000000005678'),
                'index' => 0, 'sequence' => 4_294_967_295,
            ]),
            'script' => $script->data(), 'amount' => 50_000_000,
        ])]);

        $data = AnySigner::sign($tx->serializeToString(), CoinType::Bitcoin);
        self::assertNotNull($data);
        $output = new \TW\Bitcoin\Proto\SigningOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '0100000000010112340000000000000000000000000000000000000000000000000000000056780000000000ffffffff0340420f0000000000160014d6cbc5021c3eee72798718d447758b91d14e8c5fd49ceb02000000001600140cb9f5c6b62c03249367bc20a90dd2425e6926af0000000000000000496a473d3a4554482e4554483a3078623966353737316332373636346266323238326439386530396437663530636563376362303161373a31343030303030303030303030303030303002483045022100a67f84cbde5affbb46ffff2b33c1453ff2de70ef990fc974175d9a609e5a87ed0220589c57d958208f866c9477c7d6c9075dea4c58622debb02eab85032b8b6d37300121021e582a887bd94d648a9267143eb600449a8d59a0db0653740b1378067a6d0cee00000000',
            bin2hex($output->getEncoded()),
        );
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['thor1c8jd7ad9pcw4k3wkuqlkz4auv95mldr2kyhc65', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
