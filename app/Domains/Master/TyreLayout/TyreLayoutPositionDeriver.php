<?php

namespace App\Domains\Master\TyreLayout;

use App\Domains\Master\TyreLayout\Enums\TyreAxleConfiguration;

final class TyreLayoutPositionDeriver
{
    /**
     * @param  list<array{sequence: int, configuration: TyreAxleConfiguration}>  $axles
     * @return list<array{
     *     position_key: string,
     *     axle_sequence: int,
     *     side: string,
     *     ring: ?string,
     *     label: string
     * }>
     */
    public function derive(array $axles): array
    {
        $positions = [];

        foreach ($axles as $axle) {
            $sequence = (int) $axle['sequence'];
            $prefix = 'A'.$sequence;

            if ($axle['configuration'] === TyreAxleConfiguration::Single) {
                $positions[] = [
                    'position_key' => "{$prefix}-L",
                    'axle_sequence' => $sequence,
                    'side' => 'L',
                    'ring' => null,
                    'label' => "Axle {$sequence} Left",
                ];
                $positions[] = [
                    'position_key' => "{$prefix}-R",
                    'axle_sequence' => $sequence,
                    'side' => 'R',
                    'ring' => null,
                    'label' => "Axle {$sequence} Right",
                ];

                continue;
            }

            foreach ([
                'L' => 'Left',
                'R' => 'Right',
            ] as $side => $sideLabel) {
                $positions[] = [
                    'position_key' => "{$prefix}-{$side}I",
                    'axle_sequence' => $sequence,
                    'side' => $side,
                    'ring' => 'inner',
                    'label' => "Axle {$sequence} {$sideLabel} Inner",
                ];
                $positions[] = [
                    'position_key' => "{$prefix}-{$side}O",
                    'axle_sequence' => $sequence,
                    'side' => $side,
                    'ring' => 'outer',
                    'label' => "Axle {$sequence} {$sideLabel} Outer",
                ];
            }
        }

        return $positions;
    }

    /**
     * @return list<array{
     *     position_key: string,
     *     axle_sequence: int,
     *     side: string,
     *     ring: ?string,
     *     label: string
     * }>
     */
    public function deriveSpares(int $spareTyreCount): array
    {
        $positions = [];

        for ($index = 1; $index <= $spareTyreCount; $index++) {
            $positions[] = [
                'position_key' => 'SP-'.$index,
                'axle_sequence' => 0,
                'side' => 'S',
                'ring' => null,
                'label' => 'Spare '.$index,
            ];
        }

        return $positions;
    }

    /**
     * @param  list<array{sequence: int, configuration: TyreAxleConfiguration}>  $axles
     * @return list<array{
     *     position_key: string,
     *     axle_sequence: int,
     *     side: string,
     *     ring: ?string,
     *     label: string
     * }>
     */
    public function deriveAll(array $axles, int $spareTyreCount): array
    {
        return array_merge($this->derive($axles), $this->deriveSpares($spareTyreCount));
    }
}
