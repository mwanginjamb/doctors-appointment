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
        'rowOptions' => function ($model, $key, $index, $grid) {
                if ($model->status === Appointments::STATUS_CONFIRMED) {
                    return ['class' => 'table-success text-light'];
                }

                return [];
            },
        'columns' => [
            ['class' => 'yii\grid\SerialColumn'],
            [
                'attribute' => 'date',
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
            [
                'attribute' => 'status',
                'value' => 'status',
                'label' => 'Status',
                'filter' => Html::activeDropDownList($searchModel, 'status', [
                    Appointments::STATUS_SCHEDULED => 'Scheduled',
                    Appointments::STATUS_COMPLETED => 'Completed',
                    Appointments::STATUS_CANCELLED => 'Cancelled',
                    Appointments::STATUS_NO_SHOW => 'No Show',
                ], ['class' => 'form-control', 'prompt' => 'Select ...']),
            ],
            [
                'attribute' => 'patient_id',
                'value' => 'patient.full_name',
                'label' => 'Patient',
            ],
            'symptoms_brief:ntext',
            'created_at:datetime',
            [
                'attribute' => 'consultant_id',
                'value' => 'consultant.names',
                'label' => 'Consultant',
                'filter' => Html::activeDropDownList($searchModel, 'consultant_id', \yii\helpers\ArrayHelper::map(\frontend\models\Consultant::find()->asArray()->all(), 'user_id', 'names'), ['class' => 'form-control', 'prompt' => 'Select Consultant']),

            ],
            [
                'class' => ActionColumn::className(),
                /*'urlCreator' => function ($action, Appointments $model, $key, $index, $column) {
                        return Url::toRoute([$action, 'id' => $model->id]);
                    }*/
                'template' => '{view} {update} {delete} {confirm}',
                'buttons' => [
                    'confirm' => function ($url, $model, $key) {
                            return Html::a('<span class="btn btn-sm btn-outline-success">Confirm</span>', ['appointments/confirm', 'id' => $model->id], [
                                'title' => Yii::t('app', 'Confirm Appointment'),
                                'data' => [
                                    'confirm' => 'Are you sure you want to confirm this appointment?',
                                    'method' => 'post',
                                ],
                            ]);
                        },
                    'update' => function ($url, $model, $key) {
                            return Html::a('<span class="btn btn-sm btn-outline-primary my-2">Update</span>', ['appointments/update', 'id' => $model->id], [
                                'title' => Yii::t('app', 'Update Appointment'),
                            ]);
                        },
                    'delete' => function ($url, $model, $key) {
                            return Html::a('<span class="btn btn-sm btn-outline-danger my-2">Cancel</span>', ['appointments/delete', 'id' => $model->id], [
                                'title' => Yii::t('app', 'Delete Appointment'),
                                'data' => [
                                    'confirm' => 'Are you sure you want to cancel this appointment?',
                                    'method' => 'post',
                                ],
                            ]);
                        },
                    'view' => function ($url, $model, $key) {
                            return Html::a('<span class="btn btn-sm btn-outline-info my-2">View</span>', ['appointments/view', 'id' => $model->id], [
                                'title' => Yii::t('app', 'View Appointment'),
                            ]);
                        },

                ],
                'visibleButtons' => [
                    'update' => function ($model) {
                            return (Yii::$app->user->identity->role === 'super' && $model->status === Appointments::STATUS_SCHEDULED) ? true : false;
                        },
                    'delete' => function ($model) {
                            return (Yii::$app->user->identity->role === 'patient' && $model->status === Appointments::STATUS_SCHEDULED) ? true : false;
                        },
                    'confirm' => function ($model) {
                            return (Yii::$app->user->identity->role === 'consultant' && $model->status === Appointments::STATUS_SCHEDULED) ? true : false;
                        },

                ],
                'header' => 'Actions',
                'headerOptions' => ['style' => 'color:#337ab7'],

            ],
        ],
    ]); ?>

    <?php Pjax::end(); ?>

</div>