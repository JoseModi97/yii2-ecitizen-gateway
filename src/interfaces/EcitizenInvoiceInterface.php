<?php

namespace odhis\ecitizen\interfaces;

/**
 * Implement this on whatever ActiveRecord model stores your eCitizen
 * invoices/payment records so EcitizenPaymentTrait can operate on it
 * without knowing your schema.
 */
interface EcitizenInvoiceInterface
{
    public function getBillRefNumber(): string;

    public function getAmountExpected(): float;

    public function getCurrency(): string;

    public function getStatus(): string;

    /** Persist the record with the given status (e.g. 'Pending', 'Settled'). */
    public function markStatus(string $status): bool;

    /** Persist arbitrary raw gateway response data for auditing. */
    public function setGatewayResponse(array $response): void;
}
