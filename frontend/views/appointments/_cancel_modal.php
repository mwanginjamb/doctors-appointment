<?php

use yii\bootstrap5\BootstrapPluginAsset;
use yii\bootstrap5\Html;

/**
 * Confirmation dialog for cancelling an appointment, with an optional note on why.
 *
 * Open it with any element carrying:
 *   data-bs-toggle="modal" data-bs-target="#cancelAppointmentModal" data-cancel-url="<appointments/cancel URL>"
 *
 * @var yii\web\View $this
 * @var string|null $action cancel URL when the page is about a single appointment
 */

BootstrapPluginAsset::register($this);
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
    }
    this.querySelector('textarea').value = '';
});
JS);
