<?php

use frontend\services\FeeRules;
use yii\bootstrap5\Html;

/**
 * F22: the commitment-fee questions on a booking form: cash or insurance, the phone that pays, and
 * the booking terms. Shown only where FeeRules::mayCharge() for the consultant; the booking itself
 * decides whether the fee applies (a same-day walk-in, or a second booking that day, pays nothing).
 * Posted as payment_mode, pay_phone and fee_terms; read with FeeRules::load().
 *
 * @var yii\web\View $this
 * @var bool $forStaff staff book for the patient: the phone defaults to the patient's
 * @var string|null $phone the phone to suggest
 * @var string $idPrefix for the inputs' ids
 */

$forStaff = !empty($forStaff);
$idPrefix = $idPrefix ?? 'fee';
$amount = number_format(FeeRules::amount());
?>
<fieldset class="border rounded p-3 mb-3 fee-fields" id="<?= $idPrefix ?>-fields">
    <legend class="fs-6 fw-semibold float-none w-auto px-1 mb-1"><?= Yii::t('app', 'Commitment fee: Ksh {amount}', ['amount' => $amount]) ?></legend>
    <p class="small text-muted mb-2">
        <?= $forStaff
            ? Yii::t('app', 'A booking ahead is confirmed once the patient pays Ksh {amount} by M-Pesa. The patient gets the prompt on their phone; the time is held for 10 minutes.', ['amount' => $amount])
            : Yii::t('app', 'Your booking is confirmed once you pay Ksh {amount} by M-Pesa. You get the prompt on your phone; the time is held for you for 10 minutes.', ['amount' => $amount]) ?>
    </p>

    <div class="mb-2">
        <span class="form-label d-block small fw-semibold"><?= $forStaff ? Yii::t('app', 'The patient pays for the visit by') : Yii::t('app', 'I pay for the visit by') ?></span>
        <?php foreach (FeeRules::paymentModeLabels() as $value => $label): ?>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="payment_mode" id="<?= $idPrefix ?>-mode-<?= $value ?>" value="<?= $value ?>" <?= $value === FeeRules::PAYMENT_CASH ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= $idPrefix ?>-mode-<?= $value ?>"><?= Html::encode($label) ?></label>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mb-2">
        <label class="form-label small fw-semibold" for="<?= $idPrefix ?>-phone"><?= Yii::t('app', 'M-Pesa number that pays') ?></label>
        <input type="tel" class="form-control form-control-sm" name="pay_phone" id="<?= $idPrefix ?>-phone" maxlength="20"
            value="<?= Html::encode($phone ?? '') ?>"
            placeholder="<?= $forStaff ? Yii::t('app', "Leave empty for the patient's own number") : Yii::t('app', 'e.g. 0712 345 678') ?>">
    </div>

    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="fee_terms" value="1" id="<?= $idPrefix ?>-terms">
        <label class="form-check-label small" for="<?= $idPrefix ?>-terms">
            <?= $forStaff
                ? Yii::t('app', 'The patient accepts the booking terms: the fee is kept on a no-show or a cancellation less than 24 hours ahead, moves with a reschedule more than 24 hours ahead, is credited if the clinic cancels, and is paid at most once a day.')
                : Yii::t('app', 'I accept the booking terms: the fee is kept if I do not come or cancel less than 24 hours ahead, moves with my booking if I reschedule more than 24 hours ahead, is credited if the clinic cancels, and is paid at most once a day.') ?>
        </label>
    </div>
</fieldset>
