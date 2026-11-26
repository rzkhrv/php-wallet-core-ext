<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TW\Common\Proto\SigningError;
use TW\Solana\Proto;
use WalletCore\AnyAddress;
use WalletCore\AnySigner;
use WalletCore\Base58;
use WalletCore\Base64;
use WalletCore\CoinType;
use WalletCore\CoinTypeConfiguration;
use WalletCore\DataVector;
use WalletCore\Derivation;
use WalletCore\HDWallet;
use WalletCore\MessageSigner;
use WalletCore\PrivateKey;
use WalletCore\SolanaAddress;
use WalletCore\SolanaTransaction;
use WalletCore\TransactionDecoder;
use WalletCore\WalletConnectRequest;

// Address vectors: Wallet Core 4.8.7, CoinAddressDerivationTests.kt.
// Public test keys only; do not fund or broadcast.
final class SolanaTest extends TestCase
{
    public function testCoinConfiguration(): void
    {
        $coin = CoinType::Solana;

        $symbol = CoinTypeConfiguration::getSymbol($coin);
        $decimals = CoinTypeConfiguration::getDecimals($coin);

        self::assertSame('SOL', $symbol);
        self::assertSame(9, $decimals);
    }

    public function testAddressFromMnemonic(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $address = $wallet->getAddressForCoin(CoinType::Solana);

        self::assertSame('2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m', $address);
    }

    public function testAddressFromDerivationPath(): void
    {
        $wallet = HDWallet::createWithMnemonic(
            'shoot island position soft burden budget tooth cruel issue economy destroy above',
            '',
        );
        self::assertNotNull($wallet);

        $coin = CoinType::Solana;
        $path = "m/44'/501'/0'";
        $key = $wallet->getKey($coin, $path);
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertSame($path, CoinType::derivationPath($coin));
        self::assertSame('2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m', $address);
    }

    public function testAddressFromNonDefaultPath(): void
    {
        $coin = CoinType::Solana;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/501'/1'");
        self::assertNotNull($key);

        $address = CoinType::deriveAddress($coin, $key);

        self::assertNotNull($address);
        self::assertTrue(AnyAddress::isValid($address, $coin));
        self::assertNotSame('2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m', $address);
    }

    public function testAddressFromPublicKey(): void
    {
        $coin = CoinType::Solana;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKeyForCoin($coin);
        self::assertNotNull($key);
        $publicKey = $key->getPublicKeyByType(CoinType::publicKeyType($coin));
        self::assertNotNull($publicKey);

        $address = AnyAddress::createWithPublicKey($publicKey, $coin);
        self::assertNotNull($address);

        self::assertSame('2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m', $address->description());
    }

    public function testImportPrivateKey(): void
    {
        $coin = CoinType::Solana;
        $wallet = HDWallet::createWithMnemonic('shoot island position soft burden budget tooth cruel issue economy destroy above', '');
        self::assertNotNull($wallet);
        $key = $wallet->getKey($coin, "m/44'/501'/0'");
        self::assertNotNull($key);
        $raw = $key->data();
        self::assertNotNull($raw);

        $imported = PrivateKey::createWithData($raw);

        self::assertNotNull($imported);
        self::assertSame($raw, $imported->data());
        self::assertSame('2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m', CoinType::deriveAddress($coin, $imported));
    }

    public function testParseAddress(): void
    {
        $coin = CoinType::Solana;
        $expected = '2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m';

        $address = AnyAddress::createWithString($expected, $coin);

        self::assertNotNull($address);
        self::assertSame($coin, $address->coin());
        self::assertSame($expected, $address->description());
    }

    #[DataProvider('addressProvider')]
    public function testValidateAddress(string $address, bool $expected): void
    {
        $valid = AnyAddress::isValid($address, CoinType::Solana);

        self::assertSame($expected, $valid);
    }

