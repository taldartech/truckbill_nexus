<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Party;
use App\Models\State;
use App\Models\Station;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Seeds India State/City masters and optionally links deterministic historical text.
 */
class IndiaGeographySeeder extends Seeder
{
    public function run(): void
    {
        $report = [
            'states_upserted' => 0,
            'cities_upserted' => 0,
            'matched' => [],
            'unmatched' => [],
            'ambiguous' => [],
        ];

        foreach (IndiaGeographyData::statesWithCities() as $row) {
            $state = State::query()->updateOrCreate(
                [
                    'country_code' => 'IN',
                    'code' => $row['code'],
                ],
                [
                    'name' => $row['name'],
                    'gst_code' => $row['gst_code'],
                    'is_active' => true,
                ],
            );
            $report['states_upserted']++;

            foreach ($row['cities'] as $cityName) {
                $normalized = City::normalizeName($cityName);
                City::query()->updateOrCreate(
                    [
                        'state_id' => $state->id,
                        'name_normalized' => $normalized,
                    ],
                    [
                        'name' => $cityName,
                        'is_active' => true,
                    ],
                );
                $report['cities_upserted']++;
            }
        }

        $this->linkHistoricalRecords($report);
        $this->writeReport($report);

        $this->command?->info(sprintf(
            'India geography: %d states, %d city rows. Matched=%d unmatched=%d ambiguous=%d. Report: storage/app/geography-migration-report.json',
            $report['states_upserted'],
            $report['cities_upserted'],
            count($report['matched']),
            count($report['unmatched']),
            count($report['ambiguous']),
        ));
    }

    /**
     * @param  array{
     *     states_upserted: int,
     *     cities_upserted: int,
     *     matched: list<array<string, mixed>>,
     *     unmatched: list<array<string, mixed>>,
     *     ambiguous: list<array<string, mixed>>
     * }  $report
     */
    private function linkHistoricalRecords(array &$report): void
    {
        $statesByName = State::query()
            ->where('country_code', 'IN')
            ->get()
            ->keyBy(fn (State $state): string => City::normalizeName($state->name));

        foreach (IndiaGeographyData::STATE_ALIASES as $alias => $canonical) {
            $canonicalKey = City::normalizeName($canonical);
            if ($statesByName->has($canonicalKey) && ! $statesByName->has($alias)) {
                $statesByName[$alias] = $statesByName[$canonicalKey];
            }
        }

        $tables = [
            ['model' => Company::class, 'label' => 'companies'],
            ['model' => Branch::class, 'label' => 'branches'],
            ['model' => Party::class, 'label' => 'parties'],
            ['model' => Station::class, 'label' => 'stations'],
        ];

        foreach ($tables as $table) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $table['model'];
            $modelClass::query()
                ->whereNull('state_id')
                ->whereNotNull('state')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($statesByName, $table, &$report): void {
                    foreach ($rows as $row) {
                        $rawState = trim((string) $row->getAttribute('state'));
                        if ($rawState === '') {
                            continue;
                        }

                        $key = City::normalizeName($rawState);
                        $state = $statesByName->get($key);
                        if ($state === null) {
                            $report['unmatched'][] = [
                                'table' => $table['label'],
                                'id' => $row->getKey(),
                                'state' => $rawState,
                                'city' => $row->getAttribute('city'),
                            ];

                            continue;
                        }

                        $cityId = null;
                        $rawCity = trim((string) ($row->getAttribute('city') ?? ''));
                        if ($rawCity !== '') {
                            $cityMatches = City::query()
                                ->where('state_id', $state->id)
                                ->where('name_normalized', City::normalizeName($rawCity))
                                ->where('is_active', true)
                                ->get();

                            if ($cityMatches->count() === 1) {
                                $cityId = $cityMatches->first()?->id;
                            } elseif ($cityMatches->count() > 1) {
                                $report['ambiguous'][] = [
                                    'table' => $table['label'],
                                    'id' => $row->getKey(),
                                    'state' => $rawState,
                                    'city' => $rawCity,
                                ];
                            } else {
                                $report['unmatched'][] = [
                                    'table' => $table['label'],
                                    'id' => $row->getKey(),
                                    'state' => $rawState,
                                    'city' => $rawCity,
                                    'note' => 'state_matched_city_unmatched',
                                ];
                            }
                        }

                        DB::table($table['label'])
                            ->where('id', $row->getKey())
                            ->update([
                                'state_id' => $state->id,
                                'city_id' => $cityId,
                                'updated_at' => now(),
                            ]);

                        $report['matched'][] = [
                            'table' => $table['label'],
                            'id' => $row->getKey(),
                            'state_id' => $state->id,
                            'city_id' => $cityId,
                        ];
                    }
                });
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report): void
    {
        $path = storage_path('app/geography-migration-report.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'states_upserted' => $report['states_upserted'],
            'cities_upserted' => $report['cities_upserted'],
            'matched_count' => count($report['matched']),
            'unmatched_count' => count($report['unmatched']),
            'ambiguous_count' => count($report['ambiguous']),
            'unmatched' => $report['unmatched'],
            'ambiguous' => $report['ambiguous'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
