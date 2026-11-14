<?php

use frontend\models\WaitlistEntry;
use yii\bootstrap5\Html;

/**
 * The signed-in patient's waiting lists.
 *
 * @var yii\web\View $this
 * @var WaitlistEntry[] $entries
 */

$this->title = 'My waiting list';
$this->params['breadcrumbs'][] = $this->title;
$labels = WaitlistEntry::statusLabels();
?>
<div class="waitlist-mine my-4">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        To join, open a doctor's calendar and choose <em>Notify me when a time frees up</em>, or open one of your
        appointments and choose <em>Tell me if something earlier opens</em>.
    </p>
    <?php if (!$entries): ?>
        <p id="waitlist-empty">You are not on any waiting list.</p>
    <?php else: ?>
        <div class="list-group" id="waitlist-entries">
            <?php foreach ($entries as $entry): ?>
                <?= Html::a(
                    '<div class="d-flex justify-content-between gap-2"><span class="fw-semibold">' . Html::encode($entry->consultant->names ?? '') . '</span>'
                        . '<span class="badge ' . ($entry->isWaiting() ? 'text-bg-primary' : 'text-bg-secondary') . '">' . Html::encode($labels[$entry->status] ?? $entry->status) . '</span></div>'
                        . '<div class="small text-muted">' . Html::encode($entry->getWindowLabel()) . ' · ' . Html::encode($entry->facility->name ?? '') . '</div>',
                    ['offer', 'id' => $entry->id, 'sig' => $entry->signature()],
                    ['class' => 'list-group-item list-group-item-action']
                ) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
