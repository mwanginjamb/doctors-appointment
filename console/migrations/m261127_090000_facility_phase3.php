<?php

use yii\db\Migration;

/**
 * F8 phase 3: rotations, one-off sessions, external engagements and the calendar feed (F9).
 *
 * - Weekly hours can run every week, or only in week A or week B, and between dates. Week A is
 *   counted from `consultant.rotation_week_a` (the Monday of a week A).
 * - `consultant_session`: a dated session at a facility outside the weekly pattern (an outreach
 *   clinic on a Saturday). It is bookable like weekly hours.
 * - `consultant_external_block`: weekly hours the consultant works somewhere that does not use the
 *   app; nobody can book them then, and calendars show "busy at <place>".
 * - `consultant.calendar_feed_hash`: the hash of the secret in the consultant's iCal feed address.
 */
class m261127_090000_facility_phase3 extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%consultant_working_hours}}', 'weeks', $this->char(1)->null()->comment('A or B for alternate weeks; null = every week'));
        $this->addColumn('{{%consultant_working_hours}}', 'from_date', $this->date()->null()->comment('first day these hours run; null = already'));
        $this->addColumn('{{%consultant_working_hours}}', 'to_date', $this->date()->null()->comment('last day; null = open-ended'));
        $this->addColumn('{{%consultant}}', 'rotation_week_a', $this->date()->null()->comment('the Monday of a week A, for alternate-week hours'));
        $this->addColumn('{{%consultant}}', 'calendar_feed_hash', $this->string(64)->null()->comment('sha256 of the secret in the iCal feed address'));
        $this->createIndex('ux-consultant-calendar_feed_hash', '{{%consultant}}', 'calendar_feed_hash', true);

        $this->createTable('{{%consultant_session}}', [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'facility_id' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'start_time' => $this->time()->notNull(),
            'end_time' => $this->time()->notNull(),
            'note' => $this->string(150)->null()->comment('e.g. "Outreach clinic"; shown to staff'),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('idx-consultant_session-consultant-date', '{{%consultant_session}}', ['consultant_id', 'date']);
        $this->createIndex('idx-consultant_session-facility-date', '{{%consultant_session}}', ['facility_id', 'date']);
        $this->addForeignKey('fk-consultant_session-consultant_id', '{{%consultant_session}}', 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-consultant_session-facility_id', '{{%consultant_session}}', 'facility_id', '{{%facility}}', 'id', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%consultant_external_block}}', [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'place' => $this->string(150)->notNull()->comment('where they are, e.g. "Kenyatta National Hospital"'),
            'day_of_week' => $this->tinyInteger()->notNull()->comment('ISO: 1 = Monday'),
            'start_time' => $this->time()->notNull(),
            'end_time' => $this->time()->notNull(),
            'from_date' => $this->date()->null(),
            'to_date' => $this->date()->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('idx-consultant_external_block-consultant', '{{%consultant_external_block}}', ['consultant_id', 'day_of_week']);
        $this->addForeignKey('fk-consultant_external_block-consultant_id', '{{%consultant_external_block}}', 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%consultant_external_block}}');
        $this->dropTable('{{%consultant_session}}');
        $this->dropIndex('ux-consultant-calendar_feed_hash', '{{%consultant}}');
        $this->dropColumn('{{%consultant}}', 'calendar_feed_hash');
        $this->dropColumn('{{%consultant}}', 'rotation_week_a');
        $this->dropColumn('{{%consultant_working_hours}}', 'to_date');
        $this->dropColumn('{{%consultant_working_hours}}', 'from_date');
        $this->dropColumn('{{%consultant_working_hours}}', 'weeks');
    }
}
