<?php

use frontend\services\FeeRules;
use frontend\services\Insurance;
use yii\bootstrap5\Html;

/**
 * F18: cash or insurance on every booking form, and for insurance the insurer and member number
 * (filled in from the patient's last insured booking). An insurer the consultant does not list is
 * warned about here, not refused. Posted as payment_mode, insurer_id and member_number; read with
 * FeeRules::load(). The commitment-fee questions (_fee_fields) follow when a fee may apply.
 *
 * @var yii\web\View $this
 * @var bool $forStaff staff book for the patient
 * @var frontend\models\Consultant|null $consultant whose listed insurers the choice is checked against
 * @var int|null $insurerId to suggest
 * @var string|null $memberNumber to suggest
 * @var string $idPrefix for the inputs' ids
 */

$forStaff = !empty($forStaff);
$idPrefix = $idPrefix ?? 'pay';
$accepted = Insurance::acceptedIds($consultant ?? null);
$doctor = $consultant->names ?? Yii::t('app', 'The doctor');
?>
<fieldset class="border rounded p-3 mb-3 payment-fields" id="<?= $idPrefix ?>-pay-fields"
    data-accepted="<?= Html::encode(json_encode($accepted)) ?>" data-doctor="<?= Html::encode($doctor) ?>">
    <legend class="fs-6 fw-semibold float-none w-auto px-1 mb-1"><?= $forStaff ? Yii::t('app', 'The patient pays by') : Yii::t('app', 'I pay for the visit by') ?></legend>
    <?php foreach (FeeRules::paymentModeLabels() as $value => $label): ?>
        <div class="form-check form-check-inline">
            <input class="form-check-input pay-mode" type="radio" name="payment_mode" id="<?= $idPrefix ?>-mode-<?= $value ?>" value="<?= $value ?>"
                <?= $value === ($insurerId ? FeeRules::PAYMENT_INSURANCE : FeeRules::PAYMENT_CASH) ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= $idPrefix ?>-mode-<?= $value ?>"><?= Html::encode($label) ?></label>
        </div>
    <?php endforeach; ?>
    <div class="row g-2 mt-1 insurer-fields <?= $insurerId ? '' : 'd-none' ?>">
        <div class="col-sm-6">
            <label class="form-label small fw-semibold mb-1" for="<?= $idPrefix ?>-insurer"><?= Yii::t('app', 'Insurer') ?></label>
            <?= Html::dropDownList('insurer_id', $insurerId, Insurance::insurers(), [
                'class' => 'form-select form-select-sm insurer-select', 'id' => $idPrefix . '-insurer', 'prompt' => Yii::t('app', 'Choose…'),
            ]) ?>
        </div>
        <div class="col-sm-6">
            <label class="form-label small fw-semibold mb-1" for="<?= $idPrefix ?>-member"><?= Yii::t('app', 'Member number') ?></label>
            <input type="text" class="form-control form-control-sm member-number" name="member_number" id="<?= $idPrefix ?>-member" maxlength="40"
                value="<?= Html::encode($memberNumber ?? '') ?>" autocomplete="off">
        </div>
        <div class="col-12">
            <div class="small text-warning-emphasis d-none insurer-warning" id="<?= $idPrefix ?>-insurer-warning"></div>
            <div class="form-text"><?= $forStaff
                ? Yii::t('app', 'Pre-authorisation is recorded on the appointment once the insurer answers.')
                : Yii::t('app', 'The clinic asks your insurer to approve the visit before you come.') ?></div>
        </div>
    </div>
</fieldset>
<?php
$this->registerJs(<<<'JS'
(function () {
    document.querySelectorAll('.payment-fields').forEach(function (box) {
        if (box.dataset.ready) { return; }
        box.dataset.ready = '1';
        var accepted = JSON.parse(box.dataset.accepted || '[]').map(Number);
        var fields = box.querySelector('.insurer-fields');
        var select = box.querySelector('.insurer-select');
        var warning = box.querySelector('.insurer-warning');
        function update() {
            var mode = box.querySelector('.pay-mode:checked');
            var insured = mode && mode.value === 'insurance';
            fields.classList.toggle('d-none', !insured);
            var id = Number(select.value || 0);
            var unlisted = insured && id && accepted.length && accepted.indexOf(id) === -1;
            warning.classList.toggle('d-none', !unlisted);
            if (unlisted) {
                warning.textContent = box.dataset.doctor + ' does not list ' + select.options[select.selectedIndex].text
                    + '. You may need to pay for the visit yourself; you can still book.';
            }
        }
        box.querySelectorAll('.pay-mode').forEach(function (r) { r.addEventListener('change', update); });
        select.addEventListener('change', update);
        // Staff pick the patient after the form opens: fill in their last insurer
        box.addEventListener('insurer:prefill', function (e) {
            if (!e.detail || !e.detail.insurerId) { return; }
            box.querySelector('.pay-mode[value=insurance]').checked = true;
            select.value = String(e.detail.insurerId);
            box.querySelector('.member-number').value = e.detail.memberNumber || '';
            update();
        });
        update();
    });
})();
JS);
