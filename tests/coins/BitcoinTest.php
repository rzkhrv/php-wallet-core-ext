<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Bitcoin\Proto;
use TW\Common\Proto\SigningError;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\BitcoinAddress;
use WalletCore\BitcoinMessageSigner;
use WalletCore\BitcoinScript;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\Derivation;
use WalletCore\HDVersion;
use WalletCore\HDWallet;
use WalletCore\HRP;
use WalletCore\PrivateKey;
use WalletCore\PublicKey;
use WalletCore\PublicKeyType;
use WalletCore\Purpose;
use WalletCore\SegwitAddress;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class BitcoinTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Bitcoin;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('BTC', $symbol);
        self::assertSame(8, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Bitcoin);

        self::assertSame('bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Bitcoin;
        $path = "m/84'/0'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Bitcoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/84'/0'/0'/0/1.
        self::assertSame('3ca5e5b4582de37beae214693b545a0d58c57c209350d1e9d2c8455b408105ec', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('bc1qr50phjz4yezaqmh2hkdzekk575mfcml7n5yzea', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Bitcoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Bitcoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/84'/0'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Bitcoin;
        $expected = 'bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Bitcoin);

        self::assertSame($expected, $valid);
    }

    #[DataProvider('derivationProvider')]
    public function testNamedDerivation(
        int $derivation,
        string $path,
        string $defaultAddress,
        bool $matchesDefaultAddress,
    ): void {
        $coin = CoinType::Bitcoin;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, $path);
        $namedKey = $wallet->getKeyDerivation($coin, $derivation);
        self::assertNotNull($key);
        self::assertNotNull($namedKey);
        self::assertSame($key->data(), $namedKey->data());
        $publicKey = $key->getPublicKey($coin);
        self::assertNotNull($publicKey);

        $address = $wallet->getAddressDerivation($coin, $derivation);

        self::assertSame($path, CoinType::derivationPathWithDerivation($coin, $derivation));
        self::assertNotEmpty($address);
        self::assertSame($address, CoinType::deriveAddressFromPublicKeyAndDerivation($coin, $publicKey, $derivation));
        self::assertSame($matchesDefaultAddress, $address === $defaultAddress);
    }

    #[DataProvider('extendedKeyProvider')]
    public function testExtendedKeys(
        int $purpose,
        int $publicVersion,
        int $privateVersion,
        string $expectedPublicKey,
        string $expectedPrivateKey,
    ): void {
        // Wallet Core 4.8.7: tests/interface/TWHDWalletTests.cpp: ExtendedKeys.
        $wallet = HDWallet::createWithMnemonic('abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about', '');
        self::assertNotNull($wallet);
        $coin = CoinType::Bitcoin;

        $publicKey = $wallet->getExtendedPublicKey($purpose, $coin, $publicVersion);
        $privateKey = $wallet->getExtendedPrivateKey($purpose, $coin, $privateVersion);

        self::assertSame($expectedPublicKey, $publicKey);
        self::assertSame($expectedPrivateKey, $privateKey);
    }

    public function testAddressFromExtendedPublicKey(): void
    {
        // Wallet Core 4.8.7: tests/interface/TWHDWalletTests.cpp: PublicKeyFromZ.
        $coin = CoinType::Bitcoin;
        $zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
        $path = "m/84'/0'/0'/0/4";

        $publicKey = HDWallet::getPublicKeyFromExtended($zpub, $coin, $path);
        self::assertNotNull($publicKey);
        $address = CoinType::deriveAddressFromPublicKey($coin, $publicKey);

        self::assertSame('03995137c8eb3b223c904259e9b571a8939a0ec99b0717684c3936407ca8538c1b', bin2hex($publicKey->data()));
        self::assertSame('bc1qm97vqzgj934vnaq9s53ynkyf9dgr05rargr04n', $address);
    }

    public function testCreateLegacyAddressFromBytes(): void
    {
        // Wallet Core: TWBitcoinAddressTests.cpp.
        $data = hex2bin('00769bdff96a02f9135a1d19b749db6a78fe07dc90');

        $address = BitcoinAddress::createWithData($data);

        self::assertNotNull($address);
        self::assertSame('1Bp9U1ogV3A14FMvKbRJms7ctyso4Z4Tcx', $address->description());
        self::assertSame(0, $address->prefix());
        self::assertSame('769bdff96a02f9135a1d19b749db6a78fe07dc90', bin2hex($address->keyhash()));
    }

    public function testCreateSegwitAddressFromPublicKey(): void
    {
        // Wallet Core: TWSegwitAddressTests.cpp.
        $key = PublicKey::createWithData(hex2bin('0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798'), PublicKeyType::SECP256k1);
        self::assertNotNull($key);

        $address = SegwitAddress::createWithPublicKey(HRP::Bitcoin, $key);

        self::assertNotNull($address);
        self::assertSame('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', $address->description());
    }

    public function testInspectTaprootAddress(): void
    {
        $address = SegwitAddress::createWithString('bc1ptmsk7c2yut2xah4pgflpygh2s7fh0cpfkrza9cjj29awapv53mrslgd5cf');
        self::assertNotNull($address);

        $version = $address->witnessVersion();
        $program = $address->witnessProgram();

        self::assertSame(1, $version);
        self::assertSame('5ee16f6144e2d46edea1427e1222ea879377e029b0c5d2e252517aee85948ec7', bin2hex($program));
    }

    public function testBuildWitnessScript(): void
    {
        $hash = hex2bin('751e76e8199196d454941c45d1b3a323f1433bd6');

        $script = BitcoinScript::buildPayToWitnessPubkeyHash($hash);

        self::assertNotNull($script);
        self::assertTrue($script->isPayToWitnessPublicKeyHash());
        self::assertSame('0014751e76e8199196d454941c45d1b3a323f1433bd6', bin2hex($script->data()));
        self::assertSame($hash, $script->matchPayToWitnessPublicKeyHash());
    }

    public function testPlanTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/Bitcoin/TWBitcoinSigningTests.cpp: ExtraOutputs
        $coin = CoinType::Bitcoin;
        $sender = '1MhdctqCwYMn2DT4mshpwvYtfF98wBojXS';
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);

        $input = new Proto\SigningInput([
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'private_key' => [hex2bin('e253373989199da27c48680e3a3fc0f648d50f9a727ef17a7fe6a4dc3b159129')],
            'amount' => 2_000,
            'byte_fee' => 6,
            'to_address' => '1PRuxNSZwUXym6A31kmrArdT2BGJiTna19',
            'change_address' => $sender,
            'utxo' => [
                new Proto\UnspentTransaction([
                    'out_point' => new Proto\OutPoint([
                        'hash' => strrev(hex2bin('d15d38de9a619809b575532a235d23947c4ff7d219d3feb6c5b6105d23360f4e')),
                        'index' => 0,
                        'sequence' => 4_294_967_290,
                    ]),
                    'amount' => 10_000,
                    'script' => $script->data(),
                ]),
            ],
            'extra_outputs' => [
                new Proto\OutputAddress([
                    'to_address' => 'bc1qkm0awulcn94gmtjwzwkvnpflc3ytt7a6cjentn',
                    'amount' => 2_000,
                ]),
                new Proto\OutputAddress([
                    'to_address' => 'bc1pqa49cxxdqyr49nwe2379tq4xsc4e8qe8mdxyjx3mprnftcde0v4s3lnhzq',
                    'amount' => 2_000,
                ]),
            ],
        ]);

        $planData = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotEmpty($planData);

        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($planData);

        self::assertSame(SigningError::OK, $plan->getError());
        self::assertSame(2000, (int) $plan->getAmount());
        self::assertSame(1800, (int) $plan->getFee());
        self::assertSame(2200, (int) $plan->getChange());
    }

    public function testSignAndVerifyMessage(): void
    {
        // Wallet Core: Bitcoin/MessageSignerTests.cpp.
        $key = PrivateKey::createWithData(hex2bin('afeefca74d9a325cf1d6b6911d61a65c32afa8e02bd5e78e2e4ac2910bab45f5'));
        self::assertNotNull($key);
        $address = '19cAJn4Ms8jodBBGtroBNNpCZiHAWGAq7X';
        $message = 'test signature';

        $signature = BitcoinMessageSigner::signMessage($key, $address, $message);

        self::assertSame('ILH5K7JQLaRGaKGXXH5mYM6FIIy9IWyY4JUPI+PHYY4WaupxUbg+zy0bhBCrDuehy9x4WidwjkRR1GSLnWvOXBo=', $signature);
        self::assertTrue(BitcoinMessageSigner::verifyMessage($address, $message, $signature));
    }

    public function testSignTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/Bitcoin/TWBitcoinSigningTests.cpp: ExtraOutputs
        $coin = CoinType::Bitcoin;
        $sender = '1MhdctqCwYMn2DT4mshpwvYtfF98wBojXS';
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);

        $input = new Proto\SigningInput([
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'private_key' => [hex2bin('e253373989199da27c48680e3a3fc0f648d50f9a727ef17a7fe6a4dc3b159129')],
            'amount' => 2_000,
            'byte_fee' => 6,
            'to_address' => '1PRuxNSZwUXym6A31kmrArdT2BGJiTna19',
            'change_address' => $sender,
            'utxo' => [
                new Proto\UnspentTransaction([
                    'out_point' => new Proto\OutPoint([
                        'hash' => strrev(hex2bin('d15d38de9a619809b575532a235d23947c4ff7d219d3feb6c5b6105d23360f4e')),
                        'index' => 0,
                        'sequence' => 4_294_967_290,
                    ]),
                    'amount' => 10_000,
                    'script' => $script->data(),
                ]),
            ],
            'extra_outputs' => [
                new Proto\OutputAddress([
                    'to_address' => 'bc1qkm0awulcn94gmtjwzwkvnpflc3ytt7a6cjentn',
                    'amount' => 2_000,
                ]),
                new Proto\OutputAddress([
                    'to_address' => 'bc1pqa49cxxdqyr49nwe2379tq4xsc4e8qe8mdxyjx3mprnftcde0v4s3lnhzq',
                    'amount' => 2_000,
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
        self::assertSame(
            '01000000014e0f36235d10b6c5b6fed319d2f74f7c94235d232a5375b50998619ade385dd1000000006b483045022100e044cce5c2cf141f725bb88dafc74d7db8679826838f1dd4ba35fa57a159454202204aed2c624dc53b6f98adbc818689af4d99c6d9cdb0377979a74982b0624d6e9e0121030f209b6ada5edb42c77fd2bc64ad650ae38314c8f451f3e36d80bc8e26f132cbfaffffff04d0070000000000001976a914f608f4635f9072c4f92715e5a6c35c058a9d6fe988ac98080000000000001976a914e311b8d6ddff856ce8e9a4e03bc6d4fe5050a83d88acd007000000000000160014b6dfd773f8996a8dae4e13acc9853fc448b5fbbad007000000000000225120076a5c18cd010752cdd9547c5582a6862b938327db4c491a3b08e695e1b97b2b00000000',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignSegwitInput(): void
    {
        // Wallet Core 4.8.7: SignP2TR_5df51e. Spend a SegWit input to a Taproot recipient.
        $coin = CoinType::Bitcoin;
        $sender = 'bc1qpjult34k9spjfym8hss2jrwjgf0xjf40ze0pp8';
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);
        self::assertSame('00140cb9f5c6b62c03249367bc20a90dd2425e6926af', bin2hex($script->data()));
        $input = new Proto\SigningInput([
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'private_key' => [hex2bin('13fcaabaf9e71ffaf915e242ec58a743d55f102cf836968e5bd4881135e0c52c')],
            'amount' => 1_100, // satoshis
            'byte_fee' => 1, // sat/vbyte
            'to_address' => 'bc1ptmsk7c2yut2xah4pgflpygh2s7fh0cpfkrza9cjj29awapv53mrslgd5cf',
            'change_address' => $sender,
            'utxo' => [new Proto\UnspentTransaction([
                'out_point' => new Proto\OutPoint([
                    'hash' => strrev(hex2bin('c24bd72e3eaea797bd5c879480a0db90980297bc7085efda97df2bf7d31413fb')),
                    'index' => 1,
                    'sequence' => 4_294_967_295,
                ]),
                'amount' => 49_429,
                'script' => $script->data(),
            ])],
        ]);

        $data = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotNull($data);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($data);
        self::assertSame(SigningError::OK, $plan->getError());
        self::assertSame(153, (int) $plan->getFee());
        self::assertSame(48176, (int) $plan->getChange());
        $input->setPlan($plan);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Bitcoin);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '01000000000101fb1314d3f72bdf97daef8570bc97029890dba08094875cbd97a7ae3e2ed74bc20100000000ffffffff024c040000000000002251205ee16f6144e2d46edea1427e1222ea879377e029b0c5d2e252517aee85948ec730bc0000000000001600140cb9f5c6b62c03249367bc20a90dd2425e6926af02473044022021cea91157fdab33226e38ee7c1a686538fc323f5e28feb35775cf82ba8c62210220723743b150cea8ead877d8b8d059499779a5df69f9bdc755c9f968c56cfb528f0121021e582a887bd94d648a9267143eb600449a8d59a0db0653740b1378067a6d0cee00000000',
            bin2hex($output->getEncoded()),
        );
    }

    public function testSignMaximumAmount(): void
    {
        // Wallet Core 4.8.7: SignP2TR_5df51e. Spend a SegWit input to a Taproot recipient.
        $coin = CoinType::Bitcoin;
        $sender = 'bc1qpjult34k9spjfym8hss2jrwjgf0xjf40ze0pp8';
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);
        self::assertSame('00140cb9f5c6b62c03249367bc20a90dd2425e6926af', bin2hex($script->data()));
        $input = new Proto\SigningInput([
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'private_key' => [hex2bin('13fcaabaf9e71ffaf915e242ec58a743d55f102cf836968e5bd4881135e0c52c')],
            'amount' => 1_100, // satoshis
            'byte_fee' => 1, // sat/vbyte
            'to_address' => 'bc1ptmsk7c2yut2xah4pgflpygh2s7fh0cpfkrza9cjj29awapv53mrslgd5cf',
            'change_address' => $sender,
            'utxo' => [new Proto\UnspentTransaction([
                'out_point' => new Proto\OutPoint([
                    'hash' => strrev(hex2bin('c24bd72e3eaea797bd5c879480a0db90980297bc7085efda97df2bf7d31413fb')),
                    'index' => 1,
                    'sequence' => 4_294_967_295,
                ]),
                'amount' => 49_429,
                'script' => $script->data(),
            ])],
        ]);
        // Spend all available satoshis minus the estimated mining fee.
        $input->setUseMaxAmount(true);

        $data = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotNull($data);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($data);
        self::assertSame(SigningError::OK, $plan->getError());
        self::assertSame(0, (int) $plan->getChange());
        self::assertGreaterThan(0, (int) $plan->getFee());
        self::assertSame(49429, (int) $plan->getAmount() + (int) $plan->getFee());
        $input->setPlan($plan);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Bitcoin);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getEncoded());
        self::assertCount(1, $output->getTransaction()->getOutputs());
        self::assertSame((int) $plan->getAmount(), (int) $output->getTransaction()->getOutputs()[0]->getValue());
    }

    public function testSignBrc20CommitV2(): void
    {
        // Wallet Core 4.8.7: SignBRC20TransferCommitV2. Commit only; reveal is a separate transaction.
        $key = PrivateKey::createWithData(hex2bin('e253373989199da27c48680e3a3fc0f648d50f9a727ef17a7fe6a4dc3b159129'));
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeySecp256k1(true);
        self::assertNotNull($publicKey);
        $pub = $publicKey->data();
        $v2 = new \TW\BitcoinV2\Proto\SigningInput([
            'private_keys' => [$key->data()],
            'dangerous_use_fixed_schnorr_rng' => true, // Test vectors only; never enable in production.
            'chain_info' => new \TW\BitcoinV2\Proto\ChainInfo(['p2pkh_prefix' => 0, 'p2sh_prefix' => 5]),
            'builder' => new \TW\BitcoinV2\Proto\TransactionBuilder([
                'version' => \TW\BitcoinV2\Proto\TransactionVersion::V2,
                'input_selector' => \TW\BitcoinV2\Proto\InputSelector::UseAll,
                'fixed_dust_threshold' => 546,
                'inputs' => [new \TW\BitcoinV2\Proto\Input([
                    'out_point' => new \TW\Utxo\Proto\OutPoint([
                        'hash' => hex2bin('089098890d2653567b9e8df2d1fbe5c3c8bf1910ca7184e301db0ad3b495c88e'),
                        'vout' => 1,
                    ]),
                    'value' => 26_400,
                    'sighash_type' => 1,
                    'script_builder' => new \TW\BitcoinV2\Proto\Input\InputBuilder([
                        'p2wpkh' => new \TW\BitcoinV2\Proto\PublicKeyOrHash(['pubkey' => $pub]),
                    ]),
                ])],
                'outputs' => [
                    new \TW\BitcoinV2\Proto\Output([
                        'value' => 7_000,
                        'builder' => new \TW\BitcoinV2\Proto\Output\OutputBuilder([
                            'brc20_inscribe' => new \TW\BitcoinV2\Proto\Output\OutputBrc20Inscription([
                                'ticker' => 'oadf', 'transfer_amount' => '20', 'inscribe_to' => $pub,
                            ]),
                        ]),
                    ]),
                    new \TW\BitcoinV2\Proto\Output([
                        'value' => 16_400, // 26400 - 7000 - 3000 miner fee
                        'builder' => new \TW\BitcoinV2\Proto\Output\OutputBuilder([
                            'p2wpkh' => new \TW\BitcoinV2\Proto\PublicKeyOrHash(['pubkey' => $pub]),
                        ]),
                    ]),
                ],
            ]),
        ]);
        $input = new Proto\SigningInput(['signing_v2' => $v2]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Bitcoin);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        $result = $output->getSigningResultV2();
        self::assertNotNull($result);
        self::assertSame(SigningError::OK, $result->getError(), $result->getErrorMessage());
        self::assertSame(
            '02000000000101089098890d2653567b9e8df2d1fbe5c3c8bf1910ca7184e301db0ad3b495c88e0100000000ffffffff02581b000000000000225120e8b706a97732e705e22ae7710703e7f589ed13c636324461afa443016134cc051040000000000000160014e311b8d6ddff856ce8e9a4e03bc6d4fe5050a83d02483045022100a44aa28446a9a886b378a4a65e32ad9a3108870bd725dc6105160bed4f317097022069e9de36422e4ce2e42b39884aa5f626f8f94194d1013007d5a1ea9220a06dce0121030f209b6ada5edb42c77fd2bc64ad650ae38314c8f451f3e36d80bc8e26f132cb00000000',
            bin2hex($result->getEncoded()),
        );
        self::assertSame('797d17d47ae66e598341f9dfdea020b04d4017dcf9cc33f0e51f7a6082171fb1', bin2hex($result->getTxid()));
        self::assertSame(3000, (int) $result->getFee());
        self::assertSame('797d17d47ae66e598341f9dfdea020b04d4017dcf9cc33f0e51f7a6082171fb1', \WalletCore\TransactionUtil::calcTxHash(CoinType::Bitcoin, bin2hex($result->getEncoded())));
    }

    public function testSignTaprootInputV2(): void
    {
        // Upstream bitcoin_sign/p2tr_key_path.rs: test_bitcoin_sign_input_p2tr_key_path_with_change_output_a9c63d.
        $key = PrivateKey::createWithData(hex2bin('7fa638b0df495b2968ae6dc7011c4db08c86df16c91aa71a77ee6a222954e5bb'));
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeySecp256k1(true);
        self::assertNotNull($publicKey);
        // BitcoinV2 p2tr_key_path expects a compressed SEC1 public key.
        $publicKeyData = $publicKey->data();
        self::assertSame(33, strlen($publicKeyData));
        $v2 = new \TW\BitcoinV2\Proto\SigningInput([
            'private_keys' => [$key->data()],
            'dangerous_use_fixed_schnorr_rng' => true, // Test vectors only; never enable in production.
            'chain_info' => new \TW\BitcoinV2\Proto\ChainInfo(['p2pkh_prefix' => 0, 'p2sh_prefix' => 5, 'hrp' => 'bc']),
            'builder' => new \TW\BitcoinV2\Proto\TransactionBuilder([
                'version' => \TW\BitcoinV2\Proto\TransactionVersion::V2,
                'input_selector' => \TW\BitcoinV2\Proto\InputSelector::SelectDescending,
                'fee_per_vb' => 8,
                'fixed_dust_threshold' => 546,
                'inputs' => [new \TW\BitcoinV2\Proto\Input([
                    'out_point' => new \TW\Utxo\Proto\OutPoint([
                        'hash' => strrev(hex2bin('75ed78f0ae2bad924065d2357ef01184ceee2181c44e03337746512be9371a82')),
                        'vout' => 1,
                    ]),
                    'value' => 8_802,
                    'sighash_type' => 1,
                    'script_builder' => new \TW\BitcoinV2\Proto\Input\InputBuilder(['p2tr_key_path' => $publicKeyData]),
                ])],
                'outputs' => [new \TW\BitcoinV2\Proto\Output([
                    'value' => 3_000,
                    'to_address' => 'bc1qtaquch7d90x37qre6f75z5a6l0luzh0c03epyz',
                ])],
                'change_output' => new \TW\BitcoinV2\Proto\Output([
                    'builder' => new \TW\BitcoinV2\Proto\Output\OutputBuilder(['p2tr_key_path' => $publicKeyData]),
                ]),
            ]),
        ]);
        $input = new Proto\SigningInput(['signing_v2' => $v2]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Bitcoin);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        $result = $output->getSigningResultV2();
        self::assertNotNull($result);
        self::assertSame(SigningError::OK, $result->getError(), $result->getErrorMessage());
        self::assertSame(
            '02000000000101821a37e92b51467733034ec48121eece8411f07e35d2654092ad2baef078ed750100000000ffffffff02b80b0000000000001600145f41cc5fcd2bcd1f0079d27d4153bafbffc15df83212000000000000225120412a773e0bba5cfb5462d024cd4bf2cce1b8688a9e7a7a3f8507ebba8f00de580140cbe4d13bc9e067b042179e2c217e4e4b1d552119d12839aa4df11c21282f9159e2c4b58a4f22b291c200c0d0c5f277902282bdd78589dff0edbea89d3f00d77400000000',
            bin2hex($result->getEncoded()),
        );
        self::assertSame('a9c63dfe54f6ff462155d966a54226c456b3e43b52a9abe55d7fa87d6564c6e4', bin2hex($result->getTxid()));
        self::assertSame(1144, (int) $result->getFee());
        self::assertSame(142, (int) $result->getVsize());
        self::assertSame(568, (int) $result->getWeight());
    }

    public function testRejectMissingUtxos(): void
    {
        // Wallet Core 4.8.7: Sign_NegativeNoUtxos.
        $input = new Proto\SigningInput([
            'coin_type' => CoinType::Bitcoin,
            'hash_type' => BitcoinScript::hashTypeForCoin(CoinType::Bitcoin),
            'amount' => 335_790_000,
            'byte_fee' => 1,
            'to_address' => '1Bp9U1ogV3A14FMvKbRJms7ctyso4Z4Tcx',
            'change_address' => '1FQc5LdgGHMHEN9nwkjmz6tWkxhPpxBvBU',
        ]);

        $data = AnySigner::plan($input->serializeToString(), CoinType::Bitcoin);
        self::assertNotNull($data);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($data);

        $data = AnySigner::sign($input->serializeToString(), CoinType::Bitcoin);
        self::assertNotNull($data);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::Error_missing_input_utxos, $plan->getError());
        self::assertSame(SigningError::Error_missing_input_utxos, $output->getError());
        self::assertSame('', $output->getEncoded());
    }

    public function testRejectInsufficientBalance(): void
    {
        // Wallet Core 4.8.7: SignP2TR_5df51e. Spend a SegWit input to a Taproot recipient.
        $coin = CoinType::Bitcoin;
        $sender = 'bc1qpjult34k9spjfym8hss2jrwjgf0xjf40ze0pp8';
        $script = BitcoinScript::lockScriptForAddress($sender, $coin);
        self::assertNotNull($script);
        self::assertSame('00140cb9f5c6b62c03249367bc20a90dd2425e6926af', bin2hex($script->data()));
        $input = new Proto\SigningInput([
            'coin_type' => $coin,
            'hash_type' => BitcoinScript::hashTypeForCoin($coin),
            'private_key' => [hex2bin('13fcaabaf9e71ffaf915e242ec58a743d55f102cf836968e5bd4881135e0c52c')],
            'amount' => 1_100, // satoshis
            'byte_fee' => 1, // sat/vbyte
            'to_address' => 'bc1ptmsk7c2yut2xah4pgflpygh2s7fh0cpfkrza9cjj29awapv53mrslgd5cf',
            'change_address' => $sender,
            'utxo' => [new Proto\UnspentTransaction([
                'out_point' => new Proto\OutPoint([
                    'hash' => strrev(hex2bin('c24bd72e3eaea797bd5c879480a0db90980297bc7085efda97df2bf7d31413fb')),
                    'index' => 1,
                    'sequence' => 4_294_967_295,
                ]),
                'amount' => 49_429,
                'script' => $script->data(),
            ])],
        ]);
        // Request less than the input value to avoid implicit send-max mode.
        // Only 1 satoshi remains for fees, which cannot fund this transaction.
        $input->setAmount(49_428);

        $data = AnySigner::plan($input->serializeToString(), $coin);
        self::assertNotNull($data);
        $plan = new Proto\TransactionPlan();
        $plan->mergeFromString($data);

        $data = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotNull($data);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::Error_not_enough_utxos, $plan->getError());
        self::assertSame(SigningError::Error_not_enough_utxos, $output->getError());
        self::assertSame('', $output->getEncoded());
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9q', false],
        ];
    }

    public static function derivationProvider(): array
    {
        return [
            'BitcoinSegwit' => [
                Derivation::BitcoinSegwit,
                "m/84'/0'/0'/0/0",
                'bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d',
                true,
            ],
            'BitcoinLegacy' => [
                Derivation::BitcoinLegacy,
                "m/44'/0'/0'/0/0",
                'bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d',
                false,
            ],
            'BitcoinTestnet' => [
                Derivation::BitcoinTestnet,
                "m/84'/1'/0'/0/0",
                'bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d',
                false,
            ],
            'BitcoinTaproot' => [
                Derivation::BitcoinTaproot,
                "m/86'/0'/0'/0/0",
                'bc1quvuarfksewfeuevuc6tn0kfyptgjvwsvrprk9d',
                false,
            ],
        ];
    }

    public static function extendedKeyProvider(): array
    {
        return [
            'BIP44' => [
                Purpose::BIP44,
                HDVersion::XPUB,
                HDVersion::XPRV,
                'xpub6BosfCnifzxcFwrSzQiqu2DBVTshkCXacvNsWGYJVVhhawA7d4R5WSWGFNbi8Aw6ZRc1brxMyWMzG3DSSSSoekkudhUd9yLb6qx39T9nMdj',
                'xprv9xpXFhFpqdQK3TmytPBqXtGSwS3DLjojFhTGht8gwAAii8py5X6pxeBnQ6ehJiyJ6nDjWGJfZ95WxByFXVkDxHXrqu53WCRGypk2ttuqncb',
            ],
            'BIP84' => [
                Purpose::BIP84,
                HDVersion::ZPUB,
                HDVersion::ZPRV,
                'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs',
                'zprvAdG4iTXWBoARxkkzNpNh8r6Qag3irQB8PzEMkAFeTRXxHpbF9z4QgEvBRmfvqWvGp42t42nvgGpNgYSJA9iefm1yYNZKEm7z6qUWCroSQnE',
            ],
        ];
    }
}
