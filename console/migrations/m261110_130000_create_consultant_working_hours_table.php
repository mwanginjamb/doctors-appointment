<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Weekly opening hours per consultant: one row per time range, several rows per day for breaks,
 * no rows for a closed day. Replaces consultant.working_start_time / working_end_time, which no
 * form ever set, and the free-text consultant.working_hours, which booking never read.
 *
 * Every existing consultant starts with Mon-Sat 08:00-13:00 and 14:00-17:00.
 */
class m261110_130000_create_consultant_working_hours_table extends Migration
{
    const TABLE = '{{%consultant_working_hours}}';

    /** ISO day numbers (1 = Monday ... 7 = Sunday) => ranges. Kept here so later code changes don't alter this migration. */
    const DEFAULT_WEEK = [
        1 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        2 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        3 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        4 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        5 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
        6 => [['08:00:00', '13:00:00'], ['14:00:00', '17:00:00']],
    ];

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'day_of_week' => $this->tinyInteger()->notNull()->comment('ISO: 1 = Monday ... 7 = Sunday'),
            'start_time' => $this->time()->notNull(),
            'end_time' => $this->time()->notNull(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
        ]);
        $this->createIndex('idx-consultant_working_hours-consultant-day', self::TABLE, ['consultant_id', 'day_of_week']);
        $this->addForeignKey(
            'fk-consultant_working_hours-consultant_id',
            self::TABLE,
            'consultant_id',
            '{{%consultant}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $now = time();
        $rows = [];
        foreach ((new Query())->select('id')->from('{{%consultant}}')->column($this->db) as $consultantId) {
            foreach (self::DEFAULT_WEEK as $day => $ranges) {
                foreach ($ranges as [$start, $end]) {
                    $rows[] = [$consultantId, $day, $start, $end, $now, $now];
                }
            }
        }
        if ($rows) {
            $this->batchInsert(self::TABLE, ['consultant_id', 'day_of_week', 'start_time', 'end_time', 'created_at', 'updated_at'], $rows);
        }
        echo '    > gave ' . (count($rows) / 12) . " consultant(s) the default week\n";
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
