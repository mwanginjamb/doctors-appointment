<?php

namespace console\controllers;

use common\components\Audit;
use common\models\AuditLog;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Audit log housekeeping.
 */
class AuditController extends Controller
{
    /** @var bool only count what would be removed */
    public $dryRun = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), $actionID === 'purge' ? ['dryRun'] : []);
    }

    public function optionAliases()
    {
        return ['n' => 'dryRun'];
    }

    /**
     * Removes audit entries older than the retention period (audit.retentionYears, default 7).
     * The removal itself is logged. Run daily by doctors-appointment-audit-purge.timer.
     *
     * Usage: php yii audit/purge [--dry-run]
     */
    public function actionPurge()
    {
        $years = AuditLog::retentionYears();
        $cutoff = strtotime("-{$years} years");
        $where = ['<', 'created_at', $cutoff];
        $count = (int) AuditLog::find()->where($where)->count();

        if ($this->dryRun) {
            $this->stdout("$count entries are older than $years years (before " . date('Y-m-d', $cutoff) . "). Nothing removed.\n");
            return ExitCode::OK;
        }
        if ($count === 0) {
            $this->stdout("Nothing older than $years years.\n");
            return ExitCode::OK;
        }

        // In batches, so a large first purge does not hold one long lock
        $removed = 0;
        $db = AuditLog::getDb();
        do {
            $ids = AuditLog::find()->select('id')->where($where)->orderBy('id')->limit(5000)->column();
            if ($ids) {
                $removed += $db->createCommand()->delete(AuditLog::tableName(), ['id' => $ids])->execute();
            }
        } while ($ids);

        Audit::log(AuditLog::ACTION_PURGED, 'audit_log', null, [], "$removed entries older than $years years (before " . date('Y-m-d', $cutoff) . ')');
        $this->stdout("Removed $removed entries older than $years years.\n");
        Yii::info("Audit purge removed $removed entries", __METHOD__);

        return ExitCode::OK;
    }
}
