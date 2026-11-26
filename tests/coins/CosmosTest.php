<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Cosmos\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base64;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\HDWallet;
use WalletCore\PrivateKey;
use WalletCore\PublicKey;
use WalletCore\PublicKeyType;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class CosmosTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Cosmos;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);
        $chainId = CoinType::chainId($coin);

        self::assertSame('ATOM', $symbol);
        self::assertSame(6, $decimals);
        self::assertSame('cosmoshub-4', $chainId);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Cosmos);

        self::assertSame('cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekn', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Cosmos;
        $path = "m/44'/118'/0'/0/0";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekn', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Cosmos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getDerivedKey($coin, 0, 0, 1);
        self::assertNotNull($key);
        // Independent BIP39/BIP32 reference: m/44'/118'/0'/0/1.
        self::assertSame('6c9c31dbacc911664b4683c16956ca708be217d96f4b9b7a0db6fc35413fb4ae', bin2hex($key->data()));

        $address = CoinType::deriveAddress($coin, $key);
        self::assertSame('cosmos1tmvlpttlwfmtatycgdyvyeuh989udrdrpuph3j', $address);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekn', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Cosmos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekn', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Cosmos;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/118'/0'/0/0");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        self::assertIsString($raw);
        self::assertSame(32, strlen($raw));
        self::assertTrue(PrivateKey::isValid($raw, CoinType::curve($coin)));

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekn', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Cosmos;
        $expected = 'cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekn';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Cosmos);

        self::assertSame($expected, $valid);
    }

    public function testCreateCustomBech32Address(): void
    {
        // Wallet Core: CosmosTestHelpers.h and Osmosis/TWAnyAddressTests.cpp.
        $key = PublicKey::createWithData(hex2bin('02cbfdb5e472893322294e60cf0883d43df431e1089d29ecb447a9e6d55045aae5'), PublicKeyType::SECP256k1);
        self::assertNotNull($key);

        $address = AnyAddress::createBech32WithPublicKey($key, CoinType::Cosmos, 'osmo');

        self::assertNotNull($address);
        self::assertSame('osmo1mry47pkga5tdswtluy0m8teslpalkdq0k6r728', $address->description());
        self::assertTrue(AnyAddress::isValidBech32($address->description(), CoinType::Cosmos, 'osmo'));
    }

    public function testSignTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/Cosmos/TWAnySignerTests.cpp: SignTx
        $coin = CoinType::Cosmos;
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('8bbec3772ddb4df68f3186440380c301af116d1422001c1877d6f5e4dba8c8af'),
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::Cosmos),
            'account_number' => 546_179,
            'sequence' => 0,
            'fee' => new Proto\Fee([
                'gas' => 200_000,
                'amounts' => [new Proto\Amount(['denom' => 'uatom', 'amount' => '1000'])],
            ]),
            'messages' => [new Proto\Message([
                'send_coins_message' => new Proto\Message\Send([
                    'from_address' => 'cosmos1mky69cn8ektwy0845vec9upsdphktxt03gkwlx',
                    'to_address' => 'cosmos18s0hdnsllgcclweu9aymw4ngktr2k0rkygdzdp',
                    'amounts' => [new Proto\Amount(['denom' => 'uatom', 'amount' => '400000'])],
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'afbd513a776f4fdf470ef7f9675f21ae9d630fc4d635d8dbaa0dc0a716434cd07e02510765d4673dfa880825bae8e67cb367396ff6b976fc6b19a31fc95e8097',
            bin2hex($output->getSignature()),
        );
        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            '85392373f54577562067030bf0d61596c91188aa5e6ca8ffe731bd0349296411',
            hash('sha256', Base64::decode($transaction['tx_bytes'])),
        );
    }

    public function testSignTokenTransferBank(): void
    {
        // Wallet Core 4.8.7: src/proto/Cosmos.proto: Message.Send and Amount (adapted offline bank transfer)
        $coin = CoinType::Cosmos;
        // Local bank transfer of an IBC token, not an IBC packet transfer.
        // The denomination hash is synthetic; obtain the real denom from chain state.
        $tokenDenom = 'ibc/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('8bbec3772ddb4df68f3186440380c301af116d1422001c1877d6f5e4dba8c8af'),
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::Cosmos),
            'account_number' => 546_179,
            'sequence' => 0,
            'fee' => new Proto\Fee([
                'gas' => 200_000,
                'amounts' => [new Proto\Amount(['denom' => 'uatom', 'amount' => '1000'])],
            ]),
            'messages' => [new Proto\Message([
                'send_coins_message' => new Proto\Message\Send([
                    'from_address' => 'cosmos1mky69cn8ektwy0845vec9upsdphktxt03gkwlx',
                    'to_address' => 'cosmos18s0hdnsllgcclweu9aymw4ngktr2k0rkygdzdp',
                    'amounts' => [new Proto\Amount(['denom' => $tokenDenom, 'amount' => '400000'])],
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertNotSame('', $output->getSignature());
        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        $txBytes = Base64::decode($transaction['tx_bytes']);
        self::assertIsString($txBytes);
        // Independent Cosmos SDK SignDoc / TxRaw reference (deterministic secp256k1).
        self::assertSame(
            'd5ad4cee107f3852cefec709f817f5aa377c95599af0bab548d30d3dc815b97e120b0d3ccce3845755dbc5b08958e47a67df61f23cfba224c680a3e0abd1921f',
            bin2hex($output->getSignature()),
        );
        self::assertSame('6e7fdb561b4da3e1804f17f9d6dbda91d96533012b6d8aa09e05224f05d5edbe', hash('sha256', $txBytes));
    }

    public function testSignIbcTransfer(): void
    {
        // Upstream SignIbcTransferProtobuf_817101. Interchain packet transfer, not a local IBC-denom MsgSend.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('8bbec3772ddb4df68f3186440380c301af116d1422001c1877d6f5e4dba8c8af'),
            'signing_mode' => Proto\SigningMode::Protobuf,
            'account_number' => 546_179,
            'chain_id' => CoinType::chainId(CoinType::Cosmos),
            'sequence' => 2,
            'fee' => new Proto\Fee([
                'gas' => 500_000,
                'amounts' => [new Proto\Amount(['denom' => 'uatom', 'amount' => '12500'])],
            ]),
            'messages' => [new Proto\Message([
                'transfer_tokens_message' => new Proto\Message\Transfer([
                    'source_port' => 'transfer',
                    'source_channel' => 'channel-141', // historical channel; obtain current route before use
                    'sender' => 'cosmos1mky69cn8ektwy0845vec9upsdphktxt03gkwlx',
                    'receiver' => 'osmo18s0hdnsllgcclweu9aymw4ngktr2k0rkvn7jmn',
                    'token' => new Proto\Amount(['denom' => 'uatom', 'amount' => '100000']), // 0.1 ATOM
                    'timeout_height' => new Proto\Height(['revision_number' => 1, 'revision_height' => 8800000]),
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Cosmos);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('817101f3d96314ad028733248b28bafad535024d7d2c8875d3fe31dc159f096b', hash('sha256', Base64::decode($transaction['tx_bytes'])));
        self::assertSame(
            'ad07216745324f82e868b5286fef10b7b57df71cd5a116508ab3dadfd9870a8d347c54512a76ef0b5e8ae80710c55310838186cf38b76808713fe70ffd7228e5',
            bin2hex($output->getSignature()),
        );
    }

    public function testSignStake(): void
    {
        // Upstream Cosmos/StakingTests.cpp: Staking, historical testnet.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('80e81ea269e66a0a05b11236df7919fb7fbeedba87452d667489d7403a02f005'),
            'signing_mode' => Proto\SigningMode::Protobuf,
            'account_number' => 1_037,
            'chain_id' => 'gaia-13003',
            'sequence' => 7,
            'fee' => new Proto\Fee(['gas' => 101_721, 'amounts' => [new Proto\Amount(['denom' => 'muon', 'amount' => '1018'])]]),
            'messages' => [new Proto\Message([
                'stake_message' => new Proto\Message\Delegate([
                    'delegator_address' => 'cosmos1hsk6jryyqjfhp5dhc55tc9jtckygx0eph6dd02',
                    'validator_address' => 'cosmosvaloper1zkupr83hrzkn3up5elktzcq3tuft8nxsmwdqgp',
                    'amount' => new Proto\Amount(['denom' => 'muon', 'amount' => '10']),
                ]),
            ])],
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Cosmos);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            'CpsBCpgBCiMvY29zbW9zLnN0YWtpbmcudjFiZXRhMS5Nc2dEZWxlZ2F0ZRJxCi1jb3Ntb3MxaHNrNmpyeXlxamZocDVkaGM1NXRjOWp0Y2t5Z3gwZXBoNmRkMDISNGNvc21vc3ZhbG9wZXIxemt1cHI4M2hyemtuM3VwNWVsa3R6Y3EzdHVmdDhueHNtd2RxZ3AaCgoEbXVvbhICMTASZgpQCkYKHy9jb3Ntb3MuY3J5cHRvLnNlY3AyNTZrMS5QdWJLZXkSIwohAlcobsPzfTNVe7uqAAsndErJAjqplnyudaGB0f+R+p3FEgQKAggBGAcSEgoMCgRtdW9uEgQxMDE4ENmaBhpA8O9Jm/kL6Za2I3poDs5vpMowYJgNvYCJBRU/vxAjs0lNZYsq40qpTbwOTbORjJA5UjQ6auc40v6uCFT4q4z+uA==',
            $transaction['tx_bytes'],
        );
        self::assertSame(
            'f0ef499bf90be996b6237a680ece6fa4ca3060980dbd808905153fbf1023b3494d658b2ae34aa94dbc0e4db3918c903952343a6ae738d2feae0854f8ab8cfeb8',
            bin2hex($output->getSignature()),
        );
    }

    public function testSignMultipleMessagesWithMemo(): void
    {
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('8bbec3772ddb4df68f3186440380c301af116d1422001c1877d6f5e4dba8c8af'),
            'signing_mode' => Proto\SigningMode::Protobuf,
            'chain_id' => CoinType::chainId(CoinType::Cosmos),
            'account_number' => 546_179,
            'sequence' => 0,
            'fee' => new Proto\Fee([
                'gas' => 200_000,
                'amounts' => [new Proto\Amount(['denom' => 'uatom', 'amount' => '1000'])],
            ]),
            'messages' => [new Proto\Message([
                'send_coins_message' => new Proto\Message\Send([
                    'from_address' => 'cosmos1mky69cn8ektwy0845vec9upsdphktxt03gkwlx',
                    'to_address' => 'cosmos18s0hdnsllgcclweu9aymw4ngktr2k0rkygdzdp',
                    'amounts' => [new Proto\Amount(['denom' => 'uatom', 'amount' => '400000'])],
                ]),
            ])],
        ]);
        // A single transaction may carry multiple messages, with one memo and fee.
        $input->setMemo('invoice-42');
        $input->getMessages()[] = new Proto\Message([
            'send_coins_message' => new Proto\Message\Send([
                'from_address' => 'cosmos1mky69cn8ektwy0845vec9upsdphktxt03gkwlx',
                'to_address' => 'cosmos18s0hdnsllgcclweu9aymw4ngktr2k0rkygdzdp',
                'amounts' => [new Proto\Amount(['denom' => 'uatom', 'amount' => '500000'])],
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Cosmos);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());

        $transaction = json_decode($output->getSerialized(), true, 512, JSON_THROW_ON_ERROR);
        $bytes = Base64::decode($transaction['tx_bytes']);
        self::assertNotNull($bytes);
        self::assertSame(2, substr_count($bytes, '/cosmos.bank.v1beta1.MsgSend'));
        self::assertStringContainsString('invoice-42', $bytes);
        self::assertStringContainsString('400000', $bytes);
        self::assertStringContainsString('500000', $bytes);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekn', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
            'invalid checksum' => ['cosmos142j9u5eaduzd7faumygud6ruhdwme98qsy2ekq', false],
        ];
    }
}