    public function testDeriveTokenAccounts(): void
    {
        // Upstream get_default_token_address.rs.
        $owner = SolanaAddress::createWithString('B1iGmDJdvmxyUiYM8UEo2Uw2D58EmUrw4KyLYMmrhf8V');
        self::assertNotNull($owner);
        $mint = 'SRMuApVNdxXokk5GT7XD5cUUgXMBCoAz2LHeuAoKWRt';

        $tokenAddress = $owner->defaultTokenAddress($mint);

        self::assertSame('EDNd1ycsydWYwVmrYZvqYazFqwk1QjBgAUKFjBoz1jKP', $tokenAddress);
    }

    public function testDeriveToken2022Account(): void
    {
        // Upstream solana_address_ffi.rs.
        $owner = SolanaAddress::createWithString('68dzdXkni9BrAwU1asAwurMEdQhXUJq6MNY8niDAny8t');
        self::assertNotNull($owner);
        $mint = '7atgF8KQo4wJrD5ATGX7t1V2zVvykPJbFfNeVf1icFv1';

        $tokenAddress = $owner->token2022Address($mint);

        self::assertSame('3PaFQnebQMHBgthRScup2B932cMxA1GBP7m9roCkomHq', $tokenAddress);
    }

    public function testDerivationSolanaSolana(): void
    {
        $coin = CoinType::Solana;
        $derivation = Derivation::SolanaSolana;
        $path = "m/44'/501'/0'/0'";
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
        self::assertNotSame('2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m', $address);
    }

    public function testSignMessage(): void
    {
        // Wallet Core: solana_message_sign.rs.
        $input = new Proto\MessageSigningInput([
            'private_key' => hex2bin('44f480ca27711895586074a14c552e58cc52e66a58edb6c58cf9b9b7295d4a2d'),
            'message' => 'Hello world',
        ]);

        $data = MessageSigner::sign(CoinType::Solana, $input->serializeToString());
        self::assertNotNull($data);
        $output = new Proto\MessageSigningOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('2iBZ6zrQRKHcbD8NWmm552gU5vGvh1dk3XV4jxnyEdRKm8up8AeQk1GFr9pJokSmchw7i9gMtNyFBdDt8tBxM1cG', $output->getSignature());
    }

    public function testVerifyMessage(): void
    {
        $input = new Proto\MessageVerifyingInput([
            'public_key' => hex2bin('ee6d61a89fc8f9909585a996bb0d2b2ac69ae23b5acf39a19f32631239ba06f9'),
            'message' => 'Hello world',
            'signature' => '2iBZ6zrQRKHcbD8NWmm552gU5vGvh1dk3XV4jxnyEdRKm8up8AeQk1GFr9pJokSmchw7i9gMtNyFBdDt8tBxM1cG',
        ]);

        $verified = MessageSigner::verify(CoinType::Solana, $input->serializeToString());

        self::assertTrue($verified);
    }

