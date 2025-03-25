<?php

use frontend\models\Appointments;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\ActionColumn;
use yii\grid\GridView;
use yii\widgets\Pjax;
/** @var yii\web\View $this */
/** @var frontend\models\AppointmentsSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

$this->title = Yii::t('app', 'Appointments');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="appointments-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <div class="actions d-flex justify-content-between my-3">
        <?php Html::a(Yii::t('app', 'Create Appointments'), ['create'], ['class' => 'btn btn-lg btn-outline-success']) ?>
        <?= Html::a(Yii::t('app', 'My Calendar'), ['appointments/my-calendar'], ['class' => 'btn btn-lg btn-outline-info']) ?>

    </div>

    <?php Pjax::begin(); ?>
    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            // 'id',
            'date:date',
            'time:time',
            [
                'attribute' => 'patient_id',
                'value' => 'patient.full_name',
                'label' => 'patient_id',
            ],
            // 'speciality_id',
            //'service_id',
            //'provider_id',
            //'location:ntext',
            //'recurring_appointment',
            //'walk_in_appointment',
            'symptoms_brief:ntext',
            //'created_at',
            //'updated_at',
            //'created_by',
            //'updated_by',
            [
                'attribute' => 'consultant_id',
                'value' => 'consultant.names',
                'label' => 'consultant_id',
            ],
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