<?php

use frontend\services\Ratings;
use yii\bootstrap5\Html;
use yii\grid\GridView;

/**
 * F30: patients' ratings in /admin: per consultant, then every rating with who gave it.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant[] $consultants with ratings
 * @var frontend\models\Consultant|null $chosen
 * @var array[] $summaries each ['consultant', 'summary' => Ratings::summary()]
 * @var yii\data\ActiveDataProvider $provider ratings, newest first
 */

$this->title = 'Ratings';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="ratings-admin py-4">
    <h1 class="h3"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Patients rate a visit 1–5 after it is recorded as attended. Consultants see their ratings without patient names; an average
        shows on a consultant's public profile from <?= Ratings::PUBLIC_MIN ?> ratings. Comments are never published.
    </p>

    <table class="table table-sm align-middle" id="ratings-by-consultant" style="max-width: 40rem">
        <thead><tr><th>Consultant</th><th class="text-end">Average</th><th class="text-end">Ratings</th><th class="text-end">Public</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($summaries as $row): ?>
            <?php $s = $row['summary']; ?>
            <tr>
                <td><?= Html::encode($row['consultant']->names) ?></td>
                <td class="text-end"><?= $s['average'] === null ? '—' : number_format($s['average'], 1) ?></td>
                <td class="text-end"><?= $s['count'] ?></td>
                <td class="text-end"><?= $s['count'] >= Ratings::PUBLIC_MIN ? 'Yes' : 'Not yet' ?></td>
                <td class="text-end"><?= Html::a('Ratings', ['index', 'consultant' => $row['consultant']->id]) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$summaries): ?><tr><td colspan="5" class="text-muted">No ratings yet.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h2 class="h5 mt-4"><?= $chosen ? Html::encode($chosen->names) . ' · ' . Html::a('all', ['index'], ['class' => 'small']) : 'All ratings' ?></h2>
    <?= GridView::widget([
        'dataProvider' => $provider,
        'id' => 'ratings-grid',
        'columns' => [
            ['label' => 'Rated', 'value' => function ($r) { return date('j M Y', (int) $r->created_at); }],
            ['label' => 'Stars', 'format' => 'raw', 'value' => function ($r) {
                return '<span class="text-warning">' . str_repeat('★', (int) $r->stars) . '</span>' . ($r->withdrawn ? ' <span class="badge text-bg-light border">withdrawn (no-show)</span>' : '');
            }],
            ['label' => 'Consultant', 'value' => function ($r) { return $r->consultant->names ?? ''; }],
            ['label' => 'Patient', 'value' => function ($r) { return $r->appointment->patient->full_name ?? ''; }],
            ['label' => 'Visit', 'value' => function ($r) {
                return $r->appointment ? date('j M Y', strtotime($r->appointment->date)) . ($r->appointment->facility ? ' · ' . $r->appointment->facility->name : '') : '';
            }],
            ['label' => 'Comment', 'value' => function ($r) { return $r->comment; }],
        ],
    ]) ?>
</div>
