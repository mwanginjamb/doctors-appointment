<?php

use common\models\AccountClosureRequest;
use yii\grid\GridView;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string $status */

$this->title = 'Account Closure Requests';
$this->params['breadcrumbs'][] = $this->title;
$filters = [AccountClosureRequest::STATUS_OPEN => 'Open', AccountClosureRequest::STATUS_CLOSED => 'Closed', AccountClosureRequest::STATUS_WITHDRAWN => 'Withdrawn by the patient', '' => 'All'];
?>
<div class="closure-request-index">
    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Patients who withdrew their consent to the clinic using their information, or asked for their
        data to be deleted. Their upcoming appointments were cancelled when they asked. Contact them if
        needed, then deactivate the account or delete their data: name, contacts, date of birth,
        symptoms and insurance details are removed; visit dates and payments stay, without the name.
        Download their data first if they asked for a copy. A patient who gives consent again withdraws
        their request.
    </p>

    <ul class="nav nav-pills mb-3">
        <?php foreach ($filters as $value => $label): ?>
            <li class="nav-item">
                <?= Html::a($label, ['index', 'status' => $value], ['class' => 'nav-link' . ((string) $status === (string) $value ? ' active' : '')]) ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table align-middle mb-0'],
                'emptyText' => 'No requests.',
                'columns' => [
                    [
                        'label' => 'Requested',
                        'value' => function (AccountClosureRequest $r) {
                            return date('Y-m-d H:i', $r->created_at);
                        },
                    ],
                    [
                        'label' => 'Patient',
                        'format' => 'raw',
                        'value' => function (AccountClosureRequest $r) {
                            if (!$r->user) {
                                return Html::tag('span', 'Account #' . (int) $r->user_id, ['class' => 'text-muted']);
                            }
                            $u = $r->user;
                            return Html::encode($u->full_name ?: $u->username)
                                . Html::tag('div', Html::encode(\common\helpers\Contact::summary($u->phone_number, $u->email)), ['class' => 'small text-muted']);
                        },
                    ],
                    [
                        'label' => 'Asked for',
                        'format' => 'raw',
                        'value' => function (AccountClosureRequest $r) {
                            return ($r->delete_data
                                ? Html::tag('span', 'Delete data', ['class' => 'badge bg-danger-subtle text-danger-emphasis'])
                                : Html::tag('span', 'Close account', ['class' => 'badge bg-secondary-subtle text-secondary-emphasis']))
                                . ($r->reason ? Html::tag('div', Html::encode($r->reason), ['class' => 'small mt-1']) : '');
                        },
                    ],
                    [
                        'label' => 'Status',
                        'format' => 'raw',
                        'value' => function (AccountClosureRequest $r) {
                            $out = Html::encode(ucfirst($r->status));
                            if ($r->handled_at) {
                                $by = $r->handler ? ' by ' . Html::encode($r->handler->full_name ?: $r->handler->username) : '';
                                $out .= Html::tag('div', date('Y-m-d', $r->handled_at) . $by, ['class' => 'small text-muted']);
                            }
                            if ($r->handled_note) {
                                $out .= Html::tag('div', Html::encode($r->handled_note), ['class' => 'small']);
                            }
                            return $out;
                        },
                    ],
                    [
                        'label' => '',
                        'format' => 'raw',
                        'contentOptions' => ['style' => 'min-width: 18rem'],
                        'value' => function (AccountClosureRequest $r) {
                            $data = $r->user && !$r->user->anonymised_at
                                ? Html::a('See data', ['data', 'id' => $r->id], ['class' => 'btn btn-sm btn-outline-secondary me-1 mb-2'])
                                    . Html::a('Download (JSON)', ['data', 'id' => $r->id, 'format' => 'json'], ['class' => 'btn btn-sm btn-outline-secondary mb-2'])
                                : '';
                            if ($r->status !== AccountClosureRequest::STATUS_OPEN) {
                                return $data;
                            }
                            return Html::tag('div', $data)
                                . Html::beginForm(['delete-data', 'id' => $r->id], 'post', ['class' => 'closure-delete-form mb-2'])
                                . Html::submitButton('Delete data and close', [
                                    'class' => 'btn btn-sm ' . ($r->delete_data ? 'btn-danger' : 'btn-outline-danger'),
                                    'data' => ['confirm' => 'Delete the personal data of this patient? Their name, contacts, date of birth, symptoms and insurance details are removed and the account is closed. This cannot be undone.'],
                                ])
                                . Html::endForm()
                                . Html::beginForm(['close', 'id' => $r->id], 'post', ['class' => 'closure-close-form'])
                                . Html::textInput('note', '', ['class' => 'form-control form-control-sm mb-2', 'placeholder' => 'Note (optional)', 'maxlength' => 400])
                                . Html::hiddenInput('deactivate', '1')
                                . Html::submitButton('Deactivate account and close', [
                                    'class' => 'btn btn-sm btn-outline-danger me-1',
                                    'data' => ['confirm' => 'Deactivate this account? The patient will no longer be able to sign in or be booked.'],
                                ])
                                . Html::endForm()
                                . Html::beginForm(['close', 'id' => $r->id], 'post', ['class' => 'mt-1'])
                                . Html::submitButton('Close without deactivating', ['class' => 'btn btn-sm btn-link px-0'])
                                . Html::endForm();
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>
</div>
