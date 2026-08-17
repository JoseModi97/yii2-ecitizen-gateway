<?php

namespace odhis\ecitizen;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Builds and signs eCitizen PaymentAPI checkout payloads, and verifies
 * inbound callback/notification signatures against the same secret.
 *
 * Configure as an application component:
 *
 * 'components' => [
 *     'ecitizen' => [
 *         'class' => \odhis\ecitizen\EcitizenGateway::class,
 *         'apiClientID' => '...',
 *         'apiKey' => '...',
 *         'secret' => '...',
 *         'serviceID' => '...',
 *         'url' => 'https://payments.ecitizen.go.ke/PaymentAPI/iframev2.1.php',
 *     ],
 * ],
 */
class EcitizenGateway extends Component
{
    public $apiClientID;
    public $apiKey;
    public $secret;
    public $serviceID;
    public $url;
    public $pictureURL = '';
    public $currency = 'KES';
    public $sendSTK = false;

    /** @var string[] eCitizen statuses treated as a confirmed/successful payment. */
    public $successStatuses = ['paid', 'settled', 'success', 'successful', 'completed', 'complete'];

    public function init()
    {
        parent::init();

        foreach (['apiClientID', 'apiKey', 'secret', 'serviceID', 'url'] as $attribute) {
            if ($this->{$attribute} === null || $this->{$attribute} === '') {
                throw new InvalidConfigException("The eCitizen gateway '{$attribute}' setting is required.");
            }
        }
    }

    /**
     * Builds a signed checkout payload ready to post/auto-submit to the
     * eCitizen PaymentAPI iframe endpoint.
     *
     * Required $invoice keys: amountExpected (or amount), billRefNumber,
     * billDesc, clientName, clientIDNumber.
     * Optional: clientMSISDN, clientEmail, currency, callBackURLOnSuccess,
     * notificationURL, pictureURL, format, sendSTK.
     */
    public function createCheckoutPayload(array $invoice): array
    {
        $amount = $this->normalizeAmount($invoice['amountExpected'] ?? $invoice['amount'] ?? null);
        $billRefNumber = trim((string)($invoice['billRefNumber'] ?? ''));
        $billDesc = trim((string)($invoice['billDesc'] ?? ''));
        $clientName = trim((string)($invoice['clientName'] ?? ''));
        $clientIDNumber = trim((string)($invoice['clientIDNumber'] ?? ''));
        $sendSTK = array_key_exists('sendSTK', $invoice) ? $invoice['sendSTK'] : $this->sendSTK;

        foreach ([
            'amountExpected' => $amount,
            'billRefNumber' => $billRefNumber,
            'billDesc' => $billDesc,
            'clientName' => $clientName,
            'clientIDNumber' => $clientIDNumber,
        ] as $field => $value) {
            if ($value === '') {
                throw new InvalidConfigException("The eCitizen checkout field '{$field}' is required.");
            }
        }

        $payload = [
            'apiClientID' => (string)$this->apiClientID,
            'serviceID' => (string)$this->serviceID,
            'billDesc' => $billDesc,
            'currency' => (string)($invoice['currency'] ?? $this->currency),
            'billRefNumber' => $billRefNumber,
            'clientMSISDN' => trim((string)($invoice['clientMSISDN'] ?? '')),
            'clientName' => $clientName,
            'clientIDNumber' => $clientIDNumber,
            'clientEmail' => trim((string)($invoice['clientEmail'] ?? '')),
            'callBackURLOnSuccess' => (string)($invoice['callBackURLOnSuccess'] ?? ''),
            'amountExpected' => $amount,
            'notificationURL' => (string)($invoice['notificationURL'] ?? ''),
            'pictureURL' => (string)($invoice['pictureURL'] ?? $this->pictureURL),
            'format' => (string)($invoice['format'] ?? 'iframe'),
        ];

        if ($this->isTruthy($sendSTK)) {
            $payload['sendSTK'] = 'true';
        }

        $payload['secureHash'] = $this->generateCheckoutHash($payload);

        return $payload;
    }

    /**
     * Signature order matches the eCitizen PaymentAPI checkout spec. Do not
     * reorder the concatenation without checking the API docs.
     */
    public function generateCheckoutHash(array $payload): string
    {
        $dataString = (string)$this->apiClientID
            . (string)$payload['amountExpected']
            . (string)$this->serviceID
            . (string)$payload['clientIDNumber']
            . (string)$payload['currency']
            . (string)$payload['billRefNumber']
            . (string)$payload['billDesc']
            . (string)$payload['clientName']
            . (string)$this->secret;

        return base64_encode(hash_hmac('sha256', $dataString, (string)$this->apiKey));
    }

    /**
     * Verifies the secure_hash/secureHash on an inbound callback or
     * notification payload against the configured secret.
     */
    public function verifyNotificationHash(array $payload): bool
    {
        $providedHash = (string)($payload['secure_hash'] ?? $payload['secureHash'] ?? '');
        if ($providedHash === '') {
            return false;
        }

        $dataString = (string)($payload['client_invoice_ref'] ?? $payload['billRefNumber'] ?? '')
            . (string)($payload['invoice_number'] ?? '')
            . (string)($payload['amount_paid'] ?? $payload['amount'] ?? '')
            . (string)($payload['payment_date'] ?? '')
            . (string)$this->secret;

        $expectedHash = base64_encode(hash_hmac('sha256', $dataString, (string)$this->apiKey));

        return hash_equals($expectedHash, $providedHash);
    }

    /**
     * True when the payload's status field is one of $successStatuses.
     */
    public function isSuccessStatus($status): bool
    {
        return in_array(strtolower(trim((string)$status)), $this->successStatuses, true);
    }

    public function absoluteUrl(array $route): string
    {
        return Yii::$app->urlManager->createAbsoluteUrl($route);
    }

    private function normalizeAmount($amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return number_format((float)$amount, 2, '.', '');
    }

    private function isTruthy($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
