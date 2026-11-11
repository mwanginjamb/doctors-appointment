<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Facility $model */
/** @var frontend\models\FacilityRequest|null $facilityRequest when creating a facility a consultant asked for */

$facilityRequest = $facilityRequest ?? null;
$this->title = 'Add Facility';
$this->params['breadcrumbs'][] = ['label' => 'Facilities', 'url' => ['index']];
if ($facilityRequest) {
    $this->params['breadcrumbs'][] = ['label' => 'Facility Requests', 'url' => ['requests']];
}
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?php if ($facilityRequest): ?>
        <div class="alert alert-info" style="max-width: 40rem">
            Requested by <strong><?= Html::encode($facilityRequest->consultant->names ?? 'a consultant') ?></strong>.
            Check and correct the details; saving lists the facility, adds it to their facilities and tells them.
            <?php if ($facilityRequest->note): ?>
                <div class="small mt-1">Their note: <?= Html::encode($facilityRequest->note) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?= $this->render('_form', ['model' => $model]) ?>

</div>
