<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Cardano\Proto;
use TW\Common\Proto\SigningError;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Cardano;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class CardanoTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Cardano;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('ADA', $symbol);
        self::assertSame(6, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Cardano);

        self::assertSame(
            'addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2',
            $address,
        );
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Cardano;
        $path = "m/1852'/1815'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame(
            'addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2',
            $address,
        );
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Cardano;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Cardano;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame(
            'addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2',
            $address->description(),
        );
    }

    public function testImportExtendedPrivateKey(): void
    {
        $coin = CoinType::Cardano;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);
        self::assertSame(192, strlen($raw));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame(
            'addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2',
            CoinType::deriveAddress($coin, $imported),
        );
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Cardano;
        $expected = 'addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Cardano);

        self::assertSame($expected, $valid);
    }

    public function testDeriveStakingAddress(): void
    {
        // Wallet Core: TWCardanoAddressTests.cpp.
        $address = 'addr1q8043m5heeaydnvtmmkyuhe6qv5havvhsf0d26q3jygsspxlyfpyk6yqkw0yhtyvtr0flekj84u64az82cufmqn65zdsylzk23';

        $stakingAddress = Cardano::getStakingAddress($address);

        self::assertSame('stake1u80jysjtdzqt88jt4jx93h5lumfr67d273r4vwyasfa2pxcwxllmx', $stakingAddress);
    }

    public function testPlanTransaction(): void
    {
        $coin = CoinType::Cardano;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2';
        $key = $wallet->getKey($coin, "m/1852'/1815'/0'/0/0");
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
            'private_key' => [$privateKey],
            'utxos' => [
                new Proto\TxInput([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => $hash,
                        'output_index' => '0',
                    ]),
                    'address' => $sender,
                    'amount' => '10000000',
                ]),
            ],
            'transfer_message' => new Proto\Transfer([
                'to_address' => $recipient,
                'change_address' => $sender,
                'amount' => '2000000',
            ]),
            'ttl' => '100000000',
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);

        self::assertSame(SigningError::OK, $plan->getError());
        self::assertSame(2000000, (int) $plan->getAmount());
        self::assertSame(10000000, (int) $plan->getAvailableAmount());
        self::assertGreaterThan(0, (int) $plan->getFee());
        self::assertSame(10000000, (int) $plan->getAmount() + (int) $plan->getFee() + (int) $plan->getChange());
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Cardano;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2';
        $key = $wallet->getKey($coin, "m/1852'/1815'/0'/0/0");
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
            'private_key' => [$privateKey],
            'utxos' => [
                new Proto\TxInput([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => $hash,
                        'output_index' => '0',
                    ]),
                    'address' => $sender,
                    'amount' => '10000000',
                ]),
            ],
            'transfer_message' => new Proto\Transfer([
                'to_address' => $recipient,
                'change_address' => $sender,
                'amount' => '2000000',
            ]),
            'ttl' => '100000000',
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
        // Wallet Core 4.8.7: tests/chains/Cardano/SigningTests.cpp: SignTransferToken
        $policy = '9a9693a9a37912a5097918f97918d15240c92ab729a0b7c4aa144d77';
        $sender = 'addr1q8043m5heeaydnvtmmkyuhe6qv5havvhsf0d26q3jygsspxlyfpyk6yqkw0yhtyvtr0flekj84u64az82cufmqn65zdsylzk23';
        $txHash = hex2bin('f074134aabbfb13b8aec7cf5465b1e5a862bde5cb88532cc7e64619179b3e767');
        $input = new Proto\SigningInput([
            'private_key' => [hex2bin('089b68e458861be0c44bf9f7967f05cc91e51ede86dc679448a3566990b7785bd48c330875b1e0d03caaed0e67cecc42075dce1c7a13b1c49240508848ac82f603391c68824881ae3fc23a56a1a75ada3b96382db502e37564e84a5413cfaf1290dbd508e5ec71afaea98da2df1533c22ef02a26bb87b31907d0b2738fb7785b38d53aa68fc01230784c9209b2b2a2faf28491b3b1f1d221e63e704bbd0403c4154425dfbb01a2c5c042da411703603f89af89e57faae2946e2a5c18b1c5ca0e')],
            'utxos' => [
                new Proto\TxInput([
                    'out_point' => new Proto\OutPoint(['tx_hash' => $txHash, 'output_index' => 1]),
                    'address' => $sender,
                    'amount' => 8_051_373,
                    'token_amount' => [new Proto\TokenAmount([
                        'policy_id' => $policy,
                        'asset_name' => 'CUBY',
                        'amount' => ltrim(pack('J', 3_000_000), "\0"),
                    ])],
                ]),
                new Proto\TxInput([
                    'out_point' => new Proto\OutPoint(['tx_hash' => $txHash, 'output_index' => 2]),
                    'address' => $sender,
                    'amount' => 2_000_000,
                    'token_amount' => [
                        new Proto\TokenAmount([
                            'policy_id' => $policy,
                            'asset_name_hex' => '53554e444145',
                            'amount' => pack('N', 80_996_569),
                        ]),
                        new Proto\TokenAmount([
                            'policy_id' => $policy,
                            'asset_name' => 'CUBY',
                            'amount' => ltrim(pack('J', 2_000_000), "\0"),
                        ]),
                    ],
                ]),
            ],
            'transfer_message' => new Proto\Transfer([
                'to_address' => 'addr1q92cmkgzv9h4e5q7mnrzsuxtgayvg4qr7y3gyx97ukmz3dfx7r9fu73vqn25377ke6r0xk97zw07dqr9y5myxlgadl2s0dgke5',
                'change_address' => 'addr1qxxe304qg9py8hyyqu8evfj4wln7dnms943wsugpdzzsxnkvvjljtzuwxvx0pnwelkcruy95ujkq3aw6rl0vvg32x35qc92xkq',
                'amount' => 1_444_443,
                'token_amount' => new Proto\TokenBundle([
                    'token' => [new Proto\TokenAmount([
                        'policy_id' => $policy,
                        'asset_name_hex' => '53554e444145',
                        'amount' => pack('N', 20_000_000),
                    ])],
                ]),
            ]),
            'ttl' => 53_333_333,
        ]);

        // Minimum ADA required for the token bundle; values are lovelace.
        $bundle = $input->getTransferMessage()->getTokenAmount();
        $minimumAda = \WalletCore\Cardano::minAdaAmount($bundle->serializeToString());
        self::assertSame(1444443, $minimumAda);
        $input->getTransferMessage()->setAmount($minimumAda);

        $planData = AnySigner::plan($input->serializeToString(), CoinType::Cardano);
        self::assertNotNull($planData);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);
        self::assertSame(SigningError::OK, $plan->getError());
        self::assertSame(10051373, (int) $plan->getAvailableAmount());
        self::assertSame(1444443, (int) $plan->getAmount());
        self::assertSame(174601, (int) $plan->getFee());
        self::assertSame(8432329, (int) $plan->getChange());
        self::assertCount(2, $plan->getUtxos());
        self::assertCount(2, $plan->getAvailableTokens());
        self::assertCount(1, $plan->getOutputTokens());
        self::assertSame(20000000, hexdec(bin2hex($plan->getOutputTokens()[0]->getAmount())));
        self::assertCount(2, $plan->getChangeTokens());
        // The other token remains in change, along with the unspent SUNDAE.
        $change = [];
        foreach ($plan->getChangeTokens() as $token) {
            $change[] = hexdec(bin2hex($token->getAmount()));
        }
        sort($change);
        self::assertSame([5000000, 60996569], $change);
        $input->setPlan($plan);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Cardano);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('201c537693b005b64a0f0528e366ec67a84be0119ed4363b547f141f2a7770c2', bin2hex($output->getTxId()));
        self::assertSame(
            '83a40082825820f074134aabbfb13b8aec7cf5465b1e5a862bde5cb88532cc7e64619179b3e76701825820f074134aabbfb13b8aec7cf5465b1e5a862bde5cb88532cc7e64619179b3e76702018282583901558dd902616f5cd01edcc62870cb4748c45403f1228218bee5b628b526f0ca9e7a2c04d548fbd6ce86f358be139fe680652536437d1d6fd5821a00160a5ba1581c9a9693a9a37912a5097918f97918d15240c92ab729a0b7c4aa144d77a14653554e4441451a01312d00825839018d98bea0414243dc84070f96265577e7e6cf702d62e871016885034ecc64bf258b8e330cf0cdd9fdb03e10b4e4ac08f5da1fdec6222a3468821a0080aac9a1581c9a9693a9a37912a5097918f97918d15240c92ab729a0b7c4aa144d77a244435542591a004c4b404653554e4441451a03a2bbd9021a0002aa09031a032dcd55a100818258206d8a0b425bd2ec9692af39b1c0cf0e51caa07a603550e22f54091e872c7df2905840d90dcfbd190cbe59c42094e59eeb49b3de9d80a85b786cc311f932c5c9302d1c8c6c577b22aa70ff7955c139c700ea918f8cb425c3ba43a27980e1d238e4e908f6',
            bin2hex($output->getEncoded()),
        );
    }

    public function testRejectInsufficientTokenBalance(): void
    {
        $policy = '9a9693a9a37912a5097918f97918d15240c92ab729a0b7c4aa144d77';
        $sender = 'addr1q8043m5heeaydnvtmmkyuhe6qv5havvhsf0d26q3jygsspxlyfpyk6yqkw0yhtyvtr0flekj84u64az82cufmqn65zdsylzk23';
        $txHash = hex2bin('f074134aabbfb13b8aec7cf5465b1e5a862bde5cb88532cc7e64619179b3e767');
        $input = new Proto\SigningInput([
            'private_key' => [hex2bin('089b68e458861be0c44bf9f7967f05cc91e51ede86dc679448a3566990b7785bd48c330875b1e0d03caaed0e67cecc42075dce1c7a13b1c49240508848ac82f603391c68824881ae3fc23a56a1a75ada3b96382db502e37564e84a5413cfaf1290dbd508e5ec71afaea98da2df1533c22ef02a26bb87b31907d0b2738fb7785b38d53aa68fc01230784c9209b2b2a2faf28491b3b1f1d221e63e704bbd0403c4154425dfbb01a2c5c042da411703603f89af89e57faae2946e2a5c18b1c5ca0e')],
            'utxos' => [
                new Proto\TxInput([
                    'out_point' => new Proto\OutPoint(['tx_hash' => $txHash, 'output_index' => 1]),
                    'address' => $sender,
                    'amount' => 8_051_373,
                    'token_amount' => [new Proto\TokenAmount([
                        'policy_id' => $policy,
                        'asset_name' => 'CUBY',
                        'amount' => ltrim(pack('J', 3_000_000), "\0"),
                    ])],
                ]),
                new Proto\TxInput([
                    'out_point' => new Proto\OutPoint(['tx_hash' => $txHash, 'output_index' => 2]),
                    'address' => $sender,
                    'amount' => 2_000_000,
                    'token_amount' => [
                        new Proto\TokenAmount([
                            'policy_id' => $policy,
                            'asset_name_hex' => '53554e444145',
                            'amount' => pack('N', 80_996_569),
                        ]),
                        new Proto\TokenAmount([
                            'policy_id' => $policy,
                            'asset_name' => 'CUBY',
                            'amount' => ltrim(pack('J', 2_000_000), "\0"),
                        ]),
                    ],
                ]),
            ],
            'transfer_message' => new Proto\Transfer([
                'to_address' => 'addr1q92cmkgzv9h4e5q7mnrzsuxtgayvg4qr7y3gyx97ukmz3dfx7r9fu73vqn25377ke6r0xk97zw07dqr9y5myxlgadl2s0dgke5',
                'change_address' => 'addr1qxxe304qg9py8hyyqu8evfj4wln7dnms943wsugpdzzsxnkvvjljtzuwxvx0pnwelkcruy95ujkq3aw6rl0vvg32x35qc92xkq',
                'amount' => 1_444_443,
                'token_amount' => new Proto\TokenBundle([
                    'token' => [new Proto\TokenAmount([
                        'policy_id' => $policy,
                        'asset_name_hex' => '53554e444145',
                        'amount' => pack('N', 100_000_000), // 100,000,000 > available 80,996,569
                    ])],
                ]),
            ]),
            'ttl' => 53_333_333,
        ]);

        $data = AnySigner::plan($input->serializeToString(), CoinType::Cardano);
        self::assertNotNull($data);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($data);

        self::assertSame(SigningError::Error_low_balance, $plan->getError());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84ray2', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['addr1qyr8jjfnypp95eq74aqzn7ss687ehxclgj7mu6gratmg3mul2040vt35dypp042awzsjk5xm3zr3zm5qh7454uwdv08s84rayq', false],
        ];
    }
}
