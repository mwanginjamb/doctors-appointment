<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Facility $facility */
/** @var frontend\models\Consultant[] $here consultants who work at the facility */
/** @var frontend\models\FacilityInvitation[] $invitations waiting for the consultant */
/** @var string[] $others consultant id => name: those who can be invited */
/** @var array $keep route params that keep the facility (consultancy admins) */

$this->title = 'Consultants: ' . $facility->name;
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-consultants-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        The consultants who see patients at <?= Html::encode($facility->name) ?>. Invite one who already uses the app,
        or add a new consultant account that starts here. Each consultant sets their own hours, session length and fee
        here on their Availability page.
    </p>

    <p><?= Html::a('Add a new consultant', ['create'] + $keep, ['class' => 'btn btn-success']) ?></p>

    <table class="table table-sm align-middle" id="consultants-here">
        <thead><tr><th>Consultant</th><th>Email</th><th>Phone</th></tr></thead>
        <tbody>
            <?php foreach ($here as $consultant): ?>
                <tr>
                    <td><?= Html::encode($consultant->names) ?></td>
                    <td><?= Html::encode((string) $consultant->consultant_email) ?></td>
                    <td><?= Html::encode((string) $consultant->consultant_phone_number) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$here): ?>
                <tr><td colspan="3" class="text-muted">No consultants here yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($invitations): ?>
        <h2 class="h5 mt-4">Invited, not here yet</h2>
        <ul id="invited">
            <?php foreach ($invitations as $invitation): ?>
                <li><?= Html::encode($invitation->consultant->names ?? '') ?>
                    <span class="text-muted small">invited <?= Yii::$app->formatter->asDate($invitation->created_at, 'php:j M Y') ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2 class="h5 mt-4">Invite a consultant who works elsewhere</h2>
    <?php if ($others): ?>
        <?= Html::beginForm(['invite'] + $keep, 'post', ['class' => 'd-flex flex-wrap gap-2', 'id' => 'invite-form', 'style' => 'max-width: 36rem']) ?>
            <?= Html::dropDownList('consultant_id', null, $others, ['prompt' => 'Choose a consultant', 'class' => 'form-select w-auto flex-grow-1', 'aria-label' => 'Consultant']) ?>
            <?= Html::submitButton('Invite', ['class' => 'btn btn-outline-primary']) ?>
        <?= Html::endForm() ?>
        <p class="small text-muted mt-1">They are emailed and WhatsApped, and accept by adding <?= Html::encode($facility->name) ?> on their Availability page.</p>
    <?php else: ?>
        <p class="text-muted">Every consultant on the app already works here or is invited.</p>
    <?php endif; ?>

</div>
