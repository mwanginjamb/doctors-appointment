<?php

use frontend\services\Ratings;
use yii\bootstrap5\Html;
use yii\widgets\ListView;

/**
 * F30: My ratings, for a consultant: anonymous (no patient names).
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant|null $consultant
 * @var array|null $summary Ratings::summary()
 * @var yii\data\ActiveDataProvider $provider their ratings, newest first
 */

$this->title = Yii::t('app', 'My ratings');
?>
<div class="my-ratings container my-4" style="max-width: 46rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        <?= Yii::t('app', 'Patients rate a visit from 1 to 5 after it is recorded as attended. You see their stars and comments, not who they are. Your average shows on your public profile once you have {n} ratings; comments are never published.', ['n' => Ratings::PUBLIC_MIN]) ?>
    </p>
    <?= $this->render('_summary', ['summary' => $summary]) ?>
    <?= ListView::widget([
        'dataProvider' => $provider,
        'id' => 'ratings-list',
        'layout' => "{items}\n{pager}",
        'emptyText' => '',
        'itemView' => function ($rating) {
            /** @var frontend\models\ConsultantRating $rating */
            $facility = $rating->appointment->facility->name ?? null;

            return '<div class="border-bottom py-2 rating-item">'
                . '<span class="text-warning">' . str_repeat('★', (int) $rating->stars) . str_repeat('☆', 5 - (int) $rating->stars) . '</span>'
                . ' <span class="small text-muted">' . Html::encode(date('j M Y', strtotime($rating->appointment->date ?? 'now')) . ($facility ? ' · ' . $facility : '')) . '</span>'
                . ($rating->comment ? '<div class="mt-1">' . nl2br(Html::encode($rating->comment)) . '</div>' : '')
                . '</div>';
        },
    ]) ?>
</div>
