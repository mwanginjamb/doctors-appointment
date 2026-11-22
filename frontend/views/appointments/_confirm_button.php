<?php

use frontend\models\Appointments;
use yii\bootstrap5\Html;

/**
 * F13: Confirm, asking how the patient confirmed (phone, in person, WhatsApp/SMS, other). Shown
 * only to those who may confirm the appointment, while it can be confirmed.
 *
 * @var yii\web\View $this
 * @var Appointments $model
 * @var string $size optional: btn-sm (default) or ''
 */

if (!$model->canBeConfirmed() || !\frontend\components\FrontDesk::canConfirm($model)) {
    return;
}
$size = $size ?? 'btn-sm';
?>
<div class="btn-group confirm-appointment" id="confirm-<?= (int) $model->id ?>">
    <button type="button" class="btn <?= $size ?> btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <?= Yii::t('app', 'Confirm') ?>
    </button>
    <?= Html::beginForm(['/appointments/confirm', 'id' => $model->id], 'post', ['class' => 'dropdown-menu p-0 confirm-appointment-form']) ?>
        <h6 class="dropdown-header"><?= Yii::t('app', 'How did the patient confirm?') ?></h6>
        <?php foreach (Appointments::confirmedViaChoices() as $value => $label): ?>
            <button type="submit" name="via" value="<?= Html::encode($value) ?>" class="dropdown-item"><?= Html::encode(Yii::t('app', $label)) ?></button>
        <?php endforeach; ?>
    <?= Html::endForm() ?>
</div>
