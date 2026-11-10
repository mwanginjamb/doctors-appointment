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
        <?= Yii::$app->user->identity->isConsultantAccount() ? Html::a(Yii::t('app', 'Edit Calendar Bookings'), ['appointments/calendar'], ['class' => 'btn btn-lg btn-outline-dark']) : '' ?>

    </div>

    <?php Pjax::begin(); ?>
    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'rowOptions' => function ($model, $key, $index, $grid) {
                if ($model->status === Appointments::STATUS_CONFIRMED) {
                    return ['class' => 'table-success'];
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
                    Appointments::STATUS_CONFIRMED => 'confirmed',
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
                'template' => '{view} {update} {cancel} {confirm} {outcome}',
                'buttons' => [
                    // Attended / No-show once the appointment has started (consultant only on this list)
                    'outcome' => function ($url, $model, $key) {
                            return $this->render('_outcome_buttons', ['model' => $model]);
                        },
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
                    // Opens _cancel_modal, which asks for an optional reason
                    'cancel' => function ($url, $model, $key) {
                            return Html::button(Yii::t('app', 'Cancel'), [
                                'class' => 'btn btn-sm btn-outline-danger my-2',
                                'title' => Yii::t('app', 'Cancel Appointment'),
                                'data' => [
                                    'bs-toggle' => 'modal',
                                    'bs-target' => '#cancelAppointmentModal',
                                    'cancel-url' => Url::to(['appointments/cancel', 'id' => $model->id]),
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
                    'outcome' => function ($model) {
                            return $model->canRecordOutcome() && \frontend\components\FrontDesk::canRecordOutcome($model);
                        },
                    'update' => function ($model) {
                            return \frontend\components\FrontDesk::canBookFor($model->consultant_id) && $model->status === Appointments::STATUS_SCHEDULED;
                        },
                    // The list only holds the user's own appointments, so either participant may cancel
                    'cancel' => function ($model) {
                            return $model->canBeCancelled();
                        },
                    'confirm' => function ($model) {
                            return $model->status === Appointments::STATUS_SCHEDULED && Yii::$app->user->can(\common\components\RbacHelper::PERM_CONFIRM_APPOINTMENT, ['appointment' => $model]);
                        },

                ],
                'header' => 'Actions',
                'headerOptions' => ['style' => 'color:#337ab7'],

            ],
        ],
    ]); ?>

    <?php Pjax::end(); ?>

    <?= $this->render('_cancel_modal') ?>

</div>