<?php

use frontend\models\AppointmentBoardSearch;
use frontend\models\Appointments;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var AppointmentBoardSearch $search */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var array $counts status => count */

$this->title = 'Appointments Board';
$this->params['breadcrumbs'][] = $this->title;

$f = Yii::$app->formatter;
$range = $search->from === $search->to
    ? $f->asDate(strtotime($search->from), 'php:l j M Y')
    : $f->asDate(strtotime($search->from), 'php:j M') . ' – ' . $f->asDate(strtotime($search->to), 'php:j M Y');

$statusBadges = [
    Appointments::STATUS_SCHEDULED => 'text-bg-primary',
    Appointments::STATUS_CONFIRMED => 'text-bg-success',
    Appointments::STATUS_CANCELLED => 'text-bg-secondary',
    Appointments::STATUS_COMPLETED => 'text-bg-dark',
    Appointments::STATUS_NO_SHOW => 'text-bg-warning',
];

/** Same filters with a different date range. */
$rangeLink = function ($label, $from, $to) use ($search) {
    $active = $search->from === $from && $search->to === $to;
    return Html::a($label, ['index', 'from' => $from, 'to' => $to, 'consultant' => $search->consultant, 'status' => $search->status, 'q' => $search->q], [
        'class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-primary'),
    ]);
};
$today = date('Y-m-d');
?>
<div class="board-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">Every consultant's appointments. Open one to see the details, or cancel it with a note.</p>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <?= $rangeLink('Today', $today, $today) ?>
        <?= $rangeLink('Tomorrow', date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day'))) ?>
        <?= $rangeLink('Next 7 days', $today, date('Y-m-d', strtotime('+6 days'))) ?>
        <?= $rangeLink('Last 7 days', date('Y-m-d', strtotime('-6 days')), $today) ?>
    </div>

    <?= Html::beginForm(['index'], 'get', ['class' => 'row g-2 align-items-end mb-3', 'id' => 'board-filters']) ?>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="board-from">From</label>
            <?= Html::input('date', 'from', $search->from, ['class' => 'form-control form-control-sm', 'id' => 'board-from']) ?>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small" for="board-to">To</label>
            <?= Html::input('date', 'to', $search->to, ['class' => 'form-control form-control-sm', 'id' => 'board-to']) ?>
        </div>
        <div class="col-md-3">
            <label class="form-label small" for="board-consultant">Consultant</label>
            <?= Html::dropDownList('consultant', $search->consultant, AppointmentBoardSearch::consultantOptions(), ['class' => 'form-select form-select-sm', 'id' => 'board-consultant', 'prompt' => 'All consultants']) ?>
        </div>
        <div class="col-md-2">
            <label class="form-label small" for="board-status">Status</label>
            <?= Html::dropDownList('status', $search->status, AppointmentBoardSearch::statusOptions(), ['class' => 'form-select form-select-sm', 'id' => 'board-status', 'prompt' => 'Any status']) ?>
        </div>
        <div class="col-md-2">
            <label class="form-label small" for="board-q">Patient</label>
            <?= Html::input('search', 'q', $search->q, ['class' => 'form-control form-control-sm', 'id' => 'board-q', 'placeholder' => 'Name, email or phone']) ?>
        </div>
        <div class="col-md-1 d-flex gap-1">
            <?= Html::submitButton('Filter', ['class' => 'btn btn-sm btn-primary']) ?>
            <?= Html::a('Reset', ['index'], ['class' => 'btn btn-sm btn-link px-1']) ?>
        </div>
    <?= Html::endForm() ?>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <strong><?= Html::encode($range) ?>:</strong>
        <?php if (!$counts): ?>
            <span class="text-muted">no appointments</span>
        <?php endif; ?>
        <?php foreach (Appointments::getStatusList() as $status => $label): ?>
            <?php if (!empty($counts[$status])): ?>
                <span class="badge <?= $statusBadges[$status] ?>"><?= $counts[$status] ?> <?= Html::encode(strtolower($label)) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'tableOptions' => ['class' => 'table table-sm table-hover align-middle'],
        'emptyText' => 'No appointments match these filters.',
        'rowOptions' => function (Appointments $model) {
            return $model->status === Appointments::STATUS_CANCELLED ? ['class' => 'text-muted'] : [];
        },
        'columns' => [
            [
                'attribute' => 'when',
                'label' => 'When',
                'format' => 'raw',
                'value' => function (Appointments $model) use ($f, $search) {
                    $ts = strtotime($model->date . ' ' . $model->time);
                    $time = Html::tag('span', $f->asTime($ts, 'php:H:i'), ['class' => 'fw-semibold']);
                    // Show the date only when the range spans several days
                    return $search->from === $search->to ? $time : $f->asDate($ts, 'php:D j M') . ' ' . $time;
                },
            ],
            [
                'attribute' => 'consultant',
                'label' => 'Consultant',
                'format' => 'raw',
                'value' => function (Appointments $model) {
                    if (!$model->consultant) {
                        return Html::tag('span', '(no profile)', ['class' => 'text-muted']);
                    }
                    return Html::a(Html::encode($model->consultant->names), ['/appointments/calendar', 'cid' => $model->consultant->id], ['title' => 'Open their calendar']);
                },
            ],
            [
                'label' => 'Patient',
                'format' => 'raw',
                'value' => function (Appointments $model) {
                    $patient = $model->patient;
                    if (!$patient) {
                        return Html::tag('span', '(deleted account)', ['class' => 'text-muted']);
                    }
                    return Html::encode($patient->full_name ?: $patient->username)
                        . ($patient->phone_number ? '<div class="small text-muted">' . Html::encode($patient->phone_number) . '</div>' : '');
                },
            ],
            [
                'attribute' => 'status',
                'label' => 'Status',
                'format' => 'raw',
                'value' => function (Appointments $model) use ($statusBadges) {
                    $status = $model->status ?: Appointments::STATUS_SCHEDULED;
                    return Html::tag('span', Html::encode(Appointments::getStatusList()[$status] ?? $status), ['class' => 'badge ' . ($statusBadges[$status] ?? 'text-bg-light')]);
                },
            ],
            [
                'label' => 'Notes',
                'format' => 'raw',
                'value' => function (Appointments $model) {
                    $text = $model->status === Appointments::STATUS_CANCELLED && $model->cancellation_note
                        ? 'Cancelled: ' . $model->cancellation_note
                        : (string) $model->symptoms_brief;
                    return Html::tag('span', Html::encode(mb_strimwidth($text, 0, 80, '…')), ['title' => $text]);
                },
            ],
            [
                'label' => 'Booked by',
                'value' => function (Appointments $model) {
                    if ((int) $model->created_by === (int) $model->patient_id) {
                        return 'Patient online';
                    }
                    if ((int) $model->created_by === (int) $model->consultant_id) {
                        return 'Consultant';
                    }
                    return $model->creator->full_name ?? $model->creator->username ?? '—';
                },
            ],
            [
                'label' => '',
                'format' => 'raw',
                'contentOptions' => ['class' => 'text-end text-nowrap'],
                'value' => function (Appointments $model) {
                    $buttons = Html::a('View', ['/appointments/view', 'id' => $model->id], ['class' => 'btn btn-sm btn-outline-secondary']);
                    if ($model->canBeCancelled()) {
                        $buttons .= ' ' . Html::button('Cancel', [
                            'class' => 'btn btn-sm btn-outline-danger',
                            'data' => [
                                'bs-toggle' => 'modal',
                                'bs-target' => '#cancelAppointmentModal',
                                'cancel-url' => Url::to(['/appointments/cancel', 'id' => $model->id]),
                            ],
                        ]);
                    }
                    return $buttons;
                },
            ],
        ],
    ]) ?>

    <?= $this->render('@frontend/views/appointments/_cancel_modal') ?>

</div>
