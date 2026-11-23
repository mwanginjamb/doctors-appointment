<?php

use frontend\models\Appointments;
use frontend\models\Facility;
use frontend\services\Clashes;
use yii\bootstrap5\Html;

/**
 * F14 part 2: the planned new time of each ticked booking, before anything is moved. Saving moves
 * them to exactly these times; each patient is told the new time, with their link to change it.
 *
 * @var yii\web\View $this
 * @var frontend\models\FacilityClosure|frontend\models\ConsultantTimeOff|frontend\models\PublicHoliday $source
 * @var string $type
 * @var string $date Y-m-d, the chosen day
 * @var int|null $facilityId the chosen facility, or null for where each was booked
 * @var array[] $plan Clashes::planMove()
 */

$this->title = Yii::t('app', 'Check the new times');
$placed = array_filter($plan, function ($row) { return $row['date'] !== null; });
$names = Facility::find()->select('name')->where(['id' => array_unique(array_column($plan, 'facilityId'))])->indexBy('id')->column();
?>
<div class="clash-move container my-4" style="max-width: 56rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted mb-3">
        <?= Html::encode(Clashes::describe($source)) ?> ·
        <?= Html::encode(Yii::t('app', 'from {date}', ['date' => date('l j M Y', strtotime($date))])) ?>
        <?= $facilityId ? ' · ' . Html::encode($names[$facilityId] ?? '') : '' ?>
    </p>

    <table class="table align-middle" id="move-plan">
        <thead><tr><th><?= Yii::t('app', 'Patient') ?></th><th><?= Yii::t('app', 'Now') ?></th><th><?= Yii::t('app', 'New time') ?></th></tr></thead>
        <tbody>
        <?php foreach ($plan as $row): ?>
            <?php /** @var Appointments $a */ $a = $row['appointment']; ?>
            <tr id="move-<?= (int) $a->id ?>" class="<?= $row['date'] === null ? 'table-warning' : '' ?>">
                <td><?= Html::encode($a->patient->full_name ?? '—') ?><div class="small text-muted"><?= Html::encode($a->consultant->names ?? '') ?></div></td>
                <td class="text-nowrap small"><?= Html::encode(date('D j M H:i', strtotime($a->date . ' ' . $a->time))) ?><?= $a->facility ? '<div class="text-muted">' . Html::encode($a->facility->name) . '</div>' : '' ?></td>
                <td class="text-nowrap">
                    <?php if ($row['date'] !== null): ?>
                        <strong><?= Html::encode(date('D j M', strtotime($row['date'])) . ' ' . $row['time']) ?></strong>
                        <?= $row['date'] !== $date ? '<span class="badge text-bg-light ms-1">' . Yii::t('app', 'next free day') . '</span>' : '' ?>
                        <div class="small text-muted"><?= Html::encode($names[$row['facilityId']] ?? '') ?></div>
                    <?php else: ?>
                        <span class="text-danger small"><?= Html::encode($row['problem']) ?> <?= Yii::t('app', 'It stays as it is.') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($placed): ?>
        <?= Html::beginForm(['move', 'type' => $type, 'id' => $source->id], 'post', ['id' => 'move-save-form']) ?>
            <?= Html::hiddenInput('step', 'save') ?>
            <?php foreach ($placed as $row): ?>
                <?= Html::hiddenInput('plan[' . (int) $row['appointment']->id . ']', $row['date'] . ' ' . $row['time']) ?>
            <?php endforeach; ?>
            <label class="form-label" for="move-message"><?= Yii::t('app', 'Message for the patients (optional)') ?></label>
            <textarea id="move-message" name="message" class="form-control mb-2" rows="2" maxlength="500"></textarea>
            <p class="small text-muted"><?= Yii::t('app', 'Each patient is told their new time and place, with their link to choose another if it does not suit them.') ?></p>
            <?= Html::submitButton(Yii::t('app', '{n, plural, one{Move # booking} other{Move # bookings}}', ['n' => count($placed)]), ['class' => 'btn btn-primary', 'id' => 'move-save']) ?>
            <?= Html::a(Yii::t('app', 'Back'), ['view', 'type' => $type, 'id' => $source->id], ['class' => 'btn btn-link']) ?>
        <?= Html::endForm() ?>
    <?php else: ?>
        <div class="alert alert-warning"><?= Yii::t('app', 'None of them can be moved from that day. Try another day or facility.') ?></div>
        <?= Html::a(Yii::t('app', 'Back'), ['view', 'type' => $type, 'id' => $source->id], ['class' => 'btn btn-outline-secondary']) ?>
    <?php endif; ?>
</div>
