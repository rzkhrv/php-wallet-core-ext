<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Nervos\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\NervosAddress;
use WalletCore\PrivateKey;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class NervosTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Nervos;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('CKB', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Nervos);

        self::assertSame('ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Nervos;
        $path = "m/44'/309'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Nervos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Nervos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Nervos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/309'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Nervos;
        $expected = 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Nervos);

        self::assertSame($expected, $valid);
    }

    public function testPlanTransaction(): void
    {
        $coin = CoinType::Nervos;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3';
        $key = $wallet->getKey($coin, "m/44'/309'/0'/0/0");
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
        $lock = NervosAddress::createWithString($sender);
        self::assertNotNull($lock);

        $input = new Proto\SigningInput([
            'private_key' => [$privateKey],
            'byte_fee' => '1',
            'cell' => [
                new Proto\Cell([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => $hash,
                        'index' => 0,
                    ]),
                    'capacity' => '100000000000',
                    'lock' => new Proto\Script([
                        'code_hash' => $lock->codeHash(),
                        'hash_type' => $lock->hashType(),
                        'args' => $lock->args(),
                    ]),
                ]),
            ],
            'native_transfer' => new Proto\NativeTransfer([
                'to_address' => $recipient,
                'change_address' => $sender,
                'amount' => '10000000000',
            ]),
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);

        self::assertSame(SigningError::OK, $plan->getError());
        self::assertCount(1, $plan->getSelectedCells());
        self::assertCount(2, $plan->getOutputs());
        self::assertSame(10000000000, (int) $plan->getOutputs()[0]->getCapacity());
    }

    public function testSignTransaction(): void
    {
        $coin = CoinType::Nervos;
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);
        $sender = 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3';
        $key = $wallet->getKey($coin, "m/44'/309'/0'/0/0");
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
        $lock = NervosAddress::createWithString($sender);
        self::assertNotNull($lock);

        $input = new Proto\SigningInput([
            'private_key' => [$privateKey],
            'byte_fee' => '1',
            'cell' => [
                new Proto\Cell([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => $hash,
                        'index' => 0,
                    ]),
                    'capacity' => '100000000000',
                    'lock' => new Proto\Script([
                        'code_hash' => $lock->codeHash(),
                        'hash_type' => $lock->hashType(),
                        'args' => $lock->args(),
                    ]),
                ]),
            ],
            'native_transfer' => new Proto\NativeTransfer([
                'to_address' => $recipient,
                'change_address' => $sender,
                'amount' => '10000000000',
            ]),
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

        self::assertSame(SigningError::OK, $output->getError());
        self::assertNotSame('', $output->getTransactionJson());
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: tests/chains/Nervos/TWAnySignerTests.cpp: Sign_SUDT_Simple
        $sender = 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqwyk5x9erg8furras980hksatlslfaktks7epf25';
        $change = 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqds6ed78yze6eyfyvd537z66ur22c9mmrgz82ama';
        $senderAddress = NervosAddress::createWithString($sender);
        $changeAddress = NervosAddress::createWithString($change);
        self::assertNotNull($senderAddress);
        self::assertNotNull($changeAddress);
        $senderLock = new Proto\Script([
            'code_hash' => $senderAddress->codeHash(),
            'hash_type' => $senderAddress->hashType(),
            'args' => $senderAddress->args(),
        ]);
        $changeLock = new Proto\Script([
            'code_hash' => $changeAddress->codeHash(),
            'hash_type' => $changeAddress->hashType(),
            'args' => $changeAddress->args(),
        ]);
        $tokenId = hex2bin('9657b32fcdc463e13ec9205914fd91c443822a949937ae94add9869e7f2e1de8');
        $input = new Proto\SigningInput([
            'private_key' => [
                hex2bin('8a2a726c44e46d1efaa0f9c2a8efed932f0e96d6050b914fde762ee285e61feb'),
                hex2bin('0c8859a9d9084a8c2b55963268b352e258756f9240f2a1f4645c610ed191dae9'),
            ],
            'byte_fee' => 1,
            'cell' => [
                new Proto\Cell([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => hex2bin('5b12911e7413e011f251c1fb5fae4e76fd5fcae4f0d4c6412dcc5b0bfcece823'),
                        'index' => 0,
                    ]),
                    'capacity' => '14399998906',
                    'lock' => $senderLock,
                ]),
                new Proto\Cell([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => hex2bin('e118bd11a73d381daf288381ce182d92b6cf2f52d25886bbda9e1a61525c7c4a'),
                        'index' => 0,
                    ]),
                    'capacity' => '14400000000',
                    'lock' => $senderLock,
                    'type' => new Proto\Script([
                        'code_hash' => hex2bin('5e7a36a77e68eecc013dfa2fe6a23f3b6c344b04005808694ae6dd45eea4cfd5'),
                        'hash_type' => 'type',
                        'args' => $tokenId,
                    ]),
                    'data' => hex2bin('00e0e4c9b9f84f000000000000000000'),
                ]),
                new Proto\Cell([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => hex2bin('09a45a15e48f985b554a0b6e5f0857913cc492ec061cc9b0b2befa4b24609a4a'),
                        'index' => 1,
                    ]),
                    'capacity' => '8210025567',
                    'lock' => $changeLock,
                ]),
            ],
            'sudt_transfer' => new Proto\SudtTransfer([
                'to_address' => 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3',
                'change_address' => $change,
                'amount' => '1000000000000000',
                'sudt_address' => $tokenId,
            ]),
        ]);

        $planData = AnySigner::plan($input->serializeToString(), CoinType::Nervos);
        self::assertNotNull($planData);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);
        self::assertSame(SigningError::OK, $plan->getError());
        self::assertCount(3, $plan->getSelectedCells());
        self::assertCount(3, $plan->getOutputs());
        self::assertSame('0080c6a47e8d03000000000000000000', bin2hex($plan->getOutputsData()[0]));
        self::assertSame('00601e253b6b4c000000000000000000', bin2hex($plan->getOutputsData()[1]));
        $input->setPlan($plan);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Nervos);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError());
        self::assertSame('0x9b15f2bea26b98201540d8e20e8b1c21d96dd77ad246520b405c6aabb7173371', $output->getTransactionId());
        $transaction = json_decode($output->getTransactionJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['0x0080c6a47e8d03000000000000000000', '0x00601e253b6b4c000000000000000000', '0x'], $transaction['outputs_data']);
        self::assertCount(3, $transaction['witnesses']);
    }

    public function testSignMaximumSudt(): void
    {
        // Wallet Core 4.8.7: tests/chains/Nervos/TWAnySignerTests.cpp: Sign_SUDT_SendMaximum
        $sender = 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqwyk5x9erg8furras980hksatlslfaktks7epf25';
        $change = 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqds6ed78yze6eyfyvd537z66ur22c9mmrgz82ama';
        $senderAddress = NervosAddress::createWithString($sender);
        self::assertNotNull($senderAddress);
        $senderLock = new Proto\Script([
            'code_hash' => $senderAddress->codeHash(),
            'hash_type' => $senderAddress->hashType(),
            'args' => $senderAddress->args(),
        ]);
        $tokenId = hex2bin('9657b32fcdc463e13ec9205914fd91c443822a949937ae94add9869e7f2e1de8');
        $input = new Proto\SigningInput([
            'private_key' => [
                hex2bin('8a2a726c44e46d1efaa0f9c2a8efed932f0e96d6050b914fde762ee285e61feb'),
            ],
            'byte_fee' => 1,
            'cell' => [
                new Proto\Cell([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => hex2bin('430cb60ee816e2631d6d9605659c18fec8eb3de94526f5fd4ad51feaad6f1664'),
                        'index' => 0,
                    ]),
                    'capacity' => '8210026306',
                    'lock' => $senderLock,
                ]),
                new Proto\Cell([
                    'out_point' => new Proto\OutPoint([
                        'tx_hash' => hex2bin('378b6bd2f7fc2b1599ee55be7e8fa17fdd6e0d25e2e146d5f46006e0292d6564'),
                        'index' => 0,
                    ]),
                    'capacity' => '14400000000',
                    'lock' => $senderLock,
                    'type' => new Proto\Script([
                        'code_hash' => hex2bin('5e7a36a77e68eecc013dfa2fe6a23f3b6c344b04005808694ae6dd45eea4cfd5'),
                        'hash_type' => 'type',
                        'args' => $tokenId,
                    ]),
                    'data' => hex2bin('00601e253b6b4c000000000000000000'),
                ]),
            ],
            'sudt_transfer' => new Proto\SudtTransfer([
                'to_address' => 'ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3',
                'change_address' => $change,
                'use_max_amount' => true,
                'sudt_address' => $tokenId,
            ]),
        ]);

        $planData = AnySigner::plan($input->serializeToString(), CoinType::Nervos);
        self::assertNotNull($planData);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);
        self::assertSame(SigningError::OK, $plan->getError());
        self::assertCount(2, $plan->getSelectedCells());
        self::assertCount(2, $plan->getOutputs());
        self::assertSame('00601e253b6b4c000000000000000000', bin2hex($plan->getOutputsData()[0]));
        self::assertSame('', $plan->getOutputsData()[1]);
        $input->setPlan($plan);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Nervos);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError());
        self::assertSame('0x09a45a15e48f985b554a0b6e5f0857913cc492ec061cc9b0b2befa4b24609a4a', $output->getTransactionId());
        $transaction = json_decode($output->getTransactionJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['0x00601e253b6b4c000000000000000000', '0x'], $transaction['outputs_data']);
        self::assertCount(2, $transaction['witnesses']);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['ckb1qzda0cr08m85hc8jlnfp3zer7xulejywt49kt2rr0vthywaa50xwsqdtyq04tvp02wectaumxn0664yw2jd53lqk4mxg3', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
