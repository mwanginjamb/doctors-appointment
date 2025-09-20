<?php

use yii\helpers\Url;
use yii\helpers\Html;
use yii\widgets\Pjax;
use yii\grid\GridView;
use yii\jui\DatePicker;
use yii\grid\ActionColumn;
use frontend\models\Appointments;
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
        <?= Html::a(Yii::t('app', '<i class="fa fas-calendar"></i> My Calendar'), ['appointments/my-calendar'], ['class' => 'btn btn-lg btn-outline-info']) ?>
        <?= (Yii::$app->user->identity->role === 'consultant') ? Html::a(Yii::t('app', 'Edit Calendar Bookings'), ['appointments/calendar'], ['class' => 'btn btn-lg btn-outline-dark']) : '' ?>

    </div>

    <?php Pjax::begin(); ?>
    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],

            // 'id',
            [
                'attribute' => 'date',
                // long date format
                'format' => ['date', 'php:Y-m-d'],
                'value' => 'date',
                'filter' => DatePicker::widget([
                    'model' => $searchModel,
                    'attribute' => 'date',
                    'dateFormat' => 'php:Y-m-d',
                    'options' => ['class' => 'form-control'],
                ]),
                'label' => 'Appointment Date',
            ],
            'time:time',
            // filter condition: user_id me
            [
                'attribute' => 'patient_id',
                'value' => 'patient.full_name',
                'label' => 'Patient',
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
                'label' => 'Consultant',
                'filter' => Html::activeDropDownList($searchModel, 'consultant_id', \yii\helpers\ArrayHelper::map(\frontend\models\Consultant::find()->asArray()->all(), 'user_id', 'names'), ['class' => 'form-control', 'prompt' => 'Select Consultant']),

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