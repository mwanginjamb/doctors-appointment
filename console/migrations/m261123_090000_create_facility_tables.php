<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * F8 phase 1: consultants engaged at several facilities.
 *
 * - `facility`: the places consultants see patients.
 * - `consultant_facility`: a consultant's engagement at a facility, with its own session length and
 *   fee, whether patients can book it online, and optional from/to dates.
 * - `facility_id` on weekly hours (each block of hours is at one facility), appointments (where it
 *   takes place, with the fee in force when booked) and booking links (the facility it offers).
 *
 * Existing data: one facility per distinct `consultant.facility` (else practice name, else
 * "Main clinic"); each consultant gets an engagement there with their current session length, and
 * their hours, appointments and booking links are attached to it. `consultant.facility` is kept as
 * a legacy column.
 */
class m261123_090000_create_facility_tables extends Migration
{
    const FALLBACK_NAME = 'Main clinic';

    public function safeUp()
    {
        $this->createTable('{{%facility}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(150)->notNull()->unique(),
            'address' => $this->text()->null(),
            'phone' => $this->string(20)->null(),
            'map_url' => $this->string(500)->null(),
            'active' => $this->boolean()->notNull()->defaultValue(true),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);

        $this->createTable('{{%consultant_facility}}', [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'facility_id' => $this->integer()->notNull(),
            'session_minutes' => $this->smallInteger()->notNull()->comment('length of new bookings here'),
            'fee' => $this->decimal(10, 2)->null()->comment('consultation fee here; null when not set'),
            'booking_open' => $this->boolean()->notNull()->defaultValue(true)->comment('patients can book themselves'),
            'from_date' => $this->date()->null()->comment('engagement starts; null = already'),
            'to_date' => $this->date()->null()->comment('last day; null = open-ended'),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('ux-consultant_facility-consultant-facility', '{{%consultant_facility}}', ['consultant_id', 'facility_id'], true);
        $this->createIndex('idx-consultant_facility-facility', '{{%consultant_facility}}', 'facility_id');
        $this->addForeignKey('fk-consultant_facility-consultant_id', '{{%consultant_facility}}', 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-consultant_facility-facility_id', '{{%consultant_facility}}', 'facility_id', '{{%facility}}', 'id', 'RESTRICT', 'CASCADE');

        $this->addColumn('{{%consultant_working_hours}}', 'facility_id', $this->integer()->null()->after('consultant_id'));
        $this->addColumn('{{%appointments}}', 'facility_id', $this->integer()->null()->after('consultant_id'));
        $this->addColumn('{{%appointments}}', 'fee', $this->decimal(10, 2)->null()->after('duration_minutes')->comment('fee in force when booked'));
        $this->addColumn('{{%booking_link}}', 'facility_id', $this->integer()->null()->after('consultant_id'));

        $this->backfill();

        $this->alterColumn('{{%consultant_working_hours}}', 'facility_id', $this->integer()->notNull());
        $this->createIndex('idx-consultant_working_hours-facility', '{{%consultant_working_hours}}', 'facility_id');
        $this->addForeignKey('fk-consultant_working_hours-facility_id', '{{%consultant_working_hours}}', 'facility_id', '{{%facility}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx-appointments-facility-date', '{{%appointments}}', ['facility_id', 'date']);
        $this->addForeignKey('fk-appointments-facility_id', '{{%appointments}}', 'facility_id', '{{%facility}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk-booking_link-facility_id', '{{%booking_link}}', 'facility_id', '{{%facility}}', 'id', 'SET NULL', 'CASCADE');
    }

    private function backfill()
    {
        $now = time();
        $default = (int) round((int) (getenv('DURATION') ?: 1800) / 60);
        $facilities = []; // lower-case name => id
        $engagements = 0;

        $consultants = (new Query())
            ->select(['id', 'user_id', 'facility', 'practice_name', 'physical_address', 'consultant_phone_number', 'appointment_session_duration'])
            ->from('{{%consultant}}')
            ->orderBy(['id' => SORT_ASC])
            ->all($this->db);

        foreach ($consultants as $c) {
            $name = trim((string) $c['facility']) ?: trim((string) $c['practice_name']) ?: self::FALLBACK_NAME;
            $name = mb_substr(preg_replace('/\s+/', ' ', $name), 0, 150);
            $key = mb_strtolower($name);
            if (!isset($facilities[$key])) {
                $this->insert('{{%facility}}', [
                    'name' => $name,
                    'address' => trim((string) $c['physical_address']) ?: null,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $facilities[$key] = (int) $this->db->getLastInsertID();
            }
            $facilityId = $facilities[$key];

            $this->insert('{{%consultant_facility}}', [
                'consultant_id' => $c['id'],
                'facility_id' => $facilityId,
                'session_minutes' => (int) $c['appointment_session_duration'] > 0 ? (int) $c['appointment_session_duration'] : max(5, $default),
                'fee' => null,
                'booking_open' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $engagements++;

            $this->update('{{%consultant_working_hours}}', ['facility_id' => $facilityId], ['consultant_id' => $c['id']]);
            $this->update('{{%booking_link}}', ['facility_id' => $facilityId], ['consultant_id' => $c['id']]);
            if ($c['user_id']) {
                $this->update('{{%appointments}}', ['facility_id' => $facilityId], ['consultant_id' => $c['user_id']]);
            }
        }

        // Hours of consultants that no longer exist would block the NOT NULL; they are orphans
        $this->delete('{{%consultant_working_hours}}', ['facility_id' => null]);

        echo '    > ' . count($facilities) . " facilit(y/ies) created; {$engagements} consultant(s) engaged there\n";
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-booking_link-facility_id', '{{%booking_link}}');
        $this->dropForeignKey('fk-appointments-facility_id', '{{%appointments}}');
        $this->dropIndex('idx-appointments-facility-date', '{{%appointments}}');
        $this->dropForeignKey('fk-consultant_working_hours-facility_id', '{{%consultant_working_hours}}');
        $this->dropIndex('idx-consultant_working_hours-facility', '{{%consultant_working_hours}}');

        $this->dropColumn('{{%booking_link}}', 'facility_id');
        $this->dropColumn('{{%appointments}}', 'fee');
        $this->dropColumn('{{%appointments}}', 'facility_id');
        $this->dropColumn('{{%consultant_working_hours}}', 'facility_id');

        $this->dropTable('{{%consultant_facility}}');
        $this->dropTable('{{%facility}}');
    }
}
