<?php

use frontend\models\Appointments;
use yii\bootstrap5\BootstrapPluginAsset;
use yii\bootstrap5\Html;

/**
 * Confirmation dialog for cancelling an appointment, with an optional note on why. Staff must also
 * say why it is cancelled (F20): the refund and the Ksh 100 charge depend on it.
 *
 * Open it with any element carrying:
 *   data-bs-toggle="modal" data-bs-target="#cancelAppointmentModal" data-cancel-url="<appointments/cancel URL>"
 * and data-facility-closed="1" when the facility is closed that day, to pre-select that reason.
 *
 * @var yii\web\View $this
 * @var string|null $action cancel URL when the page is about a single appointment
 * @var bool $facilityClosed with $action: the facility is closed that day
 */

BootstrapPluginAsset::register($this);
$askReason = Appointments::asksCancelReason(Yii::$app->user->identity);
$facilityClosed = !empty($facilityClosed);
?>
<div class="modal fade" id="cancelAppointmentModal" tabindex="-1" aria-labelledby="cancelAppointmentLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <?= Html::beginForm($action ?? '', 'post', ['id' => 'cancel-appointment-form']) ?>
            <div class="modal-header">
                <h5 class="modal-title" id="cancelAppointmentLabel"><?= Yii::t('app', 'Cancel appointment') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= Yii::t('app', 'Close') ?>"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted"><?= Yii::t('app', 'The appointment will stay in the history as cancelled, and its reminders will stop.') ?></p>
                <?php if ($askReason): ?>
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6"><?= Yii::t('app', 'Why is it cancelled?') ?></legend>
                        <?php foreach (Appointments::cancelReasonLabels() as $value => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="reason" required
                                    id="cancel-reason-<?= $value ?>" value="<?= $value ?>"
                                    <?= $facilityClosed && $value === Appointments::REASON_FACILITY_CLOSED ? 'checked' : '' ?>>
                                <label class="form-check-label" for="cancel-reason-<?= $value ?>"><?= Html::encode($label) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
                <label class="form-label" for="cancel-note"><?= Yii::t('app', 'Reason (optional)') ?></label>
                <textarea id="cancel-note" name="note" class="form-control" rows="3" maxlength="500"
                    placeholder="<?= Yii::t('app', 'e.g. Patient is travelling, or the doctor is unavailable') ?>"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= Yii::t('app', 'Keep appointment') ?></button>
                <button type="submit" class="btn btn-danger"><?= Yii::t('app', 'Cancel appointment') ?></button>
            </div>
            <?= Html::endForm() ?>
        </div>
    </div>
</div>
<?php
// Point the form at the appointment whose button opened the dialog, and start with an empty note
$this->registerJs(<<<JS
document.getElementById('cancelAppointmentModal').addEventListener('show.bs.modal', function (e) {
    var url = e.relatedTarget && e.relatedTarget.getAttribute('data-cancel-url');
    if (url) {
        this.querySelector('form').action = url;
        var closed = e.relatedTarget.getAttribute('data-facility-closed') === '1';
        this.querySelectorAll('input[name=reason]').forEach(function (radio) {
            radio.checked = closed && radio.value === 'facility_closed';
        });
    }
    this.querySelector('textarea').value = '';
});
JS);
