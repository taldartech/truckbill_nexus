<?php

namespace Database\Seeders;

/**
 * India States/UTs with ISO subdivision codes and GSTIN state codes.
 * District/city list sourced from legacy Broker india_states_districts.json.
 */
final class IndiaGeographyData
{
    /**
     * ISO 3166-2:IN (without IN-) => GSTIN numeric state code.
     *
     * @var array<string, array{name: string, gst_code: string}>
     */
    public const STATES = [
        'AN' => ['name' => 'Andaman and Nicobar Islands', 'gst_code' => '35'],
        'AP' => ['name' => 'Andhra Pradesh', 'gst_code' => '37'],
        'AR' => ['name' => 'Arunachal Pradesh', 'gst_code' => '12'],
        'AS' => ['name' => 'Assam', 'gst_code' => '18'],
        'BR' => ['name' => 'Bihar', 'gst_code' => '10'],
        'CH' => ['name' => 'Chandigarh', 'gst_code' => '04'],
        'CG' => ['name' => 'Chhattisgarh', 'gst_code' => '22'],
        'DH' => ['name' => 'Dadra and Nagar Haveli and Daman and Diu', 'gst_code' => '26'],
        'DL' => ['name' => 'Delhi', 'gst_code' => '07'],
        'GA' => ['name' => 'Goa', 'gst_code' => '30'],
        'GJ' => ['name' => 'Gujarat', 'gst_code' => '24'],
        'HR' => ['name' => 'Haryana', 'gst_code' => '06'],
        'HP' => ['name' => 'Himachal Pradesh', 'gst_code' => '02'],
        'JK' => ['name' => 'Jammu and Kashmir', 'gst_code' => '01'],
        'JH' => ['name' => 'Jharkhand', 'gst_code' => '20'],
        'KA' => ['name' => 'Karnataka', 'gst_code' => '29'],
        'KL' => ['name' => 'Kerala', 'gst_code' => '32'],
        'LA' => ['name' => 'Ladakh', 'gst_code' => '38'],
        'LD' => ['name' => 'Lakshadweep', 'gst_code' => '31'],
        'MP' => ['name' => 'Madhya Pradesh', 'gst_code' => '23'],
        'MH' => ['name' => 'Maharashtra', 'gst_code' => '27'],
        'MN' => ['name' => 'Manipur', 'gst_code' => '14'],
        'ML' => ['name' => 'Meghalaya', 'gst_code' => '17'],
        'MZ' => ['name' => 'Mizoram', 'gst_code' => '15'],
        'NL' => ['name' => 'Nagaland', 'gst_code' => '13'],
        'OR' => ['name' => 'Odisha', 'gst_code' => '21'],
        'PY' => ['name' => 'Puducherry', 'gst_code' => '34'],
        'PB' => ['name' => 'Punjab', 'gst_code' => '03'],
        'RJ' => ['name' => 'Rajasthan', 'gst_code' => '08'],
        'SK' => ['name' => 'Sikkim', 'gst_code' => '11'],
        'TN' => ['name' => 'Tamil Nadu', 'gst_code' => '33'],
        'TG' => ['name' => 'Telangana', 'gst_code' => '36'],
        'TR' => ['name' => 'Tripura', 'gst_code' => '16'],
        'UP' => ['name' => 'Uttar Pradesh', 'gst_code' => '09'],
        'UK' => ['name' => 'Uttarakhand', 'gst_code' => '05'],
        'WB' => ['name' => 'West Bengal', 'gst_code' => '19'],
    ];

    /**
     * Known historical aliases → canonical state name.
     *
     * @var array<string, string>
     */
    public const STATE_ALIASES = [
        'orissa' => 'Odisha',
        'pondicherry' => 'Puducherry',
        'puducherry (pondicherry)' => 'Puducherry',
        'uttaranchal' => 'Uttarakhand',
        'nct of delhi' => 'Delhi',
        'delhi ncr' => 'Delhi',
        'new delhi' => 'Delhi',
        'jammu & kashmir' => 'Jammu and Kashmir',
        'j&k' => 'Jammu and Kashmir',
        'dadra and nagar haveli' => 'Dadra and Nagar Haveli and Daman and Diu',
        'daman and diu' => 'Dadra and Nagar Haveli and Daman and Diu',
        'andaman & nicobar islands' => 'Andaman and Nicobar Islands',
        'andaman and nicobar' => 'Andaman and Nicobar Islands',
    ];