    public function testRefreshBlockhashAndSign(): void
    {
        // Wallet Core: solana_transaction_ffi.rs. Transaction is base64; blockhash and keys are base58.
        $encoded = 'AnQTYwZpkm3fs4SdLxnV6gQj3hSLsyacpxDdLMALYWObm722f79IfYFTbZeFK9xHtMumiDOWAM2hHQP4r/GtbARpncaXgOVFv7OgbRLMbuCEJHO1qwcdCbtH72VzyzU8yw9sqqHIAaCUE8xaQTgT6Z5IyZfeyMe2QGJIfOjz65UPAgACBssq8Im1alV3N7wXGODL8jLPWwLhTuCqfGZ1Iz9fb5tXlMOJD6jUvASrKmdtLK/qXNyJns2Vqcvlk+nfJYdZaFpIWiT/tAcEYbttfxyLdYxrLckAKdVRtf1OrNgtZeMCII4SAn6SYaaidrX/AN3s/aVn/zrlEKW0cEUIatHVDKtXO0Qss5EhV/E6kz0BNCgtAytf/s0Botvxt3kGCN8ALqcG3fbh12Whk9nL4UbO63msHLSF7V9bN5E6jPWFfv8Aqe6sdLXiXSDILEtzckCjkjchiSf6zVGpMYiAE5BE2IqHAQUEAgQDAQoMoA8AAAAAAAAG';
        $blockhash = 'CyPYVsYWrsJNfVpi8aazu7WsrswNFuDd385z6GNoBGUg';
        $keys = DataVector::create();
        self::assertNotNull($keys);
        $keys->add(Base58::decodeNoCheck('66ApBuKpo2uSzpjGBraHq7HP8UZMUJzp3um8FdEjkC9c'));
        $keys->add(Base58::decodeNoCheck('9YtuoD4sH4h88CVM8DSnkfoAaLY7YeGC2TarDJ8eyMS5'));

        $data = SolanaTransaction::updateBlockhashAndSign($encoded, $blockhash, $keys);
        self::assertNotNull($data);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('Ajzc/Tke0CG8Cew5qFa6xZI/7Ya3DN0M8Ige6tKPsGzhg8Bw9DqL18KUrEZZ1F4YqZBo4Rv+FsDT8A7Nss7p4A6BNVZzzGprCJqYQeNg0EVIbmPc6mDitNniHXGeKgPZ6QZbM4FElw9O7IOFTpOBPvQFeqy0vZf/aayncL8EK/UEAgACBssq8Im1alV3N7wXGODL8jLPWwLhTuCqfGZ1Iz9fb5tXlMOJD6jUvASrKmdtLK/qXNyJns2Vqcvlk+nfJYdZaFpIWiT/tAcEYbttfxyLdYxrLckAKdVRtf1OrNgtZeMCII4SAn6SYaaidrX/AN3s/aVn/zrlEKW0cEUIatHVDKtXO0Qss5EhV/E6kz0BNCgtAytf/s0Botvxt3kGCN8ALqcG3fbh12Whk9nL4UbO63msHLSF7V9bN5E6jPWFfv8AqbHiki6ThNH3auuyZPQpJntnN0mA//56nMpK/6HIuu8xAQUEAgQDAQoMoA8AAAAAAAAG', $output->getEncoded());
    }

    public function testSignWalletConnectRequest(): void
    {
        // Wallet Core: TWWalletConnectSolana.cpp. Review the request before signing in a real wallet.
        $input = new \TW\WalletConnect\Proto\ParseRequestInput([
            'method' => \TW\WalletConnect\Proto\Method::SolanaSignTransaction,
            'payload' => json_encode([
                'transaction' => 'AQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAEDZsL1CMnFVcrMn7JtiOiN1U4hC7WovOVof2DX51xM0H/GizyJTHgrBanCf8bGbrFNTn0x3pCGq30hKbywSTr6AgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAgIAAQwCAAAAKgAAAAAAAAA=',
            ], JSON_THROW_ON_ERROR),
        ]);
        $parsed = WalletConnectRequest::parse(CoinType::Solana, $input->serializeToString());
        self::assertNotNull($parsed);
        $request = new \TW\WalletConnect\Proto\ParseRequestOutput();
        $request->mergeFromString($parsed);
        self::assertSame(SigningError::OK, $request->getError(), $request->getErrorMessage());
        $transaction = $request->getSolana();
        self::assertNotNull($transaction);
        $transaction->setPrivateKey(Base58::decodeNoCheck('A7psj2GW7ZMdY4E5hJq14KMeYg7HFjULSsWSrTXZLvYr'));
        $transaction->setTxEncoding(Proto\Encoding::Base64);

        $data = AnySigner::sign($transaction->serializeToString(), CoinType::Solana);
        self::assertNotNull($data);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame('AQPWaOi7dMdmQpXi8HyQQKwiqIftrg1igGQxGtZeT50ksn4wAnyH4DtDrkkuE0fqgx80LTp4LwNN9a440SrmoA8BAAEDZsL1CMnFVcrMn7JtiOiN1U4hC7WovOVof2DX51xM0H/GizyJTHgrBanCf8bGbrFNTn0x3pCGq30hKbywSTr6AgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAgIAAQwCAAAAKgAAAAAAAAA=', $output->getEncoded());
    }

