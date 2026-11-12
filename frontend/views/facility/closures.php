<?php

use yii\bootstrap5\ActiveForm;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Facility $facility */
/** @var frontend\models\FacilityClosure $closure the "close a day" form */
/** @var frontend\models\FacilityClosure[] $closures upcoming closures, earliest first */
/** @var array $booked Y-m-d => appointments still booked there that day */

// Consultancy admins come from the facility list; a facility admin from their own facility
$full = Yii::$app->user->can(\common\components\RbacHelper::PERM_MANAGE_REFERENCE_DATA);
$this->title = 'Closed days: ' . $facility->name;
$this->params['breadcrumbs'][] = $full
    ? ['label' => 'Facilities', 'url' => ['index']]
    : ['label' => 'My Facility', 'url' => ['update', 'id' => $facility->id]];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-closures" style="max-width: 48rem">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Days <?= Html::encode($facility->name) ?> is shut, on top of the
        <?= $full ? Html::a('public holidays', ['holiday/index']) : 'public holidays' ?>. Nobody can be booked here those days; consultants
        can still be booked at their other facilities. Appointments already booked are kept, for staff to move.
    </p>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 fw-bold">Close a day</h2>
            <?php $f = ActiveForm::begin(['id' => 'facility-closure-form']); ?>
            <div class="row g-2">
                <div class="col-sm-4"><?= $f->field($closure, 'date')->input('date', ['min' => date('Y-m-d')]) ?></div>
                <div class="col-sm-8"><?= $f->field($closure, 'reason')->textInput(['maxlength' => 150]) ?></div>
            </div>
            <?= Html::submitButton('Close this day', ['class' => 'btn btn-warning']) ?>
            <?php ActiveForm::end(); ?>
        </div>
    </div>

    <h2 class="h6 fw-bold">Upcoming</h2>
    <?php if (!$closures): ?>
        <p class="text-muted">No closed days ahead.</p>
    <?php else: ?>
        <table class="table table-sm align-middle closures">
            <thead><tr><th>Date</th><th>Reason</th><th>Still booked</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($closures as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= Html::encode(date('D j M Y', strtotime($row->date))) ?></td>
                        <td><?= Html::encode($row->reason) ?></td>
                        <td>
                            <?php if (!empty($booked[$row->date])): ?>
                                <?= Html::a((int) $booked[$row->date] . ' to move', ['board/index', 'facility' => $facility->id, 'from' => $row->date, 'to' => $row->date], ['class' => 'text-warning']) ?>
                            <?php else: ?>
                                <span class="text-muted">none</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= Html::beginForm(['delete-closure', 'id' => $row->id], 'post', ['class' => 'd-inline']) ?>
                                <?= Html::submitButton('Open again', ['class' => 'btn btn-sm btn-outline-secondary', 'data-confirm' => 'Open ' . $facility->name . ' again on this day?']) ?>
                            <?= Html::endForm() ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p><?= $full
        ? Html::a('Back to facilities', ['index'], ['class' => 'btn btn-link px-0'])
        : Html::a('Back to my facility', ['update', 'id' => $facility->id], ['class' => 'btn btn-link px-0']) ?></p>
</div>
