<?php

namespace common\behaviors;

use common\components\Audit;
use common\models\AuditLog;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;

/**
 * Records an ActiveRecord's creation, changes and deletion in the audit log, with old and new
 * values of the fields that changed.
 *
 *   ['class' => AuditBehavior::class, 'entityType' => 'appointment', 'masked' => ['symptoms_brief']]
 *
 * Masked fields show that they changed but not their values; excluded fields are left out entirely.
 */
class AuditBehavior extends Behavior
{
    /** @var string how entries name this kind of record, e.g. 'appointment' */
    public $entityType;

    /** @var string[] fields logged as changed without their values (password hashes, health details) */
    public $masked = [];

    /** @var string[] fields never logged (keys, tokens, device ids) */
    public $exclude = [];

    /** @var string[] bookkeeping fields every model has; not worth an entry of their own */
    public $ignore = ['created_at', 'updated_at', 'created_by', 'updated_by'];

    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    public function afterInsert(AfterSaveEvent $event)
    {
        $changes = [];
        foreach ($this->owner->getAttributes() as $name => $value) {
            if ($this->isLogged($name) && !self::isBlank($value)) {
                $changes[$name] = [null, $this->value($name, $value)];
            }
        }
        Audit::log(AuditLog::ACTION_CREATED, $this->entityType, $this->owner->getPrimaryKey(), $changes);
    }

    public function afterUpdate(AfterSaveEvent $event)
    {
        $changes = [];
        foreach ($event->changedAttributes as $name => $old) {
            $new = $this->owner->getAttribute($name);
            // ActiveRecord marks '10' -> 10 and null -> '' as changes; they are not
            if (!$this->isLogged($name) || self::same($old, $new)) {
                continue;
            }
            $changes[$name] = [$this->value($name, $old), $this->value($name, $new)];
        }
        if ($changes) {
            Audit::log(AuditLog::ACTION_UPDATED, $this->entityType, $this->owner->getPrimaryKey(), $changes);
        }
    }

    public function afterDelete($event)
    {
        $changes = [];
        foreach ($this->owner->getOldAttributes() ?: $this->owner->getAttributes() as $name => $value) {
            if ($this->isLogged($name) && !self::isBlank($value)) {
                $changes[$name] = [$this->value($name, $value), null];
            }
        }
        Audit::log(AuditLog::ACTION_DELETED, $this->entityType, $this->owner->getPrimaryKey(), $changes);
    }

    private function isLogged($name)
    {
        return !in_array($name, $this->ignore, true) && !in_array($name, $this->exclude, true);
    }

    private function value($name, $value)
    {
        if (in_array($name, $this->masked, true)) {
            return self::isBlank($value) ? null : AuditLog::MASKED;
        }
        return $value;
    }

    private static function isBlank($value)
    {
        return $value === null || $value === '';
    }

    private static function same($a, $b)
    {
        if (self::isBlank($a) || self::isBlank($b)) {
            return self::isBlank($a) && self::isBlank($b);
        }
        return (string) $a === (string) $b;
    }
}
