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
        <!-- Part 2: tick bookings, then move them to another day or cancel them -->
        <?= Html::beginForm(['move', 'type' => $type, 'id' => $source->id], 'post', ['id' => 'clash-bulk-form']) ?>
        <table class="table align-middle" id="clash-list">
            <thead><tr>
                <th style="width: 2rem"><input type="checkbox" class="form-check-input" id="clash-all" aria-label="<?= Html::encode(Yii::t('app', 'Tick all')) ?>"></th>
                <th><?= Yii::t('app', 'When') ?></th><th><?= Yii::t('app', 'Patient') ?></th><th><?= Yii::t('app', 'Consultant') ?></th><th><?= Yii::t('app', 'Patient told') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($open as $row): ?>
                <?php /** @var Appointments $a */ $a = $row['appointment']; $clash = $row['clash']; ?>
                <tr id="clash-<?= (int) $a->id ?>">
                    <td><input type="checkbox" class="form-check-input clash-pick" name="appointments[]" value="<?= (int) $a->id ?>" id="clash-pick-<?= (int) $a->id ?>"
                        aria-label="<?= Html::encode(Yii::t('app', 'Tick {patient}', ['patient' => $a->patient->full_name ?? ''])) ?>"></td>
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

        <div class="row g-3 mb-4" id="clash-bulk">
            <div class="col-md-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6"><?= Yii::t('app', 'Move the ticked bookings to another day') ?></h2>
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-5">
                                <label class="form-label small mb-1" for="clash-date"><?= Yii::t('app', 'Day') ?></label>
                                <input type="date" name="date" id="clash-date" class="form-control" min="<?= date('Y-m-d') ?>">
                            </div>
                            <?php if (count($facilities) > 1): ?>
                                <div class="col-sm-7">
                                    <label class="form-label small mb-1" for="clash-facility"><?= Yii::t('app', 'Facility') ?></label>
                                    <?= Html::dropDownList('facility', '', $facilities, ['id' => 'clash-facility', 'class' => 'form-select', 'prompt' => Yii::t('app', 'Where each was booked')]) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <p class="small text-muted mt-2 mb-2"><?= Yii::t('app', 'Each takes the first free time that day with its consultant, in order; any that do not fit go to the next days. You see the new times before anything changes.') ?></p>
                        <button type="submit" class="btn btn-primary" id="clash-move"><?= Yii::t('app', 'Preview the moves') ?></button>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6"><?= Yii::t('app', 'Cancel the ticked bookings') ?></h2>
                        <label class="form-label small mb-1" for="clash-cancel-message"><?= Yii::t('app', 'Message for the patients (optional)') ?></label>
                        <textarea name="message" id="clash-cancel-message" class="form-control mb-2" rows="2" maxlength="500"></textarea>
                        <p class="small text-muted mb-2"><?= Yii::t('app', 'Cancelled by the clinic: a paid fee becomes a credit. Each patient is told, with a link to book again.') ?></p>
                        <button type="submit" class="btn btn-outline-danger" id="clash-cancel"
                            formaction="<?= Html::encode(\yii\helpers\Url::to(['cancel', 'type' => $type, 'id' => $source->id])) ?>"
                            data-confirm="<?= Html::encode(Yii::t('app', 'Cancel the ticked bookings? Each patient will be told.')) ?>"><?= Yii::t('app', 'Cancel them') ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?= Html::endForm() ?>

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
<?php
// Tick all
$this->registerJs(<<<'JS'
(function () {
    var all = document.getElementById('clash-all');
    if (!all) { return; }
    all.addEventListener('change', function () {
        document.querySelectorAll('.clash-pick').forEach(function (box) { box.checked = all.checked; });
    });
})();
JS);
