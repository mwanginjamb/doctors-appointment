<?php

use common\components\HealthCheck;
use common\models\HealthState;
use yii\bootstrap5\Html;

/**
 * System health (T2) on the admin dashboard: the latest results of `php yii health/check`, which
 * runs every 5 minutes. Read from health_state, so the dashboard never runs the checks itself.
 *
 * @var yii\web\View $this
 */

$states = HealthState::find()->indexBy('check_key')->all();
$lastRun = $states ? max(array_map(function ($s) {
    return (int) $s->checked_at;
}, $states)) : null;
// No run for 15 minutes: the health timer itself may be stopped
$stale = $lastRun !== null && $lastRun < time() - 15 * 60;
?>
<div class="card border-0 shadow-sm mb-4" id="system-health">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-baseline">
            <h2 class="h5 fw-bold mb-3">System health</h2>
            <span class="small text-muted">
                <?= $lastRun ? 'Checked ' . date('j M H:i', $lastRun) : '' ?>
            </span>
        </div>
        <?php if (!$states): ?>
            <p class="text-muted mb-0">
                No checks have run yet. They run every 5 minutes once <code>doctors-appointment-health.timer</code>
                is enabled (see Deploy steps in docs/FEATURES.md).
            </p>
        <?php else: ?>
            <?php if ($stale): ?>
                <div class="alert alert-warning py-2">The checks have not run for over 15 minutes: the health timer may be stopped.</div>
            <?php endif; ?>
            <ul class="list-unstyled mb-0">
                <?php foreach (HealthCheck::labels() as $key => $label): ?>
                    <?php $state = $states[$key] ?? null; ?>
                    <li class="d-flex gap-2 py-1 border-bottom">
                        <?php if ($state === null): ?>
                            <span class="badge text-bg-light">?</span>
                        <?php elseif ($state->failing): ?>
                            <span class="badge text-bg-danger">Problem</span>
                        <?php else: ?>
                            <span class="badge text-bg-success">OK</span>
                        <?php endif; ?>
                        <span>
                            <span class="fw-semibold"><?= Html::encode($label) ?></span>
                            <?php if ($state): ?>
                                <span class="small text-muted d-block">
                                    <?= Html::encode($state->message) ?>
                                    <?= $state->failing && $state->since ? ' Since ' . date('j M H:i', $state->since) . '.' : '' ?>
                                </span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
