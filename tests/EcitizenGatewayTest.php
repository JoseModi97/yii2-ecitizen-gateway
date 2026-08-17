<?php

namespace odhis\ecitizen\tests;

use odhis\ecitizen\EcitizenGateway;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;

class EcitizenGatewayTest extends TestCase
{
    private function makeGateway(array $overrides = []): EcitizenGateway
    {
        return new EcitizenGateway(array_merge([
            'apiClientID' => 'CLIENT1',
            'apiKey' => 'KEY1',
            'secret' => 'SECRET1',
            'serviceID' => 'SERVICE1',
            'url' => 'https://payments.ecitizen.go.ke/PaymentAPI/iframev2.1.php',
        ], $overrides));
    }

    public function testMissingSettingThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        new EcitizenGateway(['apiClientID' => 'CLIENT1']);
    }

    public function testCreateCheckoutPayloadIncludesSecureHash(): void
    {
        $gateway = $this->makeGateway();

        $payload = $gateway->createCheckoutPayload([
            'amountExpected' => 500,
            'billRefNumber' => 'INV-0001',
            'billDesc' => 'School fees',
            'clientName' => 'Jane Doe',
            'clientIDNumber' => '12345678',
        ]);

        $this->assertSame('CLIENT1', $payload['apiClientID']);
        $this->assertSame('SERVICE1', $payload['serviceID']);
        $this->assertSame('500.00', $payload['amountExpected']);
        $this->assertSame('KES', $payload['currency']);
        $this->assertNotEmpty($payload['secureHash']);
    }

    public function testCreateCheckoutPayloadRejectsMissingField(): void
    {
        $gateway = $this->makeGateway();

        $this->expectException(InvalidConfigException::class);
        $gateway->createCheckoutPayload([
            'amountExpected' => 500,
            'billRefNumber' => 'INV-0001',
            // billDesc missing
            'clientName' => 'Jane Doe',
            'clientIDNumber' => '12345678',
        ]);
    }

    public function testVerifyNotificationHashRoundTrips(): void
    {
        $gateway = $this->makeGateway();

        $dataString = 'INV-0001' . '' . '500.00' . '2026-08-17' . 'SECRET1';
        $hash = base64_encode(hash_hmac('sha256', $dataString, 'KEY1'));

        $payload = [
            'client_invoice_ref' => 'INV-0001',
            'amount_paid' => '500.00',
            'payment_date' => '2026-08-17',
            'status' => 'Settled',
            'secure_hash' => $hash,
        ];

        $this->assertTrue($gateway->verifyNotificationHash($payload));
        $this->assertTrue($gateway->isSuccessStatus($payload['status']));
    }

    public function testVerifyNotificationHashRejectsTamperedPayload(): void
    {
        $gateway = $this->makeGateway();

        $payload = [
            'client_invoice_ref' => 'INV-0001',
            'amount_paid' => '500.00',
            'payment_date' => '2026-08-17',
            'status' => 'Settled',
            'secure_hash' => 'not-a-real-hash',
        ];

        $this->assertFalse($gateway->verifyNotificationHash($payload));
    }

    public function testVerifyNotificationHashRejectsMissingHash(): void
    {
        $gateway = $this->makeGateway();

        $this->assertFalse($gateway->verifyNotificationHash(['status' => 'Settled']));
    }
}
