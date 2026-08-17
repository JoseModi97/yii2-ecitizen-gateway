<?php

namespace odhis\ecitizen;

use odhis\ecitizen\helpers\PhoneHelper;
use InvalidArgumentException;

/**
 * The easy, beginner-friendly way to use eCitizen payments.
 *
 * Plain PHP — no framework required, no application-component
 * registration, and no interfaces to implement for the basic flow. Works
 * the same whether you're in a Yii2 app, a Yii3 app, or anything else.
 * Create one of these with your eCitizen credentials and use plain-English
 * fields:
 *
 *   $ecitizen = new EcitizenClient([
 *       'apiClientID' => '...',
 *       'apiKey'      => '...',
 *       'secret'      => '...',
 *       'serviceID'   => '...',
 *   ]);
 *
 *   echo $ecitizen->payButton([
 *       'amount'      => 500,
 *       'reference'   => 'INV-0001',
 *       'description' => 'School fees',
 *       'name'        => 'Jane Doe',
 *       'idNumber'    => '12345678',
 *       'phone'       => '0712345678',
 *       'email'       => 'jane@example.com',
 *       'callbackUrl' => 'https://example.com/payment/success', // browser is redirected here after paying
 *       'notifyUrl'   => 'https://example.com/payment/notify',  // eCitizen POSTs the verified result here
 *   ]);
 *
 * When eCitizen calls your notify URL, hand the posted data straight to
 * verify():
 *
 *   $result = $ecitizen->verify($_POST);
 *   if ($result['success']) {
 *       // payment confirmed — do whatever you like with $result
 *   }
 *
 * If something is missing or wrong, every method here throws
 * InvalidArgumentException with a plain-English message telling you
 * exactly what to fix — there's no silent failure and nothing mysterious.
 */
class EcitizenClient
{
    /** Friendly field name => the underlying eCitizen field it maps to. */
    private const FIELD_MAP = [
        'amount' => 'amountExpected',
        'reference' => 'billRefNumber',
        'description' => 'billDesc',
        'name' => 'clientName',
        'idNumber' => 'clientIDNumber',
        'phone' => 'clientMSISDN',
        'email' => 'clientEmail',
        'currency' => 'currency',
        // eCitizen actually gives you two different URLs — see the class
        // docblock and README "URLs explained" section for which is which.
        'callbackUrl' => 'callBackURLOnSuccess', // browser redirect after payment (not verified on its own)
        'successUrl' => 'callBackURLOnSuccess',  // alias of callbackUrl, kept for backwards compatibility
        'notifyUrl' => 'notificationURL',        // server-to-server notification (verify() this one)
        'sendStkPush' => 'sendSTK',
    ];

    /** Friendly labels used in error messages, so beginners aren't shown eCitizen's raw field names. */
    private const FIELD_LABELS = [
        'amount' => 'amount (how much to charge, e.g. 500)',
        'reference' => "reference (a unique ID for this payment, e.g. 'INV-0001')",
        'description' => "description (what the payment is for, e.g. 'School fees')",
        'name' => "name (the payer's full name)",
        'idNumber' => "idNumber (the payer's National ID or Passport number)",
    ];

    private EcitizenGateway $gateway;

    /**
     * @param array $settings apiClientID, apiKey, secret, serviceID are
     *     required. url and currency default to eCitizen's live endpoint
     *     and KES if not supplied.
     */
    public function __construct(array $settings)
    {
        $this->assertRequiredSettings($settings);

        $settings['url'] = trim((string)($settings['url'] ?? '')) !== ''
            ? $settings['url']
            : 'https://payments.ecitizen.go.ke/PaymentAPI/iframev2.1.php';
        $settings['currency'] = trim((string)($settings['currency'] ?? '')) !== ''
            ? $settings['currency']
            : 'KES';

        $this->gateway = new EcitizenGateway($settings);
    }

    /**
     * Builds and signs a checkout payload. Returns ['url' => ..., 'payload' => [...]]
     * — post $payload as a form to $url (or use payButton() to skip this step).
     *
     * Accepts plain-English keys: amount, reference, description, name,
     * idNumber, phone, email, currency, callbackUrl, notifyUrl, sendStkPush.
     */
    public function checkout(array $payment): array
    {
        $this->assertRequiredPaymentFields($payment);

        $invoice = $this->translateFields($payment);
        if ($invoice['clientMSISDN'] !== '') {
            $invoice['clientMSISDN'] = PhoneHelper::normalize($invoice['clientMSISDN']);
        }

        // EcitizenGateway throws the same InvalidArgumentException type,
        // so nothing to translate here — this should also be unreachable
        // in practice since assertRequiredPaymentFields() already checked.
        $payload = $this->gateway->createCheckoutPayload($invoice);

        return [
            'url' => $this->gateway->url,
            'payload' => $payload,
        ];
    }

