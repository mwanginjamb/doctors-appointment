<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Seeds the `{{%provider}}` table (insurers, F18) with health cover providers patients in Kenya use:
 * "None" (no cover; Insurance::insurers() leaves it out of insured bookings), the public Social
 * Health Authority, the IRA-licensed insurers that underwrite medical cover, and the international
 * insurers expatriates and some employers use.
 *
 * Licences change (mergers, rebrands, statutory management): check the list against the Insurance
 * Regulatory Authority's current register (ira.go.ke) and edit it under Providers. NHIF is not
 * seeded: SHA replaced it on 1 October 2024.
 *
 * Names that already exist (case-insensitive) are skipped, so admin-entered rows are kept. Seeded
 * rows have created_by = NULL; safeDown() removes only those that no booking and no consultant's
 * accepted covers refer to.
 */
class m261221_090100_seed_kenya_insurers extends Migration
{
    public function safeUp()
    {
        $existing = array_map('mb_strtolower', (new Query())->select('provider')->from('{{%provider}}')
            ->where(['not', ['provider' => null]])->column($this->db));

        $now = time();
        $rows = [];
        foreach (self::insurers() as $name) {
            if (!in_array(mb_strtolower($name), $existing, true)) {
                $rows[] = [$name, $now, $now, null, null];
            }
        }
        if ($rows) {
            $this->batchInsert('{{%provider}}', ['provider', 'created_at', 'updated_at', 'created_by', 'updated_by'], $rows);
        }
        echo '    > seeded ' . count($rows) . ' insurer(s), skipped ' . (count(self::insurers()) - count($rows)) . " existing\n";
    }

    public function safeDown()
    {
        $inUse = (new Query())->select('insurer_id')->distinct()->from('{{%appointments}}')
            ->where(['not', ['insurer_id' => null]])->column($this->db);
        foreach ((new Query())->select('covers_supported')->from('{{%consultant}}')
            ->where(['not', ['covers_supported' => null]])->column($this->db) as $covers) {
            $inUse = array_merge($inUse, array_filter(array_map('intval', explode(',', (string) $covers))));
        }

        $this->delete('{{%provider}}', ['and',
            ['provider' => self::insurers(), 'created_by' => null],
            ['not in', 'id', $inUse ?: [0]],
        ]);
    }

    /** @return string[] */
    private static function insurers()
    {
        return [
            // ── No cover: for profiles and consultants' accepted covers; not offered on insured bookings ──
            'None',

            // ── Public scheme ──
            'Social Health Authority (SHA)',

            // ── Kenyan insurers with medical cover ──
            'AAR Insurance Kenya',
            'Allianz Insurance Company of Kenya',
            'APA Insurance',
            'Britam General Insurance',
            'CIC General Insurance',
            'Corporate Insurance Company',
            'Equity General Insurance (EGIA)',
            'Fidelity Shield Insurance',
            'First Assurance',
            'GA Insurance',
            'Geminia Insurance',
            'Heritage Insurance',
            'ICEA LION General Insurance',
            'Intra Africa Assurance',
            'Jubilee Health Insurance',
            'Kenindia Assurance',
            'Kenya Orient Insurance',
            'Madison General Insurance',
            'Mayfair Insurance',
            'Monarch Insurance',
            'MUA Insurance Kenya',
            'Occidental Insurance',
            'Old Mutual General Insurance (formerly UAP)',
            'Pacis Insurance',
            'Sanlam General Insurance',
            'Takaful Insurance of Africa',
            'The Kenyan Alliance Insurance',
            'Trident Insurance',
            'Xplico Insurance',

            // ── International (expatriate and employer cover) ──
            'Aetna International',
            'Allianz Care',
            'AXA Global Healthcare',
            'Bupa Global',
            'Cigna Global Health',
            'Now Health International',
        ];
    }
}
