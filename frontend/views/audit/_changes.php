<?php

use yii\helpers\Html;
use yii\helpers\Inflector;

/**
 * An audit entry's changes, as "Field: old → new" lines. Everything is escaped.
 *
 * @var common\models\AuditLog $entry
 */

$show = function ($value) {
    if ($value === null || $value === '') {
        return Html::tag('span', '—', ['class' => 'text-muted']);
    }
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return Html::encode((string) $value);
};
?>
<?php foreach ($entry->getChangeList() as $field => $change): ?>
    <?php [$old, $new] = array_pad((array) $change, 2, null); ?>
    <div class="small">
        <span class="fw-semibold"><?= Html::encode(Inflector::humanize($field)) ?>:</span>
        <?php if ($old === null || $old === ''): ?>
            <?= $show($new) ?>
        <?php else: ?>
            <?= $show($old) ?> → <?= $show($new) ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if ($entry->note !== null && $entry->note !== ''): ?>
    <div class="small text-muted"><?= Html::encode($entry->note) ?></div>
<?php endif; ?>