    /**
     * @return list<array{name: string, code: string, gst_code: string, cities: list<string>}>
     */
    public static function statesWithCities(): array
    {
        $path = database_path('data/india_states_districts.json');
        if (! is_readable($path)) {
            throw new \RuntimeException("India geo JSON missing or unreadable: {$path}");
        }

        /** @var array{states: list<array{state: string, districts: list<string>}>} $raw */
        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $byName = [];

        foreach ($raw['states'] as $row) {
            $name = (string) $row['state'];
            $districts = [];
            foreach ($row['districts'] as $district) {
                $districts[] = trim(html_entity_decode((string) $district, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $byName[$name] = array_values(array_unique($districts));
        }

        self::mergeDadraDamanDiu($byName);
        self::splitLadakhFromJammuKashmir($byName);
        self::renameUtLabels($byName);
        $byName['Andaman and Nicobar Islands'] = array_values(array_unique(array_merge(
            $byName['Andaman and Nicobar Islands'] ?? [],
            ['Nicobar', 'North and Middle Andaman', 'South Andaman'],
        )));
        $byName['Chandigarh'] = array_values(array_unique(array_merge(
            $byName['Chandigarh'] ?? [],
            ['Chandigarh'],
        )));
        $byName['Lakshadweep'] = array_values(array_unique(array_merge(
            $byName['Lakshadweep'] ?? [],
            ['Kavaratti', 'Agatti', 'Amini', 'Andrott', 'Minicoy'],
        )));
        $byName['Ladakh'] = array_values(array_unique(array_merge(
            $byName['Ladakh'] ?? [],
            ['Kargil', 'Leh'],
        )));

        $out = [];
        foreach (self::STATES as $code => $meta) {
            $name = $meta['name'];
            // Always emit every official State/UT even when the district JSON lacks rows.
            $out[] = [
                'name' => $name,
                'code' => $code,
                'gst_code' => $meta['gst_code'],
                'cities' => $byName[$name] ?? [],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $byName
     */
    private static function mergeDadraDamanDiu(array &$byName): void
    {
        $merged = [];
        foreach (['Dadra and Nagar Haveli', 'Daman and Diu', 'Dadra and Nagar Haveli and Daman and Diu'] as $key) {
            if (isset($byName[$key])) {
                $merged = array_merge($merged, $byName[$key]);
                unset($byName[$key]);
            }
        }
        if ($merged !== []) {
            $byName['Dadra and Nagar Haveli and Daman and Diu'] = array_values(array_unique($merged));
        }
    }

    /**
     * @param  array<string, list<string>>  $byName
     */
    private static function splitLadakhFromJammuKashmir(array &$byName): void
    {
        $ladakhDistricts = ['Kargil', 'Leh', 'Leh (Ladakh)'];
        if (! isset($byName['Jammu and Kashmir'])) {
            $byName['Ladakh'] = $ladakhDistricts;

            return;
        }

        $remaining = [];
        $ladakh = [];
        foreach ($byName['Jammu and Kashmir'] as $district) {
            if (in_array($district, $ladakhDistricts, true) || str_contains(mb_strtolower($district), 'leh')) {
                $ladakh[] = $district;
            } else {
                $remaining[] = $district;
            }
        }
        $byName['Jammu and Kashmir'] = $remaining;
        $byName['Ladakh'] = array_values(array_unique(array_merge($ladakhDistricts, $ladakh)));
    }

    /**
     * @param  array<string, list<string>>  $byName
     */
    private static function renameUtLabels(array &$byName): void
    {
        $renames = [
            'Orissa' => 'Odisha',
            'Pondicherry' => 'Puducherry',
            'Puducherry (UT)' => 'Puducherry',
            'Uttaranchal' => 'Uttarakhand',
            'NCT of Delhi' => 'Delhi',
            'Delhi (NCT)' => 'Delhi',
            'Chandigarh (UT)' => 'Chandigarh',
            'Dadra and Nagar Haveli (UT)' => 'Dadra and Nagar Haveli and Daman and Diu',
            'Daman and Diu (UT)' => 'Dadra and Nagar Haveli and Daman and Diu',
            'Lakshadweep (UT)' => 'Lakshadweep',
            'Andaman and Nicobar Islands (UT)' => 'Andaman and Nicobar Islands',
            'Andaman & Nicobar Islands' => 'Andaman and Nicobar Islands',
        ];
        foreach ($renames as $from => $to) {
            if (! isset($byName[$from])) {
                continue;
            }
            $byName[$to] = array_values(array_unique(array_merge($byName[$to] ?? [], $byName[$from])));
            unset($byName[$from]);
        }
    }
}
