<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * F8: lists well-known Kenyan hospitals as facilities, so most consultants can pick theirs instead
 * of requesting it, and adds `facility.ownership` (public, private, faith_based).
 *
 * Source: Wikipedia, "List of hospitals in Kenya" (revision 1379050607, read 11 Nov 2026), checked
 * against general knowledge; only established hospitals are included. Addresses are area, town and
 * county only: phone numbers and map links are left for admins to add, since they reach patients.
 * Ownership is set where the name or the hospital's well-known status makes it clear, else null.
 *
 * A facility already listed under the same name (ignoring case, "The" and punctuation) is skipped.
 * safeDown() removes the seeded facilities nobody has used, and the column.
 */
class m261124_090000_seed_kenya_facilities extends Migration
{
    const OWN_PUBLIC = 'public';
    const OWN_PRIVATE = 'private';
    const OWN_FAITH = 'faith_based';

    /** name => [address, ownership] */
    const FACILITIES = [
        // ---- Nairobi County ----
        'Kenyatta National Hospital' => ['Hospital Rd, Upper Hill, Nairobi', self::OWN_PUBLIC],
        'Kenyatta University Teaching, Referral and Research Hospital' => ['Kahawa West, Nairobi', self::OWN_PUBLIC],
        'Mathari National Teaching and Referral Hospital' => ['Mathare, Nairobi', self::OWN_PUBLIC],
        'National Spinal Injury Referral Hospital' => ['Kilimani, Nairobi', self::OWN_PUBLIC],
        'Mbagathi County Referral Hospital' => ['Golf Course, Nairobi', self::OWN_PUBLIC],
        'Mama Lucy Kibaki Hospital' => ['Umoja, Nairobi', self::OWN_PUBLIC],
        'Pumwani Maternity Hospital' => ['Pumwani, Nairobi', self::OWN_PUBLIC],
        'The Nairobi Hospital' => ['Argwings Kodhek Rd, Upper Hill, Nairobi', self::OWN_PRIVATE],
        'Aga Khan University Hospital, Nairobi' => ['3rd Parklands Ave, Parklands, Nairobi', self::OWN_PRIVATE],
        'MP Shah Hospital' => ['Shivachi Rd, Parklands, Nairobi', self::OWN_PRIVATE],
        'Avenue Hospital' => ['1st Parklands Ave, Parklands, Nairobi', self::OWN_PRIVATE],
        'The Karen Hospital' => ['Langata Rd, Karen, Nairobi', self::OWN_PRIVATE],
        "Gertrude's Children's Hospital" => ['Muthaiga Rd, Muthaiga, Nairobi', self::OWN_PRIVATE],
        'The Nairobi West Hospital' => ['Nairobi West, Nairobi', self::OWN_PRIVATE],
        "The Nairobi Women's Hospital, Hurlingham" => ['Hurlingham, Nairobi', self::OWN_PRIVATE],
        'AAR Hospital' => ['Nairobi', self::OWN_PRIVATE],
        'Metropolitan Hospital' => ['Buruburu, Nairobi', self::OWN_PRIVATE],
        'Nairobi East Hospital' => ['Eastleigh, Nairobi', self::OWN_PRIVATE],
        'The Nairobi South Hospital' => ['South C, Nairobi', self::OWN_PRIVATE],
        'South B Hospital' => ['South B, Nairobi', self::OWN_PRIVATE],
        'Mariakani Cottage Hospital' => ['South B, Nairobi', self::OWN_PRIVATE],
        'Mediheal Hospital, Eastleigh' => ['Eastleigh, Nairobi', self::OWN_PRIVATE],
        'Meridian Equator Hospital' => ['Nairobi', self::OWN_PRIVATE],
        'Bristol Park Hospital, Tasia' => ['Tasia, Embakasi, Nairobi', self::OWN_PRIVATE],
        'Mater Misericordiae Hospital' => ['Dunga Rd, Industrial Area, Nairobi', self::OWN_FAITH],
        "St. Mary's Mission Hospital, Nairobi" => ["Lang'ata, Nairobi", self::OWN_FAITH],
        'Coptic Hospital' => ['Ngong Rd, Nairobi', self::OWN_FAITH],
        'Guru Nanak Ramgarhia Sikh Hospital' => ['Parklands, Nairobi', self::OWN_FAITH],

        // ---- Former Central Province ----
        'Thika Level 5 Hospital' => ['Thika, Kiambu County', self::OWN_PUBLIC],
        'Gatundu Level 5 Hospital' => ['Gatundu, Kiambu County', self::OWN_PUBLIC],
        'Kiambu County Referral Hospital' => ['Kiambu, Kiambu County', self::OWN_PUBLIC],
        'Nyeri County Referral Hospital' => ['Nyeri, Nyeri County', self::OWN_PUBLIC],
        'Mwai Kibaki Teaching and Referral Hospital' => ['Othaya, Nyeri County', self::OWN_PUBLIC],
        'Kerugoya County Referral Hospital' => ['Kerugoya, Kirinyaga County', self::OWN_PUBLIC],
        "Murang'a County Referral Hospital" => ["Murang'a, Murang'a County", self::OWN_PUBLIC],
        'The Outspan Hospital' => ['Nyeri, Nyeri County', self::OWN_PRIVATE],
        'Thika Nursing Home' => ['Thika, Kiambu County', self::OWN_PRIVATE],
        'AIC Kijabe Hospital' => ['Kijabe, Kiambu County', self::OWN_FAITH],
        'AIC CURE International Children\'s Hospital' => ['Kijabe, Kiambu County', self::OWN_FAITH],
        'PCEA Kikuyu Hospital' => ['Kikuyu, Kiambu County', self::OWN_FAITH],
        'PCEA Tumutumu Hospital' => ['Karatina, Nyeri County', self::OWN_FAITH],
        'Consolata Hospital Mathari' => ['Nyeri, Nyeri County', self::OWN_FAITH],
        'Nazareth Hospital' => ['Limuru, Kiambu County', self::OWN_FAITH],
        'North Kinangop Catholic Hospital' => ['North Kinangop, Nyandarua County', self::OWN_FAITH],

        // ---- Coast ----
        'Coast General Teaching and Referral Hospital' => ['Mombasa, Mombasa County', self::OWN_PUBLIC],
        'Aga Khan Hospital, Mombasa' => ['Mombasa, Mombasa County', self::OWN_PRIVATE],
        'The Mombasa Hospital' => ['Mombasa, Mombasa County', self::OWN_PRIVATE],
        'Pandya Memorial Hospital' => ['Mombasa, Mombasa County', self::OWN_PRIVATE],
        'Bomu Hospital' => ['Changamwe, Mombasa County', self::OWN_PRIVATE],
        'Diani Beach Hospital' => ['Diani, Kwale County', self::OWN_PRIVATE],
        'MEWA Hospital' => ['Mombasa, Mombasa County', self::OWN_FAITH],

        // ---- Former Eastern Province ----
        'Machakos Level 5 Hospital' => ['Machakos, Machakos County', self::OWN_PUBLIC],
        'Embu Level 5 Hospital' => ['Embu, Embu County', self::OWN_PUBLIC],
        'Meru Teaching and Referral Hospital' => ['Meru, Meru County', self::OWN_PUBLIC],
        'Kitui County Referral Hospital' => ['Kitui, Kitui County', self::OWN_PUBLIC],
        'Makueni County Referral Hospital' => ['Wote, Makueni County', self::OWN_PUBLIC],
        'Consolata Hospital Nkubu' => ['Nkubu, Meru County', self::OWN_FAITH],
        'Maua Methodist Hospital' => ['Maua, Meru County', self::OWN_FAITH],
        'PCEA Chogoria Hospital' => ['Chogoria, Tharaka-Nithi County', self::OWN_FAITH],
        'Our Lady of Lourdes Mutomo Hospital' => ['Mutomo, Kitui County', self::OWN_FAITH],

        // ---- Former Nyanza Province ----
        'Jaramogi Oginga Odinga Teaching and Referral Hospital' => ['Kisumu, Kisumu County', self::OWN_PUBLIC],
        'Kisii Teaching and Referral Hospital' => ['Kisii, Kisii County', self::OWN_PUBLIC],
        'Aga Khan Hospital, Kisumu' => ['Kisumu, Kisumu County', self::OWN_PRIVATE],
        'Avenue Hospital, Kisumu' => ['Kisumu, Kisumu County', self::OWN_PRIVATE],
        'Bosongo Hospital' => ['Kisii, Kisii County', self::OWN_PRIVATE],
        "St. Joseph's Nyabondo Mission Hospital" => ['Nyabondo, Kisumu County', self::OWN_FAITH],
        'Kendu Adventist Hospital' => ['Kendu Bay, Homa Bay County', self::OWN_FAITH],

        // ---- Former Rift Valley Province ----
        'Moi Teaching and Referral Hospital' => ['Nandi Rd, Eldoret, Uasin Gishu County', self::OWN_PUBLIC],
        'Nakuru Level 6 Hospital' => ['Nakuru, Nakuru County', self::OWN_PUBLIC],
        'Nanyuki Teaching and Referral Hospital' => ['Nanyuki, Laikipia County', self::OWN_PUBLIC],
        'Narok County Referral Hospital' => ['Narok, Narok County', self::OWN_PUBLIC],
        'Nanyuki Cottage Hospital' => ['Nanyuki, Laikipia County', self::OWN_PRIVATE],
        'Mediheal Hospital, Eldoret' => ['Eldoret, Uasin Gishu County', self::OWN_PRIVATE],
        'Tenwek Hospital' => ['Bomet, Bomet County', self::OWN_FAITH],
        "St. Mary's Mission Hospital, Gilgil" => ['Gilgil, Nakuru County', self::OWN_FAITH],
        'Wamba Catholic Hospital' => ['Wamba, Samburu County', self::OWN_FAITH],

        // ---- Former Western Province ----
        'Kakamega County General Teaching and Referral Hospital' => ['Kakamega, Kakamega County', self::OWN_PUBLIC],
        'Bungoma County Referral Hospital' => ['Bungoma, Bungoma County', self::OWN_PUBLIC],
        'Busia County Referral Hospital' => ['Busia, Busia County', self::OWN_PUBLIC],
        'Vihiga County Referral Hospital' => ['Mbale, Vihiga County', self::OWN_PUBLIC],
        'Holy Family Nangina Mission Hospital' => ['Funyula, Busia County', self::OWN_FAITH],
        'Jumuia Friends Hospital Kaimosi' => ['Kaimosi, Vihiga County', self::OWN_FAITH],
    ];

