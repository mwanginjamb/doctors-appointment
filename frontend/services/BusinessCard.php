<?php

namespace frontend\services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use common\models\User;
use frontend\models\Consultant;
use frontend\models\Facility;
use frontend\models\FrontDeskAssignment;

/**
 * F29: a consultant's business card. Side A: their name, speciality, KMPDC number, practice name
 * and up to three of their facilities (no personal phone or email); side B: a QR code for their
 * public profile (/dr/<id>-<name>), where patients see where they work and book.
 *
 * The consultant and the front desk assigned to them open it on /business-card; platform admins
 * in /admin. The consultant (and platform admins) choose the facilities on it.
 */
class BusinessCard
{
    const MAX_FACILITIES = 3;

    /**
     * Active facilities the consultant works at, in the order of their engagements.
     *
     * @return Facility[] keyed by id
     */
    public static function activeFacilities(Consultant $consultant)
    {
        $facilities = [];
        foreach ($consultant->engagements as $engagement) {
            $facility = $engagement->facility;
            if ($facility !== null && $facility->isActive()) {
                $facilities[(int) $facility->id] = $facility;
            }
        }

        return $facilities;
    }

    /** @return int[]|null the facility ids chosen for the card, or null when none were chosen */
    public static function chosenIds(Consultant $consultant)
    {
        if ($consultant->card_facility_ids === null) {
            return null;
        }

        return array_values(array_filter(array_map('intval', explode(',', $consultant->card_facility_ids))));
    }

    /**
     * The facilities on the card: the chosen ones still active, or the first three when none were chosen.
     *
     * @return Facility[]
     */
    public static function facilities(Consultant $consultant)
    {
        $active = self::activeFacilities($consultant);
        $chosen = self::chosenIds($consultant);
        if ($chosen === null) {
            return array_slice(array_values($active), 0, self::MAX_FACILITIES);
        }
        $list = [];
        foreach ($chosen as $id) {
            if (isset($active[$id])) {
                $list[] = $active[$id];
            }
        }

        return array_slice($list, 0, self::MAX_FACILITIES);
    }

    /**
     * Keeps the facilities to show: only ones the consultant works at, at most three.
     *
     * @param int[] $ids in the order to show them
     * @return string|null why not, or null when saved
     */
    public static function chooseFacilities(Consultant $consultant, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (count($ids) > self::MAX_FACILITIES) {
            return 'Choose up to ' . self::MAX_FACILITIES . ' facilities.';
        }
        $active = self::activeFacilities($consultant);
        $ids = array_values(array_filter($ids, function ($id) use ($active) { return isset($active[$id]); }));
        $consultant->updateAttributes(['card_facility_ids' => implode(',', $ids)]);

        return null;
    }

    /**
     * Whether $user may open the consultant's card: the consultant, or front desk assigned to them.
     * (Platform admins open it in /admin.)
     */
    public static function canView(Consultant $consultant, $user)
    {
        if (!$user instanceof User) {
            return false;
        }
        if ((int) $consultant->user_id === (int) $user->id) {
            return true;
        }

        return $user->isFrontDeskAccount()
            && FrontDeskAssignment::find()->where(['user_id' => $user->id, 'consultant_id' => $consultant->id])->exists();
    }

    /** Only the consultant chooses what is on it (platform admins in /admin). */
    public static function canEdit(Consultant $consultant, $user)
    {
        return $user instanceof User && (int) $consultant->user_id === (int) $user->id;
    }

    /** Consultants whose card $user may open, by name (a front-desk user's assigned consultants). */
    public static function consultantsFor($user)
    {
        if (!$user instanceof User) {
            return [];
        }
        if ($user->isConsultantAccount()) {
            return Consultant::find()->where(['user_id' => $user->id])->all();
        }
        if ($user->isFrontDeskAccount()) {
            return Consultant::find()->where(['id' => FrontDeskAssignment::consultantIdsFor($user->id)])->orderBy(['names' => SORT_ASC])->all();
        }

        return [];
    }

    /**
     * The QR code for $url, as SVG markup (sharp at any print size) or PNG bytes.
     *
     * @param string $format svg or png
     */
    public static function qr($url, $format = 'svg')
    {
        $png = $format === 'png';
        $options = new QROptions([
            'outputType' => $png ? QROutputInterface::GDIMAGE_PNG : QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            // The PNG stands alone (posters, messages): a white square with the standard margin.
            // The SVG sits on the card's own white panel, which gives it its margin.
            'quietzoneSize' => $png ? 4 : 1,
            'scale' => 12,
            'drawLightModules' => $png,
            'svgAddXmlHeader' => false,
            'connectPaths' => true,
        ]);

        return (new QRCode($options))->render($url);
    }

    /** "Cardiology · Interventional cardiology": the speciality and sub-specialities, for the card. */
    public static function specialityLine(Consultant $consultant)
    {
        $parts = array_filter([trim((string) $consultant->speciality), trim((string) $consultant->sub_speciality)]);

        return implode(' · ', $parts);
    }
}
