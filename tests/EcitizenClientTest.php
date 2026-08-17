<?php

namespace odhis\ecitizen\tests;

use InvalidArgumentException;
use odhis\ecitizen\EcitizenClient;
use PHPUnit\Framework\TestCase;

class EcitizenClientTest extends TestCase
{
    private const CREDENTIALS = [
        'apiClientID' => 'CLIENT1',
        'apiKey' => 'KEY1',
        'secret' => 'SECRET1',
        'serviceID' => 'SERVICE1',
    ];

    public function testMissingCredentialGivesFriendlyMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/missing.*apiKey/");

        new EcitizenClient([
            'apiClientID' => 'CLIENT1',
            'secret' => 'SECRET1',
            'serviceID' => 'SERVICE1',
        ]);
    }

    public function testDefaultsUrlAndCurrencyWhenOmitted(): void
    {
        $client = new EcitizenClient(self::CREDENTIALS);

        $this->assertSame(
            'https://payments.ecitizen.go.ke/PaymentAPI/iframev2.1.php',
            $client->getGateway()->url
        );
        $this->assertSame('KES', $client->getGateway()->currency);
    }

    public function testCheckoutWithFriendlyFieldNames(): void
    {
        $client = new EcitizenClient(self::CREDENTIALS);

        $result = $client->checkout([
            'amount' => 500,
            'reference' => 'INV-0001',
            'description' => 'School fees',
            'name' => 'Jane Doe',
            'idNumber' => '12345678',
            'phone' => '0712345678',
        ]);

        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('payload', $result);
        $this->assertSame('INV-0001', $result['payload']['billRefNumber']);
        $this->assertSame('254712345678', $result['payload']['clientMSISDN']);
        $this->assertNotEmpty($result['payload']['secureHash']);
    }

    public function testCallbackUrlAliasMapsToCallBackURLOnSuccess(): void
    {
        $client = new EcitizenClient(self::CREDENTIALS);

        $result = $client->checkout([
            'amount' => 500,
            'reference' => 'INV-0001',
            'description' => 'School fees',
            'name' => 'Jane Doe',
            'idNumber' => '12345678',
            'callbackUrl' => 'https://example.com/payment/success',
            'notifyUrl' => 'https://example.com/payment/notify',
        ]);

        $this->assertSame('https://example.com/payment/success', $result['payload']['callBackURLOnSuccess']);
        $this->assertSame('https://example.com/payment/notify', $result['payload']['notificationURL']);
    }

    public function testCheckoutMissingFieldGivesFriendlyMessage(): void
    {
        $client = new EcitizenClient(self::CREDENTIALS);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing: amount/');

        $client->checkout([
            'reference' => 'INV-0001',
            'description' => 'School fees',
            'name' => 'Jane Doe',
            'idNumber' => '12345678',
        ]);
    }

    public function testPayButtonRendersFormWithHiddenFields(): void
    {
        $client = new EcitizenClient(self::CREDENTIALS);

        $html = $client->payButton([
            'amount' => 500,
            'reference' => 'INV-0001',
            'description' => 'School fees',
            'name' => 'Jane Doe',
            'idNumber' => '12345678',
        ]);

        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('name="billRefNumber"', $html);
        $this->assertStringContainsString('Pay with eCitizen', $html);
    }

    public function testVerifyReturnsSuccessForValidSignedPayload(): void
    {
        $client = new EcitizenClient(self::CREDENTIALS);

        $dataString = 'INV-0001' . '' . '500.00' . '2026-08-17' . 'SECRET1';
        $hash = base64_encode(hash_hmac('sha256', $dataString, 'KEY1'));

        $result = $client->verify([
            'client_invoice_ref' => 'INV-0001',
            'amount_paid' => '500.00',
            'payment_date' => '2026-08-17',
            'status' => 'Settled',
            'secure_hash' => $hash,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['signatureValid']);
        $this->assertSame('INV-0001', $result['reference']);
        $this->assertSame(500.0, $result['amountPaid']);
    }

    public function testVerifyReturnsFailureForForgedPayload(): void
    {
        $client = new EcitizenClient(self::CREDENTIALS);

        $result = $client->verify([
            'client_invoice_ref' => 'INV-0001',
            'amount_paid' => '500.00',
            'status' => 'Settled',
            'secure_hash' => 'forged',
        ]);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['signatureValid']);
        $this->assertFalse($client->isPaid([
            'client_invoice_ref' => 'INV-0001',
            'secure_hash' => 'forged',
        ]));
    }
}
