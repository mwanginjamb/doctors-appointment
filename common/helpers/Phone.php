<?php

namespace common\helpers;

/**
 * Phone numbers in one form, for finding accounts by number and for sending: country code and
 * digits, no "+" or spaces ("0712 345 678", "+254 712 345 678", "254712345678" and "712345678"
 * all become "254712345678").
 *
 * Numbers are kept as typed in user.phone_number (that is what people see); user.phone_key holds
 * this form.
 */
class Phone
{
    /** Kenya: numbers without a country code are taken as Kenyan */
    const DEFAULT_COUNTRY = '254';

    /**
     * @param string|null $raw
     * @return string|null the number as country code and digits, or null when it cannot be read
     */
    public static function normalize($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $international = strpos($raw, '+') === 0 || strpos($raw, '00') === 0;
        $digits = preg_replace('/\D/', '', $raw);
        if (strpos($raw, '00') === 0) {
            $digits = substr($digits, 2);
        }

        if (!$international) {
            if (strlen($digits) === 10 && $digits[0] === '0') {
                // 0712 345 678, 0110 123 456
                $digits = self::DEFAULT_COUNTRY . substr($digits, 1);
            } elseif (strlen($digits) === 9 && ($digits[0] === '7' || $digits[0] === '1')) {
                // 712 345 678
                $digits = self::DEFAULT_COUNTRY . $digits;
            }
        }
        // A Kenyan number is 254 and 9 digits
        if (strpos($digits, self::DEFAULT_COUNTRY) === 0 && strlen($digits) !== 12) {
            return null;
        }
        // ITU: at most 15 digits; anything shorter than 8 is not a phone number
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }

        return $digits;
    }

    /**
     * The number for people to read, partly hidden: "+254 7•• ••• 678".
     */
    public static function mask($normalized)
    {
        $digits = (string) $normalized;
        if (strpos($digits, self::DEFAULT_COUNTRY) === 0 && strlen($digits) === 12) {
            return '+254 ' . $digits[3] . '•• ••• ' . substr($digits, -3);
        }

        return '+' . substr($digits, 0, 3) . ' ••• ' . substr($digits, -3);
    }
}
