<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Facility $model */
/** @var bool $contactOnly a facility admin editing their own facility */

$contactOnly = $contactOnly ?? false;
$this->title = $contactOnly ? 'My Facility: ' . $model->name : 'Edit Facility: ' . $model->name;
if (!$contactOnly) {
    $this->params['breadcrumbs'][] = ['label' => 'Facilities', 'url' => ['index']];
}
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?php if ($contactOnly): ?>
        <p class="text-muted">
            How patients reach <?= Html::encode($model->name) ?>: sent with every confirmation and reminder.
            The name, and whether it takes bookings, are set by the clinic administrators.
        </p>
        <p><?= Html::a('Closed days', ['closures', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?></p>
    <?php endif; ?>

    <?= $this->render('_form', ['model' => $model, 'contactOnly' => $contactOnly]) ?>

</div>
