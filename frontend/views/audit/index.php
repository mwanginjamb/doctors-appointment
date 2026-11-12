<?php

use common\models\AuditLog;
use yii\grid\GridView;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\AuditLogSearch $search */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var int $retentionYears */
/** @var array $facilities facility id => name */

$this->title = 'Audit Log';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="audit-index">
    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Who changed what on appointments, accounts and the clinic's settings, and who signed in.
        Entries cannot be edited or deleted; they are kept for <?= (int) $retentionYears ?> years.
        Passwords and the details of symptoms are never recorded, only that they changed.
    </p>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <?= Html::beginForm(['index'], 'get', ['class' => 'row g-2 align-items-end']) ?>
            <div class="col-6 col-md-2">
                <?= Html::label('From', 'audit-from', ['class' => 'form-label']) ?>
                <?= Html::input('date', 'from', $search->from, ['id' => 'audit-from', 'class' => 'form-control']) ?>
            </div>
            <div class="col-6 col-md-2">
                <?= Html::label('To', 'audit-to', ['class' => 'form-label']) ?>
                <?= Html::input('date', 'to', $search->to, ['id' => 'audit-to', 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-2">
                <?= Html::label('Who (name)', 'audit-actor', ['class' => 'form-label']) ?>
                <?= Html::textInput('actor', $search->actor, ['id' => 'audit-actor', 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-2">
                <?= Html::label('Action', 'audit-action', ['class' => 'form-label']) ?>
                <?= Html::dropDownList('action', $search->action, AuditLog::actions(), ['id' => 'audit-action', 'class' => 'form-select', 'prompt' => 'Any']) ?>
            </div>
            <div class="col-6 col-md-2">
                <?= Html::label('Record', 'audit-entity', ['class' => 'form-label']) ?>
                <?= Html::dropDownList('entity_type', $search->entity_type, AuditLog::entityTypes(), ['id' => 'audit-entity', 'class' => 'form-select', 'prompt' => 'Any']) ?>
            </div>
            <div class="col-6 col-md-1">
                <?= Html::label('#', 'audit-entity-id', ['class' => 'form-label']) ?>
                <?= Html::textInput('entity_id', $search->entity_id, ['id' => 'audit-entity-id', 'class' => 'form-control']) ?>
            </div>
            <div class="col-md-1 d-flex gap-2">
                <?= Html::submitButton('Filter', ['class' => 'btn btn-primary flex-fill']) ?>
            </div>
            <div class="col-12">
                <?= Html::label('As', 'audit-actor-type', ['class' => 'form-label small me-2']) ?>
                <?= Html::dropDownList('actor_type', $search->actor_type, AuditLog::actorTypes(), ['id' => 'audit-actor-type', 'class' => 'form-select form-select-sm d-inline-block w-auto', 'prompt' => 'Anyone']) ?>
                <?= Html::label('Facility', 'audit-facility', ['class' => 'form-label small ms-3 me-2']) ?>
                <?= Html::dropDownList('facility', $search->facility, $facilities, ['id' => 'audit-facility', 'class' => 'form-select form-select-sm d-inline-block w-auto', 'prompt' => 'Any']) ?>
                <?= Html::a('Reset', ['index'], ['class' => 'btn btn-sm btn-link']) ?>
            </div>
            <?= Html::endForm() ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-hover align-middle mb-0'],
                'layout' => "<div class=\"table-responsive\">{items}</div>\n<div class=\"d-flex justify-content-between align-items-center mt-3\">{summary}{pager}</div>",
                'emptyText' => 'No entries match these filters.',
                'columns' => [
                    [
                        'label' => 'When',
                        'contentOptions' => ['class' => 'text-nowrap small'],
                        'value' => function (AuditLog $entry) {
                            return date('Y-m-d H:i:s', $entry->created_at);
                        },
                    ],
                    [
                        'label' => 'Who',
                        'format' => 'raw',
                        'value' => function (AuditLog $entry) {
                            $name = $entry->actor_name !== null ? Html::encode($entry->actor_name) : '';
                            $id = $entry->actor_id !== null ? Html::tag('span', ' #' . (int) $entry->actor_id, ['class' => 'text-muted small']) : '';
                            return $name . $id . Html::tag('div', Html::encode($entry->getActorTypeLabel()), ['class' => 'small text-muted']);
                        },
                    ],
                    [
                        'label' => 'Action',
                        'value' => function (AuditLog $entry) {
                            return $entry->getActionLabel();
                        },
                    ],
                    [
                        'label' => 'Record',
                        'format' => 'raw',
                        'value' => function (AuditLog $entry) use ($facilities) {
                            $label = Html::encode($entry->getEntityLabel());
                            $record = $entry->entity_type === 'appointment' && $entry->entity_id !== null
                                ? Html::a($label, ['/appointments/view', 'id' => $entry->entity_id])
                                : $label;
                            // F8 phase 2: where it happened
                            return $record . ($entry->facility_id && isset($facilities[$entry->facility_id])
                                ? Html::tag('div', Html::encode($facilities[$entry->facility_id]), ['class' => 'small text-muted'])
                                : '');
                        },
                    ],
                    [
                        'label' => 'Details',
                        'format' => 'raw',
                        'value' => function (AuditLog $entry) {
                            return $this->render('_changes', ['entry' => $entry]);
                        },
                    ],
                    [
                        'label' => 'IP',
                        'contentOptions' => ['class' => 'small text-muted'],
                        'value' => function (AuditLog $entry) {
                            return $entry->ip ?? '';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>
</div>
