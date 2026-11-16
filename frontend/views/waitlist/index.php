<?php

use frontend\models\WaitlistEntry;
use yii\bootstrap5\Html;

/**
 * Staff: who is waiting for the consultants they handle, longest-waiting first.
 *
 * @var yii\web\View $this
 * @var WaitlistEntry[] $entries
 * @var frontend\models\Consultant[] $consultants those the user may add patients for
 */

$this->title = 'Waiting list';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="waitlist-index my-4">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h3 fw-bold me-auto mb-0"><?= Html::encode($this->title) ?></h1>
        <?php foreach ($consultants as $consultant): ?>
            <?= Html::a('Add a patient' . (count($consultants) > 1 ? ': ' . Html::encode($consultant->names) : ''), ['add', 'cid' => $consultant->id], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        <?php endforeach; ?>
    </div>
    <p class="text-muted small">
        When a time frees up, everyone waiting for it gets a link to book it; the first to book gets it.
        Patients are not messaged at night, nor more than <?= (int) \frontend\services\Waitlist::param('maxNoticesPerDay', 3) ?> times a day.
    </p>

    <?php if (!$entries): ?>
        <p id="waitlist-empty">Nobody is waiting.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="waitlist-table">
                <thead>
                    <tr><th>Patient</th><th>Doctor</th><th>Facility</th><th>Waiting for</th><th>Since</th><th>Messages</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td>
                                <?= Html::encode($entry->patient->full_name ?? '') ?>
                                <div class="small text-muted"><?= Html::encode(\common\helpers\Contact::phone($entry->patient->phone_number ?? null)) ?></div>
                            </td>
                            <td><?= Html::encode($entry->consultant->names ?? '') ?></td>
                            <td><?= Html::encode($entry->facility->name ?? '') ?></td>
                            <td><?= Html::encode($entry->getWindowLabel()) ?></td>
                            <td><?= Html::encode(Yii::$app->formatter->asRelativeTime($entry->created_at)) ?></td>
                            <td><?= (int) $entry->notices_sent ?></td>
                            <td>
                                <?= Html::beginForm(['remove', 'id' => $entry->id], 'post', ['class' => 'd-inline']) ?>
                                    <?= Html::submitButton('Remove', [
                                        'class' => 'btn btn-outline-secondary btn-sm',
                                        'data' => ['confirm' => 'Take ' . ($entry->patient->full_name ?? 'this patient') . ' off the waiting list?'],
                                    ]) ?>
                                <?= Html::endForm() ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
