<?php

namespace odhis\ecitizen\helpers;

/**
 * Normalizes Kenyan MSISDNs to the 2547XXXXXXXX / 2541XXXXXXXX format that
 * eCitizen's sendSTK expects, and validates the result.
 */
class PhoneHelper
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strpos($digits, '254') === 0) {
            return $digits;
        }

        if (strpos($digits, '0') === 0) {
            return '254' . substr($digits, 1);
        }

        if (preg_match('/^[17]\d{8}$/', $digits) === 1) {
            return '254' . $digits;
        }

        return $digits;
    }

    /** Matches Safaricom-format MSISDNs: 2547XXXXXXXX or 2541XXXXXXXX. */
    public static function isValidStkPhone(string $normalizedPhone): bool
    {
        return preg_match('/^254[17]\d{8}$/', $normalizedPhone) === 1;
    }
}
