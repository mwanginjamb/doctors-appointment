<?php

namespace common\helpers;

use Yii;

/**
 * F21: patients' phone numbers and emails, partly hidden for lists, boards, search results and
 * exports (Kenya Data Protection Act; agreement Schedule A). Staff see them in full only on an
 * appointment they may handle, and that view is audited (AppointmentsController::actionView()).
 *
 * The owner always sees their own in full: pass their user id as $ownerId.
 */
class Contact
{
    /**
     * "0712345678" or "+254 712 345 678" -> "0712***678"; other countries keep their code:
     * "+44 7911 123456" -> "+4479***456". Null for an empty value.
     */
    public static function phone($value, $ownerId = null)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (self::isOwner($ownerId)) {
            return $value;
        }

        $digits = Phone::normalize($value);
        if ($digits !== null && strpos($digits, Phone::DEFAULT_COUNTRY) === 0) {
            $local = '0' . substr($digits, 3);
            return substr($local, 0, 4) . '***' . substr($local, -3);
        }
        $digits = $digits ?? preg_replace('/\D/', '', $value);
        if (strlen($digits) < 7) {
            return '***';
        }

        return ($digits === Phone::normalize($value) ? '+' : '') . substr($digits, 0, 4) . '***' . substr($digits, -3);
    }

    /**
     * "jane.doe@mail.com" -> "j***@mail.com". Null for an empty value.
     */
    public static function email($value, $ownerId = null)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (self::isOwner($ownerId)) {
            return $value;
        }

        $at = strrpos($value, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($value, 0, 1) . '***' . substr($value, $at);
    }

    /** The two, masked and joined for a list cell: "0712***678 · j***@mail.com". */
    public static function summary($phone, $email, $ownerId = null)
    {
        return implode(' · ', array_filter([self::phone($phone, $ownerId), self::email($email, $ownerId)]));
    }

    private static function isOwner($ownerId)
    {
        if (!$ownerId) {
            return false;
        }
        $user = Yii::$app->get('user', false);

        return $user !== null && !$user->isGuest && (int) $user->id === (int) $ownerId;
    }
}