    public function testSignTransaction(): void
    {
        // Wallet Core 4.8.7: tests/chains/Solana/TWAnySignerTests.cpp: SignTransfer
        $coin = CoinType::Solana;
        $privateKey = Base58::decodeNoCheck('A7psj2GW7ZMdY4E5hJq14KMeYg7HFjULSsWSrTXZLvYr');
        self::assertNotNull($privateKey);

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'recent_blockhash' => '11111111111111111111111111111111',
            'transfer_transaction' => new Proto\Transfer([
                'recipient' => 'EN2sCsJ1WDV8UFqsiTXHcUPUxQ4juE71eCknHYYMifkd',
                'value' => 42,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), $coin);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '3p2kzZ1DvquqC6LApPuxpTg5CCDVPqJFokGSnGhnBHrta4uq7S2EyehV1XNUVXp51D69GxGzQZU'
            . 'jikfDzbWBG2aFtG3gHT1QfLzyFKHM4HQtMQMNXqay1NAeiiYZjNhx9UvMX4uAQZ4Q6rx6m2AYfQ'
            . '7aoMUrejq298q1wBFdtS9XVB5QTiStnzC7zs97FUEK2T4XapjF1519EyFBViTfHpGpnf5bfizDz'
            . 'sW9kYUtRDW1UC2LgHr7npgq5W9TBmHf9hSmRgM9XXucjXLqubNWE7HUMhbKjuBqkirRM',
            $output->getEncoded(),
        );
    }

    public function testSignTokenTransfer(): void
    {
        // Wallet Core 4.8.7: rust/tw_tests/tests/chains/solana/solana_sign.rs: test_solana_sign_token_transfer
        $privateKey = Base58::decodeNoCheck('9YtuoD4sH4h88CVM8DSnkfoAaLY7YeGC2TarDJ8eyMS5');
        self::assertNotNull($privateKey);

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'recent_blockhash' => 'CNaHfvqePgGYMvtYi9RuUdVxDYttr1zs4TWrTXYabxZi',
            'token_transfer_transaction' => new Proto\TokenTransfer([
                'token_mint_address' => 'SRMuApVNdxXokk5GT7XD5cUUgXMBCoAz2LHeuAoKWRt',
                'sender_token_address' => 'EDNd1ycsydWYwVmrYZvqYazFqwk1QjBgAUKFjBoz1jKP',
                'recipient_token_address' => '3WUX9wASxyScbA7brDipioKfXS1XEYkQ4vo3Kej9bKei',
                'amount' => 4_000,
                'decimals' => 6,
                'token_program_id' => Proto\TokenProgramId::TokenProgram,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Solana);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'PGfKqEaH2zZXDMZLcU6LUKdBSzU1GJWJ1CJXtRYCxaCH7k8uok38WSadZfrZw3TGejiau7nSpan2GvbK26hQim24jRe2AupmcYJFrgsdaCt1Aqs5kpGjPqzgj9krgxTZwwob3xgC1NdHK5BcNwhxwRtrCphGEH7zUFpGFrFrHzgpf2KY8FvPiPELQyxzTBuyNtjLjMMreehSKShEjD9Xzp1QeC1pEF8JL6vUKzxMXuveoEYem8q8JiWszYzmTMfDk13JPgv7pXFGMqDV3yNGCLsWccBeSFKN4UKECre6x2QbUEiKGkHkMc4zQwwyD8tGmEMBAGm339qdANssEMNpDeJp2LxLDStSoWShHnotcrH7pUa94xCVvCPPaomF',
            $output->getEncoded(),
        );
    }

    public function testSignTokenTransfer2022(): void
    {
        // Wallet Core 4.8.7: rust/tw_tests/tests/chains/solana/solana_sign.rs: test_solana_sign_transfer_token_2022
        $privateKey = Base58::decodeNoCheck('MCyXa2gTJELxTPemyVi5ydDcQ3vVgFyddQYXj6UM3tw');
        self::assertNotNull($privateKey);

        $input = new Proto\SigningInput([
            'private_key' => $privateKey,
            'recent_blockhash' => '9U2eTS9b2Essvo1s5hDmwgC1atkSCCUipj2FemLvdWbj',
            'token_transfer_transaction' => new Proto\TokenTransfer([
                'token_mint_address' => 'BSQCmMAFB9itonyVSLsUxX92Ne1rgBZFqothBk3q91k6',
                'sender_token_address' => 'EQxRyhzjyhRX4TJXt7FmQ3HfFdRcu49krjxHMszidQYS',
                'recipient_token_address' => 'FzsLNpzsLMBbm1LWpM6P3W4tKrCkd8KqnMmADNvArW5d',
                'amount' => 1_000_000_000,
                'decimals' => 9,
                'token_program_id' => Proto\TokenProgramId::Token2022Program,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Solana);
        self::assertNotEmpty($signedData);

        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'SAXNFUd7dNBu956Gi4XNuvMkKKjS9vp6puz45ErYMHFpMNwC3AQxDxGbweXt4GzY2FnUZ6ubm231NrdwWa8dg9bqgRMaHPLuPiy99YwtvcQ1E6mHxHqq8nL5VaN8wiVnrMU57zCLfHsSsVCHZc5peHHAPXMDE318uMCLLBwgDWuD1FfAvUAyXRSYniXzWG3jtBdDhuDohh13E2TMrtqTcKVv3crejFqFjtsNuW7KCqrZwxCv1ASNiiL2XScQBdHwStyjH2UTqLmT6wjGLiDYy7PZ88Tbz65r8NLr4Vb1aYSTChasfVjMLdybetfNaf4nJuBE4ZuXca7W66txKbHesxQbzrjUCXX12JFbKyaA8KJKBpbgkc9jWJjQkzyn',
            $output->getEncoded(),
        );
    }

    public function testCreateAndTransferToken(): void
    {
        // Upstream test_solana_sign_create_and_transfer_token. Create the recipient ATA and transfer in one transaction.
        $owner = SolanaAddress::createWithString('71e8mDsh3PR6gN64zL1HjwuxyKpgRXrPDUJT7XXojsVd');
        self::assertNotNull($owner);
        $mint = 'SRMuApVNdxXokk5GT7XD5cUUgXMBCoAz2LHeuAoKWRt';
        $ata = $owner->defaultTokenAddress($mint);
        self::assertSame('EF6L8yJT1SoRoDCkAZfSVmaweqMzfhxZiptKi7Tgj5XY', $ata);
        $input = new Proto\SigningInput([
            'private_key' => Base58::decodeNoCheck('66ApBuKpo2uSzpjGBraHq7HP8UZMUJzp3um8FdEjkC9c'),
            'recent_blockhash' => 'DMmDdJP41M9mw8Z4586VSvxqGCrqPy5uciF6HsKUVDja',
            'create_and_transfer_token_transaction' => new Proto\CreateAndTransferToken([
                'recipient_main_address' => '71e8mDsh3PR6gN64zL1HjwuxyKpgRXrPDUJT7XXojsVd',
                'token_mint_address' => $mint,
                'recipient_token_address' => $ata,
                'sender_token_address' => 'ANVCrmRw7Ww7rTFfMbrjApSPXEEcZpBa6YEiBdf98pAf',
                'amount' => 2_900, // 0.0029 tokens, six decimal places
                'decimals' => 6,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Solana);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '3Y2MVz2VVi7aEyC9q1awwdk1ModDBPHRSacKmTYnSgkmbbJeZ62Fub1bVPSHaTy4LUcQpzCQYhHAKtTKXUDYijEeLsMAUqPBEMAq1w8zCdqDpdXy6M4PuwNtYVV1WgqeiEsiMWpPp4BGWKfcziwFbmYueUGituacJq4wTnt92fho8mFi49XW64gEG4iNGScDtJkY7Geq8PKiLh1E9JMJoceiHxKbmxzCmmLTxEHdhySYHcDUSXnXWogZskeZNBMtR9dNjEMkCzEjrxRpBtJPtUNshciY45mDPNmw4j3xyLCBTRikyfFLc5g11r3UgyVD4YokoPRvrEXsgt6W3yjBshropBm6mY2eJYvfY2eZz4Yq8kLcUatCHVKtjcb1mP9Ww57KisJ9bRhipC8sodFaMYhZARMEa4a1u9eH4MyNUATRGNXarwQSBY46PWS3nKP6QBK7Dw7Ppp9MmYkdPcXKaLScbyLF3jKu6dHWMkHw3WdXSsM1wwXjXnWF9LxdwaEVcDmySWybj6aKD9QCWTU5kdncqJU56f7SYNRTN289WdUFGNDmSh56tj2v1',
            $output->getEncoded(),
        );
    }

    public function testSignTokenTransferWithFeePayer(): void
    {
        // Upstream test_solana_sign_token_transfer_with_external_fee_payer.
        $input = new Proto\SigningInput([
            'private_key' => Base58::decodeNoCheck('9YtuoD4sH4h88CVM8DSnkfoAaLY7YeGC2TarDJ8eyMS5'),
            'fee_payer_private_key' => Base58::decodeNoCheck('66ApBuKpo2uSzpjGBraHq7HP8UZMUJzp3um8FdEjkC9c'),
            'recent_blockhash' => 'H4gZ56AdmHfZj1F36oWrxDJMUJ8ph7XdTHtmsbtHZshG',
            'token_transfer_transaction' => new Proto\TokenTransfer([
                'token_mint_address' => '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
                'sender_token_address' => '5sS5Z8GAdVHqZKRqEvpDauHvvLgbDveiyfi81uh25mrf',
                'recipient_token_address' => 'AZapcpAZtEL1gQuC87F2L1hSfAZnAvNy1hHtJ8DJzySN',
                'amount' => 4_000,
                'decimals' => 6,
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Solana);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'ushDP6dNZWq32FASGqdnw7E8x14zFDAEZBViTyevUptV9yb4WSgpYzEGCxt9sXkrEtHVyww4F4GdcGZbPEaQTjAeyX5KGvHHDhWoPeFNnECzjUTuPj35dpF7zJ75Jx3ADfLQtUyzu5w7812fQvhwBwP8XDm3btqSG4VLWeQU5XuVqpg33Mq1L9zkGHQ8PZ4WkgNuSrC584EVnDcFE4rZsUtAv2jFTMjinQJB1qQEGTCHbjgdtJt8PzmXGXeczNyisPsEDrhZUw3g7RFYsgBDB1RFe1TxspzbWmxwr6CNPkGVsopmS6cbvSG9ejXY8xRYaswP7knAoPXwYk26yetoA824mzdv9vJ2RYpyK72EyCqFfFidm8MrJjFR49KwV3HRQKxZzYcvhYuJhR15GKBUWAQvYJTQQWArTi7pr7m84wNsV1mCUjrYsCVK47QtMAYvWU4toTxfgThngfF47awnpcSxfy8ggbwamq7qcaSH6cQVk1LPGo1iB5YxitSbaXP',
            $output->getEncoded(),
        );
    }

    public function testSignTransferWithMemo(): void
    {
        // Upstream test_solana_sign_transfer_with_memo_and_references (Solana Pay).
        $input = new Proto\SigningInput([
            'private_key' => Base58::decodeNoCheck('AevJ4EWcvQ6dptBDvF2Ri5pU6QSBjkzSGHMfbLFKa746'),
            'recent_blockhash' => '11111111111111111111111111111111',
            'transfer_transaction' => new Proto\Transfer([
                'recipient' => '71e8mDsh3PR6gN64zL1HjwuxyKpgRXrPDUJT7XXojsVd',
                'value' => 10_000_000, // 0.01 SOL (lamports)
                'memo' => 'HelloSolanaMemo',
                'references' => ['CuieVDEDtLo7FypA9SbLM9saXFdb1dsshEkyErMqkRQq', 'tFpP7tZUt6zb7YZPpQ11kXNmsc5YzpMXmahGMvCHhqS'],
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Solana);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'NfNH76sST3nJ4FmFGTZJBUpJou7DRuHM3YNprT1HeEau699CQF65xNf21Hoi491bbtVKUXfqCJyeZhfTCEnABuXNC1JrhGBeCv2AbQdaS9gpp9j4xHHomhCYdwYaBWFMcKkdMXrx9xHqL9Vkny4HezkwQfb3wGqcaE9XVRdkkNxsoJnVKddRnrQbjhsZGTcKdfmbTghoUeRECNPTm6nZTA1owWF1Dq6mfr6M3GZRh4ucqEquxKsQC2HQwNRrGZahsfyUvwspPWwMt78q5Jpjd9kHqkFDspZL6Pepv4dAA4uHhYDCHeP2bbDiFMBYxxWCVDDtRKSh3H92xUgh1GCSgNcjGdbVfQUhSDPX3k9xuuszPTsVZ2GnsavAsRp6Vf6fFEikBX6pVV9zjW1cx94EepQ2aGEBSsVu4RzX7rJjCLCq87h8cxxf1XnF8mvYGEK7wzF',
            $output->getEncoded(),
        );
    }

    public function testCreateTokenAccount(): void
    {
        // Upstream test_solana_sign_create_token_account.
        $input = new Proto\SigningInput([
            'private_key' => Base58::decodeNoCheck('9YtuoD4sH4h88CVM8DSnkfoAaLY7YeGC2TarDJ8eyMS5'),
            'recent_blockhash' => '9ipJh5xfyoyDaiq8trtrdqQeAhQbQkWy2eANizKvx75K',
            'create_token_account_transaction' => new Proto\CreateTokenAccount([
                'main_address' => 'B1iGmDJdvmxyUiYM8UEo2Uw2D58EmUrw4KyLYMmrhf8V',
                'token_mint_address' => 'SRMuApVNdxXokk5GT7XD5cUUgXMBCoAz2LHeuAoKWRt',
                'token_address' => 'EDNd1ycsydWYwVmrYZvqYazFqwk1QjBgAUKFjBoz1jKP',
            ]),
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Solana);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            'CKzRLx3AQeVeLQ7T4hss2rdbUpuAHdbwXDazxtRnSKBuncCk3WnYgy7XTrEiya19MJviYHYdTxi9gmWJY8qnR2vHVnH2DbPiKA8g72rD3VvMnjosGUBBvCwbBLge6FeQdgczMyRo9n5PcHvg9yJBTJaEEvuewyBVHwCGyGQci7eYd26xtZtCjAjwcTq4gGr3NZbeRW6jZp6j6APuew7jys4MKYRV4xPodua1TZFCkyWZr1XKzmPh7KTavtN5VzPDA8rbsvoEjHnKzjB2Bszs6pDjcBFSHyQqGsHoF8XPD35BLfjDghNtBmf9cFqo5axa6oSjANAuYg6cMSP4Hy28waSj8isr6gQjE315hWi3W1swwwPcn322gYZx6aMAcmjczaxX9aktpHYgZxixF7cYWEHxJs5QUK9mJePu9Xc6yW75UB4Ynx6dUgaSTEUzoQthF2TN3xXwu1',
            $output->getEncoded(),
        );
    }

    public function testSignWithPriorityFee(): void
    {
        // Upstream test_solana_sign_transfer_with_priority_fee.
        $input = new Proto\SigningInput([
            'private_key' => hex2bin('1eb5264f82747294e7481ecae700bd1d21e50db620bcf0d2af7582f712c71b40'),
            'recent_blockhash' => 'ABdaGDKfoBe2SJAYkD31sojYVwTM8ainsdBD7ZM9dut1',
            'transfer_transaction' => new Proto\Transfer(['recipient' => 'HwHDGHYeYfXdzwvCZu7zm21SzQ7ZLBGTLNkuscyFdpvZ', 'value' => 1001]),
            'priority_fee_limit' => new Proto\PriorityFeeLimit(['limit' => 2000]), // compute units
            'priority_fee_price' => new Proto\PriorityFeePrice(['price' => 2]), // micro-lamports per compute unit
        ]);

        $signedData = AnySigner::sign($input->serializeToString(), CoinType::Solana);
        self::assertNotEmpty($signedData);
        $output = new Proto\SigningOutput();
        $output->mergeFromString($signedData);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        self::assertSame(
            '4WReC2rmup9nZjtFQbBbha5uADkVb54CRd6a3vbAoptbuzYvdud2Xau2tkzCejZsfsUC2ppqhMqbXXzaoiSpYH8XEDo6QtUV8EGXcE3GFxVjjtBT2U8abtC2qcBMDGAghtzpsmKWfWMxmQj8wWKjpmzamiU6PjfMbuAyAX5VPhQxZQ84Ay2HtQJBK8LA9CJ9qva9ehmu7g41wKBXLPq22NGjeSHq6ubjcNLQrFx34sKGtPJ68Z2oZgrju7XjKFhtnKzqUCWcgVUjUsyHyyBJ3awaExYHEw2uKq9NVoKmf9JDkjf2LBjNpUJ3EGzFHrfwnVEQ7B5XCX4nQRPir7eLuunLwVKhrdATKDbxin3ANe5D',
            $output->getEncoded(),
        );
    }

    public function testDecodeTransaction(): void
    {
        // Wallet Core 4.8.7 solana_transaction.rs: historical signed token transfer with two signatures.
        $encoded = 'AnQTYwZpkm3fs4SdLxnV6gQj3hSLsyacpxDdLMALYWObm722f79IfYFTbZeFK9xHtMumiDOWAM2hHQP4r/GtbARpncaXgOVFv7OgbRLMbuCEJHO1qwcdCbtH72VzyzU8yw9sqqHIAaCUE8xaQTgT6Z5IyZfeyMe2QGJIfOjz65UPAgACBssq8Im1alV3N7wXGODL8jLPWwLhTuCqfGZ1Iz9fb5tXlMOJD6jUvASrKmdtLK/qXNyJns2Vqcvlk+nfJYdZaFpIWiT/tAcEYbttfxyLdYxrLckAKdVRtf1OrNgtZeMCII4SAn6SYaaidrX/AN3s/aVn/zrlEKW0cEUIatHVDKtXO0Qss5EhV/E6kz0BNCgtAytf/s0Botvxt3kGCN8ALqcG3fbh12Whk9nL4UbO63msHLSF7V9bN5E6jPWFfv8Aqe6sdLXiXSDILEtzckCjkjchiSf6zVGpMYiAE5BE2IqHAQUEAgQDAQoMoA8AAAAAAAAG';
        $bytes = Base64::decode($encoded);
        self::assertNotNull($bytes);

        $data = TransactionDecoder::decode(CoinType::Solana, $bytes);
        self::assertNotNull($data);
        $output = new Proto\DecodingTransactionOutput();
        $output->mergeFromString($data);

        self::assertSame(SigningError::OK, $output->getError(), $output->getErrorMessage());
        $transaction = $output->getTransaction();
        self::assertNotNull($transaction);
        self::assertCount(2, $transaction->getSignatures());
        $message = $transaction->getLegacy();
        self::assertNotNull($message);
        self::assertSame('H4gZ56AdmHfZj1F36oWrxDJMUJ8ph7XdTHtmsbtHZshG', $message->getRecentBlockhash());
        self::assertSame('Eg5jqooyG6ySaXKbQUu4Lpvu2SqUPZrNkM4zXs9iUDLJ', $message->getAccountKeys()[0]);
    }

    public static function addressProvider(): array
    {
        return [
            'valid address' => ['2bUBiBNZyD29gP1oV6de7nxowMLoDBtopMMTGgMvjG5m', true],
            'invalid format' => ['not-an-address!', false],
            'empty address' => ['', false],
        ];
    }
}
