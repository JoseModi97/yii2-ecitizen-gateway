<?php

namespace odhis\ecitizen\controllers;

use odhis\ecitizen\EcitizenGateway;
use odhis\ecitizen\interfaces\EcitizenInvoiceInterface;
use Yii;

/**
 * Mix this into a controller to get generic eCitizen callback/notification
 * verification, without depending on your invoice model's schema.
 *
 * Usage:
 *
 *   class PaymentController extends Controller
 *   {
 *       use EcitizenPaymentTrait;
 *
 *       public function actionEcitizenNotify()
 *       {
 *           Yii::$app->response->format = Response::FORMAT_JSON;
 *           return $this->handleEcitizenNotification(
 *               Yii::$app->request->bodyParams + Yii::$app->request->get(),
 *               fn (string $billRef, int $paymentId): ?EcitizenInvoiceInterface =>
 *                   Ecitizen::findByBillRefOrId($billRef, $paymentId)
 *           );
 *       }
 *   }
 *
 * The consuming app is still responsible for: looking up its own invoice
 * record (the $findInvoice callback), CSRF-exempting the callback/notify
 * routes, and rate-limiting invoice creation/launch.
 */
trait EcitizenPaymentTrait
{
    protected function getEcitizenGateway(): EcitizenGateway
    {
        return Yii::$app->get('ecitizen');
    }

    /**
     * True when the payload plausibly carries payment-confirmation data
     * (as opposed to an empty ping or an unrelated request to the route).
     */
    protected function hasEcitizenConfirmationPayload(array $payload): bool
    {
        $hash = (string)($payload['secure_hash'] ?? $payload['secureHash'] ?? '');
        $status = (string)($payload['status'] ?? '');

        return $hash !== '' && $status !== '';
    }

    protected function getEcitizenBillRefNumber(array $payload): string
    {
        return trim((string)($payload['client_invoice_ref'] ?? $payload['billRefNumber'] ?? ''));
    }

    protected function getEcitizenPaymentId(array $payload): int
    {
        return (int)($payload['payment_id'] ?? 0);
    }

    /**
     * Verifies signature, status, invoice match, and amount/currency match,
     * then marks the invoice settled via EcitizenInvoiceInterface. Returns
     * a ['status' => 'ok'|'error', ...] array suitable for a JSON response.
     *
     * @param callable $findInvoice function(string $billRef, int $paymentId): ?EcitizenInvoiceInterface
     */
    protected function handleEcitizenNotification(array $payload, callable $findInvoice): array
    {
        if (!$this->hasEcitizenConfirmationPayload($payload)) {
            return ['status' => 'error', 'description' => 'Missing payment confirmation fields'];
        }

        $billRefNumber = $this->getEcitizenBillRefNumber($payload);
        $paymentId = $this->getEcitizenPaymentId($payload);
        if ($billRefNumber === '' && $paymentId <= 0) {
            return ['status' => 'error', 'description' => 'Missing invoice identifier'];
        }

        $gateway = $this->getEcitizenGateway();
        if (!$gateway->verifyNotificationHash($payload)) {
            Yii::warning('eCitizen notification failed signature verification.', 'ecitizen.payment');

            return ['status' => 'error', 'description' => 'Invalid signature'];
        }

        $status = (string)($payload['status'] ?? '');
        if (!$gateway->isSuccessStatus($status)) {
            return ['status' => 'error', 'description' => "Unrecognized status: {$status}"];
        }

        $invoice = $findInvoice($billRefNumber, $paymentId);
        if (!$invoice instanceof EcitizenInvoiceInterface) {
            return ['status' => 'error', 'description' => 'Invoice not found for update'];
        }

        $paidAmount = (float)($payload['amount_paid'] ?? $payload['amount'] ?? 0);
        if (number_format($paidAmount, 2, '.', '') !== number_format($invoice->getAmountExpected(), 2, '.', '')) {
            Yii::warning("eCitizen notification amount mismatch for {$billRefNumber}: expected {$invoice->getAmountExpected()}, got {$paidAmount}", 'ecitizen.payment');

            return ['status' => 'error', 'description' => 'Amount mismatch'];
        }

        $payloadCurrency = (string)($payload['currency'] ?? '');
        if ($payloadCurrency !== '' && strcasecmp($payloadCurrency, $invoice->getCurrency()) !== 0) {
            Yii::warning("eCitizen notification currency mismatch for {$billRefNumber}: expected {$invoice->getCurrency()}, got {$payloadCurrency}", 'ecitizen.payment');

            return ['status' => 'error', 'description' => 'Currency mismatch'];
        }

        $invoice->setGatewayResponse($payload + ['source' => 'ecitizen_notify', 'confirmed_at' => gmdate('c')]);
        $invoice->markStatus('Settled');

        return ['status' => 'ok'];
    }
}
