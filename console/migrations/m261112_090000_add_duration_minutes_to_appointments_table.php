<?php

use yii\db\Migration;

/**
 * Each appointment keeps the session length it was booked with. Before this, every check worked
 * out an appointment's length from the consultant's current setting, so changing the setting
 * silently changed every existing booking.
 *
 * Existing rows get the length they were actually booked at: the consultant's setting where one
 * was stored, otherwise the DURATION env value (seconds; 30 minutes by default).
 */
class m261112_090000_add_duration_minutes_to_appointments_table extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'duration_minutes', $this->smallInteger()->null()->after('time'));

        $seconds = function_exists('env') ? env('DURATION', 1800) : (getenv('DURATION') ?: 1800);
        $default = max(5, (int) round((int) $seconds / 60));

        $this->execute(
            'UPDATE {{%appointments}} a LEFT JOIN {{%consultant}} c ON c.user_id = a.consultant_id
             SET a.duration_minutes = COALESCE(NULLIF(c.appointment_session_duration, 0), :default)',
            [':default' => $default]
        );
        echo "    > existing appointments filled in; the default length was {$default} minutes\n";

        $this->alterColumn('{{%appointments}}', 'duration_minutes', $this->smallInteger()->notNull()->defaultValue($default));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%appointments}}', 'duration_minutes');
    }
}
