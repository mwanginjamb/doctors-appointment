<?php

use yii\helpers\Html;
use yii\widgets\DetailView;

/** @var yii\web\View $this */
/** @var app\models\Appointments $model */

$this->title = $model->id;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Appointments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
?>
<div class="appointments-view">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('app', 'Update'), ['update', 'id' => $model->id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a(Yii::t('app', 'Delete'), ['delete', 'id' => $model->id], [
            'class' => 'btn btn-danger',
            'data' => [
                'confirm' => Yii::t('app', 'Are you sure you want to delete this item?'),
                'method' => 'post',
            ],
        ]) ?>
    </p>

    <?= DetailView::widget([
        'model' => $model,
        'attributes' => [
            //'id',
            'date:date',
            'time:time',
            // 'patient_id',
            // 'speciality_id',
            // 'service_id',
            // 'provider_id',
            // 'location:ntext',
            'recurring_appointment:boolean',
            'walk_in_appointment:boolean',
            'symptoms_brief:ntext',
            'created_at:datetime',
            'updated_at:datetime',
            // 'created_by',
            //'updated_by',
            [
                'label' => 'Consultant',
                'attribute' => 'consultant_id',
                'value' => function ($model) {
                return $model->consultant->names;
            }
            ],
        ],
    ]) ?>

</div>