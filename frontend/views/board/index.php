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
/** @var Appointments[] $needsOutcome started appointments nobody has marked attended / no-show */
/** @var frontend\models\Consultant[] $bookable front desk: consultants they book for */
/** @var frontend\models\ClinicStats|null $stats front desk: numbers for their consultants */
/** @var frontend\models\Facility|null $facility front desk or facility admin: their facility */
/** @var frontend\models\Consultant[] $waiting front desk: consultants invited to their facility, not there yet */
/** @var bool $facilityAdmin the viewer runs $facility */

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
    return Html::a($label, ['index', 'from' => $from, 'to' => $to, 'consultant' => $search->consultant, 'facility' => $search->facility, 'status' => $search->status, 'q' => $search->q], [
        'class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-primary'),
    ]);
};
$today = date('Y-m-d');
?>
<div class="board-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <?php if (\frontend\components\FrontDesk::isFrontDesk()): ?>
        <?php if ($facility): ?>
            <p class="text-muted">Appointments at <strong><?= Html::encode($facility->name) ?></strong> for the consultants you work for. Open a consultant's calendar to book, or drag an appointment there to move it. Their appointments elsewhere show there only as busy.</p>
        <?php else: ?>
            <p class="text-muted">Appointments for the consultants you work for. Open a consultant's calendar to book, or drag an appointment there to move it.</p>
            <div class="alert alert-warning py-2" id="no-facility-warning">The facility you work at has not been set, so you see your consultants' appointments at every facility. Ask an administrator to set it.</div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($bookable as $consultant): ?>
                <?= Html::a('Book with ' . Html::encode($consultant->names), ['/appointments/calendar', 'cid' => $consultant->id], ['class' => 'btn btn-sm btn-success']) ?>
                <?= Html::a('Send link', ['/booking-link/create', 'cid' => $consultant->id], ['class' => 'btn btn-sm btn-outline-success', 'title' => 'Send a booking link (remote walk-in)']) ?>
            <?php endforeach; ?>
            <?php if (!$bookable && !$waiting): ?>
                <span class="text-warning">No consultants are assigned to you yet. Ask an administrator.</span>
            <?php endif; ?>
        </div>
        <?php if ($waiting): ?>
            <p class="small text-muted" id="waiting-consultants">
                Invited to work here, not bookable yet: <?= Html::encode(implode(', ', array_map(function ($c) {
                    return $c->names;
                }, $waiting))) ?>. You can book for them once they accept and add their hours here.
            </p>
        <?php endif; ?>
        <?php if ($bookable && $stats): ?>
            <?= $this->render('@frontend/views/site/_clinic_stats', ['stats' => $stats, 'scopeLabel' => $facility ? 'your consultants at ' . $facility->name : 'your consultants']) ?>
        <?php endif; ?>
    <?php elseif ($facilityAdmin): ?>
        <p class="text-muted">
            Every consultant's appointments at <strong><?= Html::encode($facility->name ?? 'your facility') ?></strong>.
            Open one to see the details, cancel it with a note, or record whether the patient came.
        </p>
        <?php if ($facility && $stats): ?>
            <?= $this->render('@frontend/views/site/_clinic_stats', ['stats' => $stats, 'scopeLabel' => $facility->name]) ?>
            <?= $this->render('@frontend/views/site/_facility_report', ['stats' => $stats, 'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) Yii::$app->request->get('month')) ? Yii::$app->request->get('month') : date('Y-m'), 'facility' => $facility, 'route' => 'board/index']) ?>
        <?php elseif (!$facility): ?>
            <div class="alert alert-warning py-2">The facility you run has not been set. Ask a clinic administrator.</div>
        <?php endif; ?>
    <?php else: ?>
        <p class="text-muted">Every consultant's appointments. Open one to see the details, or cancel it with a note.</p>
    <?php endif; ?>

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
        <?php $facilityOptions = $search->facilityOptions(); ?>
        <div class="<?= $facilityOptions ? 'col-md-2' : 'col-md-3' ?>">
            <label class="form-label small" for="board-consultant">Consultant</label>
            <?= Html::dropDownList('consultant', $search->consultant, $search->consultantOptions(), ['class' => 'form-select form-select-sm', 'id' => 'board-consultant', 'prompt' => 'All consultants']) ?>
        </div>
        <?php if ($facilityOptions): ?>
            <div class="col-md-2">
                <label class="form-label small" for="board-facility">Facility</label>
                <?= Html::dropDownList('facility', $search->facility, $facilityOptions, ['class' => 'form-select form-select-sm', 'id' => 'board-facility', 'prompt' => 'All facilities']) ?>
            </div>
        <?php endif; ?>
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

    <?php if ($needsOutcome): ?>
        <div class="card border-warning mb-4 needs-outcome">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-1">Needs an outcome (<?= count($needsOutcome) ?>)</h2>
                <p class="small text-muted mb-2">Appointments from the last 30 days that have started but are not yet marked attended or no-show. Oldest first.</p>
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <?php foreach ($needsOutcome as $appointment): ?>
                            <tr>
                                <td class="text-nowrap"><?= Html::encode($f->asDatetime(strtotime($appointment->date . ' ' . $appointment->time), 'php:D j M, H:i')) ?></td>
                                <td><?= Html::encode($appointment->consultant->names ?? '') ?></td>
                                <td><?= Html::encode($appointment->patient->full_name ?? $appointment->patient->username ?? '') ?></td>
                                <td class="text-end">
                                    <?php if (\frontend\components\FrontDesk::canRecordOutcome($appointment)): ?>
                                        <?= $this->render('@frontend/views/appointments/_outcome_buttons', ['model' => $appointment]) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

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
                'label' => 'Facility',
                'format' => 'raw',
                'value' => function (Appointments $model) {
                    if (!$model->facility) {
                        return '';
                    }
                    return '<span class="d-inline-block rounded-circle me-1" style="width:.6rem;height:.6rem;background:' . Html::encode($model->facility->getColor()) . '"></span>'
                        . Html::encode($model->facility->name);
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
                    if ($model->booking_link_id) {
                        return 'Remote walk-in (link from ' . ($model->creator->full_name ?? $model->creator->username ?? 'staff') . ')';
                    }
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
                    // Started appointments: record or correct attended / no-show
                    if ($model->canRecordOutcome() && \frontend\components\FrontDesk::canRecordOutcome($model)) {
                        $buttons .= ' ' . $this->render('@frontend/views/appointments/_outcome_buttons', ['model' => $model]);
                    }
                    return $buttons;
                },
            ],
        ],
    ]) ?>

    <?= $this->render('@frontend/views/appointments/_cancel_modal') ?>

</div>
