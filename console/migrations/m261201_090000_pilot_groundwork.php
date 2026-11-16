<?php

use yii\db\Migration;

/**
 * F20, pilot groundwork (docs/business-case.md): what the 90-day pilot needs recorded from its first
 * booking, because month 1 is each consultant's no-show baseline.
 *
 * - appointments.booking_channel: online, link, staff or walk_in, set by BookingService::book()
 * - appointments.cancelled_*: who cancelled, on whose side, and whether the patient asked
 * - consultant.first_booking_at: the start of the consultant's pilot, set once (left empty for
 *   consultants with bookings from before; their pilot starts at their next booking)
 * - facility_agreement: the agreement each facility signed
 */
class m261201_090000_pilot_groundwork extends Migration
{
    const AGREEMENT = '{{%facility_agreement}}';

    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'booking_channel', $this->string(10)->null()->comment('online, link, staff or walk_in; null before F20'));
        $this->addColumn('{{%appointments}}', 'cancelled_by', $this->integer()->null()->comment('user.id; null for a guest (manage link)'));
        $this->addColumn('{{%appointments}}', 'cancelled_party', $this->string(12)->null()->comment('patient, consultant, front_desk, facility or system'));
        $this->addColumn('{{%appointments}}', 'cancelled_at_patient_request', $this->boolean()->null());
        $this->addColumn('{{%appointments}}', 'cancelled_at', $this->integer()->null());
        $this->createIndex('idx-appointments-consultant-channel', '{{%appointments}}', ['consultant_id', 'booking_channel']);

        $this->addColumn('{{%consultant}}', 'first_booking_at', $this->integer()->null()->comment('start of the pilot: the first booking with them, set once'));

        $this->createTable(self::AGREEMENT, [
            'id' => $this->primaryKey(),
            'facility_id' => $this->integer()->notNull(),
            'version' => $this->string(10)->notNull()->comment('e.g. 1.2'),
            'signed_on' => $this->date()->notNull(),
            'facility_signatory' => $this->string(150)->notNull()->comment('name and title'),
            'platform_signatory' => $this->string(150)->notNull(),
            'walk_in_exempt' => $this->boolean()->notNull()->defaultValue(true)->comment('same-day walk-ins at reception pay no commitment fee'),
            'standard_terms_confirmed_at' => $this->integer()->null()->comment('a platform admin confirmed the move to standard terms (day 91)'),
            'ended_on' => $this->date()->null()->comment('the facility left'),
            'notes' => $this->text()->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('idx-facility_agreement-facility', self::AGREEMENT, ['facility_id', 'signed_on']);
        $this->addForeignKey('fk-facility_agreement-facility_id', self::AGREEMENT, 'facility_id', '{{%facility}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::AGREEMENT);
        $this->dropColumn('{{%consultant}}', 'first_booking_at');
        $this->dropIndex('idx-appointments-consultant-channel', '{{%appointments}}');
        foreach (['cancelled_at', 'cancelled_at_patient_request', 'cancelled_party', 'cancelled_by', 'booking_channel'] as $column) {
            $this->dropColumn('{{%appointments}}', $column);
        }
    }
}
