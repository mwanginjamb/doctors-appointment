<?php

use yii\bootstrap5\ActiveForm;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Consultant $consultant */
/** @var frontend\models\ConsultantSession $session the "add a session" form */
/** @var frontend\models\ConsultantSession[] $sessions upcoming sessions the viewer may manage */
/** @var array $booked session id => appointments booked in it */
/** @var array $facilities facility id => name: where the viewer may add sessions */
/** @var int|null $cid consultant.id when it is someone else's calendar */

$own = $cid === null;
$this->title = $own ? 'One-off sessions' : 'One-off sessions: ' . $consultant->names;
$this->params['breadcrumbs'][] = $own
    ? ['label' => 'Availability', 'url' => ['availability/index']]
    : ['label' => 'Appointments Board', 'url' => ['board/index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="session-index my-4" style="max-width: 48rem">

    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        A session on one date, outside the weekly hours: an outreach clinic on a Saturday, an extra
        morning before a holiday. Patients can be booked into it like into weekly hours, with the session
        length and fee at that facility. It cannot overlap <?= $own ? 'your' : Html::encode($consultant->names) . "'s" ?>
        other hours that day.<?= $own ? '' : ' ' . Html::encode($consultant->names) . ' is told when you add or remove one.' ?>
    </p>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 fw-bold">Add a session</h2>
            <?php $f = ActiveForm::begin(['id' => 'session-form']); ?>
            <div class="row g-2">
                <div class="col-sm-6">
                    <?= $f->field($session, 'facility_id')->dropDownList($facilities, ['prompt' => count($facilities) > 1 ? 'Where?' : null]) ?>
                </div>
                <div class="col-sm-6"><?= $f->field($session, 'date')->input('date', ['min' => date('Y-m-d')]) ?></div>
                <div class="col-6 col-sm-3"><?= $f->field($session, 'start_time')->input('time', ['value' => $session->start_time ? substr($session->start_time, 0, 5) : '']) ?></div>
                <div class="col-6 col-sm-3"><?= $f->field($session, 'end_time')->input('time', ['value' => $session->end_time ? substr($session->end_time, 0, 5) : '']) ?></div>
                <div class="col-sm-6"><?= $f->field($session, 'note')->textInput(['maxlength' => 150, 'placeholder' => 'e.g. Outreach clinic']) ?></div>
            </div>
            <?= Html::submitButton('Add session', ['class' => 'btn btn-primary']) ?>
            <?php ActiveForm::end(); ?>
        </div>
    </div>

    <h2 class="h6 fw-bold">Upcoming</h2>
    <?php if (!$sessions): ?>
        <p class="text-muted">No sessions ahead.</p>
    <?php else: ?>
        <table class="table table-sm align-middle sessions">
            <thead><tr><th>When</th><th>Where</th><th>Note</th><th>Booked</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($sessions as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= Html::encode(date('D j M Y', strtotime($row->date)) . ', ' . substr($row->start_time, 0, 5) . '–' . substr($row->end_time, 0, 5)) ?></td>
                        <td><?= Html::encode($row->facility->name ?? '') ?></td>
                        <td><?= Html::encode((string) $row->note) ?></td>
                        <td><?= $booked[$row->id] ?: '<span class="text-muted">none</span>' ?></td>
                        <td class="text-end">
                            <?php if (!$booked[$row->id]): ?>
                                <?= Html::beginForm(['delete', 'id' => $row->id], 'post', ['class' => 'd-inline']) ?>
                                    <?= Html::submitButton('Remove', ['class' => 'btn btn-sm btn-outline-danger', 'data-confirm' => 'Remove this session?']) ?>
                                <?= Html::endForm() ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
