<?php

use app\models\Appointments;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\widgets\Pjax;
/** @var yii\web\View $this */
/** @var app\models\AppointmentsSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('app', 'Appointments');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="appointments-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a(Yii::t('app', 'Create Appointments'), ['create'], ['class' => 'btn btn-success']) ?>
    </p>

    <?php Pjax::begin(); ?>
    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            'id',
            'date',
            'time',
            'patient_id',
            'speciality_id',
            //'service_id',
            //'provider_id',
            //'location:ntext',
            //'recurring_appointment',
            //'walk_in_appointment',
            //'symptoms_brief:ntext',
            //'created_at',
            //'updated_at',
            //'created_by',
            //'updated_by',
            //'consultant_id',
            [
                'class' => ActionColumn::className(),
                'urlCreator' => function ($action, Appointments $model, $key, $index, $column) {
                    return Url::toRoute([$action, 'id' => $model->id]);
                 }
            ],
        ],
    ]); ?>

    <?php Pjax::end(); ?>

</div>