    /**
     * Returns a ready-to-render HTML form with a submit button that sends
     * the payer to eCitizen to pay. Just echo the result in your view —
     * no view file, no JavaScript, no iframe wiring required.
     */
    public function payButton(array $payment, string $buttonLabel = 'Pay with eCitizen', array $buttonOptions = []): string
    {
        $checkout = $this->checkout($payment);

        $buttonOptions = array_merge(['class' => 'btn btn-primary'], $buttonOptions);

        // Built with plain PHP rather than a framework's HTML helper: this
        // keeps the library dependency-free (usable in Yii2, Yii3, or any
        // other PHP project), and the form posts to eCitizen's external
        // domain anyway, so a framework CSRF token has no place in it.
        $html = '<form action="' . $this->escapeAttribute($checkout['url']) . '" method="post" target="_blank">';
        foreach ($checkout['payload'] as $field => $value) {
            $html .= '<input type="hidden" name="' . $this->escapeAttribute($field) . '" value="' . $this->escapeAttribute((string)$value) . '">';
        }
        $html .= '<button type="submit"' . $this->renderAttributes($buttonOptions) . '>' . $this->escapeText($buttonLabel) . '</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Checks a callback/notification payload from eCitizen (just pass it
     * $_POST, or Yii's Yii::$app->request->bodyParams). Returns a plain
     * array; this method itself never writes to a database — what you do
     * with the result, including whether/how you record it, is up to you
     * (see the README's "Storing payment records" section for a worked
     * example if you do want to persist it).
     *
     * Return shape:
     *   [
     *       'success'        => bool,   // true only if signature AND status both check out
     *       'signatureValid' => bool,
     *       'status'         => string, // raw status eCitizen sent, e.g. 'Settled'
     *       'reference'      => string, // the billRefNumber/reference you set at checkout
     *       'amountPaid'     => float,
     *       'raw'            => array,  // the full payload, in case you need something else
     *   ]
     */
    public function verify(array $callbackData): array
    {
        $status = (string)($callbackData['status'] ?? '');
        $signatureValid = $this->gateway->verifyNotificationHash($callbackData);

        return [
            'success' => $signatureValid && $this->gateway->isSuccessStatus($status),
            'signatureValid' => $signatureValid,
            'status' => $status,
            'reference' => trim((string)($callbackData['client_invoice_ref'] ?? $callbackData['billRefNumber'] ?? '')),
            'amountPaid' => (float)($callbackData['amount_paid'] ?? $callbackData['amount'] ?? 0),
            'raw' => $callbackData,
        ];
    }

    /** Shorthand for verify($callbackData)['success']. */
    public function isPaid(array $callbackData): bool
    {
        return $this->verify($callbackData)['success'];
    }

    /**
     * Escape hatch for advanced use (e.g. EcitizenPaymentTrait, or calling
     * gateway methods directly). Most users won't need this.
     */
    public function getGateway(): EcitizenGateway
    {
        return $this->gateway;
    }

    private function assertRequiredSettings(array $settings): void
    {
        $required = [
            'apiClientID' => 'your eCitizen API Client ID',
            'apiKey' => 'your eCitizen API Key',
            'secret' => 'your eCitizen secret',
            'serviceID' => 'your eCitizen Service ID',
        ];

        $missing = [];
        foreach ($required as $key => $label) {
            if (trim((string)($settings[$key] ?? '')) === '') {
                $missing[] = "'{$key}' ({$label})";
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "Cannot set up eCitizen: missing " . implode(', ', $missing) . ". "
                . "Get these from your eCitizen merchant account, then create the client like:\n"
                . "new EcitizenClient(['apiClientID' => '...', 'apiKey' => '...', 'secret' => '...', 'serviceID' => '...'])"
            );
        }
    }

    private function assertRequiredPaymentFields(array $payment): void
    {
        $missing = [];
        foreach (self::FIELD_LABELS as $key => $label) {
            $mapped = self::FIELD_MAP[$key];
            $value = $payment[$key] ?? $payment[$mapped] ?? null;
            if (trim((string)$value) === '') {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "Cannot start this payment — missing: " . implode('; ', $missing) . ". "
                . "Example: \$ecitizen->payButton(['amount' => 500, 'reference' => 'INV-0001', "
                . "'description' => 'School fees', 'name' => 'Jane Doe', 'idNumber' => '12345678'])"
            );
        }
    }

    private function translateFields(array $payment): array
    {
        $invoice = [];
        foreach (self::FIELD_MAP as $friendly => $mapped) {
            if (array_key_exists($friendly, $payment)) {
                $invoice[$mapped] = $payment[$friendly];
            } elseif (array_key_exists($mapped, $payment)) {
                $invoice[$mapped] = $payment[$mapped];
            }
        }

        // Pass through anything the caller already supplied using the raw
        // eCitizen field names (billDesc, clientMSISDN, etc.) that isn't
        // covered above, e.g. 'format' or 'pictureURL'.
        foreach (['format', 'pictureURL'] as $passthroughField) {
            if (array_key_exists($passthroughField, $payment)) {
                $invoice[$passthroughField] = $payment[$passthroughField];
            }
        }

        $invoice['clientMSISDN'] = (string)($invoice['clientMSISDN'] ?? '');
        $invoice['clientEmail'] = (string)($invoice['clientEmail'] ?? '');

        return $invoice;
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function renderAttributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $html .= ' ' . $this->escapeAttribute((string)$name) . '="' . $this->escapeAttribute($value === true ? (string)$name : (string)$value) . '"';
        }

        return $html;
    }
}
