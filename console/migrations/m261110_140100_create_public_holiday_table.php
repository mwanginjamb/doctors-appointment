<?php

use yii\db\Migration;

/**
 * Clinic-wide public holidays: nobody can be booked on these dates. Managed by admins.
 *
 * Seeds Kenya's fixed-date holidays and Good Friday / Easter Monday for 2026-2027, with the
 * following Monday as the holiday when one falls on a Sunday. Eid al-Fitr, Eid al-Adha and any
 * holiday gazetted during the year are added by an admin.
 */
class m261110_140100_create_public_holiday_table extends Migration
{
    const TABLE = '{{%public_holiday}}';

    const SEED = [
        '2026-01-01' => "New Year's Day",
        '2026-04-03' => 'Good Friday',
        '2026-04-06' => 'Easter Monday',
        '2026-05-01' => 'Labour Day',
        '2026-06-01' => 'Madaraka Day',
        '2026-10-20' => 'Mashujaa Day',
        '2026-12-12' => 'Jamhuri Day',
        '2026-12-25' => 'Christmas Day',
        '2026-12-26' => 'Boxing Day',
        '2027-01-01' => "New Year's Day",
        '2027-03-26' => 'Good Friday',
        '2027-03-29' => 'Easter Monday',
        '2027-05-01' => 'Labour Day',
        '2027-06-01' => 'Madaraka Day',
        '2027-10-20' => 'Mashujaa Day',
        '2027-12-13' => 'Jamhuri Day (observed; 12 Dec is a Sunday)',
        '2027-12-25' => 'Christmas Day',
        '2027-12-27' => 'Boxing Day (observed; 26 Dec is a Sunday)',
    ];

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'date' => $this->date()->notNull()->unique(),
            'name' => $this->string(150)->notNull(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);

        $now = time();
        $rows = [];
        foreach (self::SEED as $date => $name) {
            $rows[] = [$date, $name, $now, $now];
        }
        $this->batchInsert(self::TABLE, ['date', 'name', 'created_at', 'updated_at'], $rows);
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
