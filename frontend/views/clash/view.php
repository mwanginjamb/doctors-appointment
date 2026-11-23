<?php

use common\helpers\Contact;
use frontend\models\Appointments;
use frontend\services\Clashes;
use yii\bootstrap5\Html;

/**
 * F14: bookings that clash with a closure, time off or holiday, for whoever handles them. Staff
 * may move some themselves first, then tell the rest; told patients get a link to choose a new
 * time, which works inside the usual cut-off. The day before, staff are reminded of those who
 * have not moved.
 *
 * @var yii\web\View $this
 * @var frontend\models\FacilityClosure|frontend\models\ConsultantTimeOff|frontend\models\PublicHoliday $source
 * @var string $type
 * @var array[] $rows Clashes::rowsFor()
 * @var int $untold clashing now and not yet told
 */

$this->title = Yii::t('app', 'Bookings to move');
$open = array_filter($rows, function ($r) { return $r['open']; });
$done = array_filter($rows, function ($r) { return !$r['open']; });
?>
<div class="clash-view container my-4" style="max-width: 60rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="lead mb-1" id="clash-source"><?= Html::encode(Clashes::describe($source)) ?></p>
    <p class="text-muted">
        <?= Yii::t('app', 'These bookings were made before and are kept. Move any you can agree with the patient yourself (open it, or drag it on the calendar), then tell the rest: each patient gets their link to choose a new time.') ?>
    </p>

    <?php if (!$open): ?>
        <div class="alert alert-success" id="clash-none"><?= Yii::t('app', 'No bookings clash now.') ?></div>
    <?php else: ?>
        <table class="table align-middle" id="clash-list">
            <thead><tr><th><?= Yii::t('app', 'When') ?></th><th><?= Yii::t('app', 'Patient') ?></th><th><?= Yii::t('app', 'Consultant') ?></th><th><?= Yii::t('app', 'Patient told') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($open as $row): ?>
                <?php /** @var Appointments $a */ $a = $row['appointment']; $clash = $row['clash']; ?>
                <tr id="clash-<?= (int) $a->id ?>">
                    <td class="text-nowrap"><?= Html::encode(date('D j M H:i', strtotime($a->date . ' ' . $a->time))) ?><?= $a->facility ? '<div class="small text-muted">' . Html::encode($a->facility->name) . '</div>' : '' ?></td>
                    <td>
                        <?= Html::encode($a->patient->full_name ?? '—') ?>
                        <div class="small text-muted"><?= Html::encode(Contact::summary($a->patient->phone_number ?? null, $a->patient->email ?? null, $a->patient_id)) ?></div>
                    </td>
                    <td><?= Html::encode($a->consultant->names ?? '') ?></td>
                    <td class="small">
                        <?php if ($clash && $clash->told_at): ?>
                            <span class="badge text-bg-warning"><?= Yii::t('app', 'Told {date}, not moved yet', ['date' => date('j M H:i', $clash->told_at)]) ?></span>
                            <?= $clash->staff_reminded_at ? '<div class="text-muted">' . Yii::t('app', 'Staff reminded') . '</div>' : '' ?>
                        <?php else: ?>
                            <span class="badge text-bg-light"><?= Yii::t('app', 'Not told') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= Html::a(Yii::t('app', 'Open'), ['/appointments/view', 'id' => $a->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($untold > 0): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <?= Html::beginForm(['tell', 'type' => $type, 'id' => $source->id], 'post', ['id' => 'clash-tell-form']) ?>
                        <label class="form-label" for="clash-message"><?= Yii::t('app', 'Add a message for the patients (optional)') ?></label>
                        <textarea id="clash-message" name="message" class="form-control mb-2" rows="2" maxlength="500"
                            placeholder="<?= Html::encode(Yii::t('app', 'e.g. Dr. Otieno is attending a conference; we are sorry for the change.')) ?>"></textarea>
                        <p class="small text-muted mb-2">
                            <?= $type === Clashes::TYPE_TIME_OFF
                                ? Yii::t('app', 'Patients are told the doctor is not available at that time. The reason for the time off is not shared unless you write it here.')
                                : Yii::t('app', 'Patients are told why: {why}', ['why' => Html::encode($type === Clashes::TYPE_CLOSURE ? 'the facility is closed that day' . ($source->reason ? " ({$source->reason})" : '') : "a public holiday ({$source->name})")]) ?>
                        </p>
                        <?= Html::submitButton(Yii::t('app', '{n, plural, one{Tell # patient} other{Tell # patients}}', ['n' => $untold]), ['class' => 'btn btn-primary', 'id' => 'clash-tell']) ?>
                    <?= Html::endForm() ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($done): ?>
        <h2 class="h6 mt-4"><?= Yii::t('app', 'Done') ?></h2>
        <ul class="list-unstyled small" id="clash-done">
            <?php foreach ($done as $row): ?>
                <?php $a = $row['appointment']; ?>
                <li>
                    <?= Html::encode($a->patient->full_name ?? 'Patient') ?>:
                    <?= $a->status === Appointments::STATUS_CANCELLED
                        ? Yii::t('app', 'cancelled')
                        : Html::encode(Yii::t('app', 'now {when}', ['when' => date('D j M H:i', strtotime($a->date . ' ' . $a->time))])) ?>
                    · <?= Html::a(Yii::t('app', 'Open'), ['/appointments/view', 'id' => $a->id]) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