    public function safeUp()
    {
        $this->addColumn('{{%facility}}', 'ownership', $this->string(20)->null()->after('map_url')->comment('public, private or faith_based'));

        $existing = [];
        foreach ((new Query())->select('name')->from('{{%facility}}')->column($this->db) as $name) {
            $existing[self::key($name)] = true;
        }

        $now = time();
        $rows = [];
        foreach (self::FACILITIES as $name => [$address, $ownership]) {
            if (isset($existing[self::key($name)])) {
                continue; // already listed (made from a consultant's profile, or added by an admin)
            }
            $existing[self::key($name)] = true;
            $rows[] = [$name, $address, $ownership, true, $now, $now];
        }
        if ($rows) {
            $this->batchInsert('{{%facility}}', ['name', 'address', 'ownership', 'active', 'created_at', 'updated_at'], $rows);
        }
        echo '    > listed ' . count($rows) . ' of ' . count(self::FACILITIES) . " Kenyan hospitals; the rest were already listed\n";
    }

    public function safeDown()
    {
        $unused = (new Query())->select('f.id')->from('{{%facility}} f')
            ->where(['f.name' => array_keys(self::FACILITIES)])
            ->andWhere(['not exists', (new Query())->from('{{%consultant_facility}}')->where('facility_id = f.id')])
            ->andWhere(['not exists', (new Query())->from('{{%consultant_working_hours}}')->where('facility_id = f.id')])
            ->andWhere(['not exists', (new Query())->from('{{%appointments}}')->where('facility_id = f.id')])
            ->andWhere(['not exists', (new Query())->from('{{%facility_request}}')->where('facility_id = f.id')])
            ->column($this->db);
        if ($unused) {
            $this->delete('{{%facility}}', ['id' => $unused]);
        }
        $this->dropColumn('{{%facility}}', 'ownership');
    }

    /** Same rule as Facility::nameKey(): case, a leading "The" and punctuation do not count. */
    private static function key($name)
    {
        $name = mb_strtolower(trim((string) $name));
        $name = preg_replace('/^the\s+/u', '', $name);
        $name = preg_replace("/['’]/u", '', $name); // St. Mary's = St Marys
        $name = preg_replace('/[.,()\-]+/u', ' ', $name);

        return trim(preg_replace('/\s+/u', ' ', $name));
    }
}
