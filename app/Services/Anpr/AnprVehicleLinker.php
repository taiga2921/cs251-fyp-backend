<?php

namespace App\Services\Anpr;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AnprVehicleLinker
{
    /**
     * SQL expression that canonicalizes stored plate_number for normalized lookup.
     */
    public function normalizedPlateColumnSql(string $column = 'plate_number'): string
    {
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?: 'plate_number';

        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER({$column}), '-', ''), ' ', ''), '.', ''), '_', ''), '/', ''), '\\', '')";
    }

    public function normalizePlateNumber(string $plateNumber): string
    {
        $upper = strtoupper(trim($plateNumber));

        return preg_replace('/[^A-Z0-9]/', '', $upper) ?? '';
    }

    public function queryByNormalizedPlate(string $plateNumber): Builder
    {
        $normalized = $this->normalizePlateNumber($plateNumber);

        return Vehicle::query()
            ->whereRaw($this->normalizedPlateColumnSql().' = ?', [$normalized]);
    }

    public function findByNormalizedPlate(string $plateNumber): ?Vehicle
    {
        $normalized = $this->normalizePlateNumber($plateNumber);

        if ($normalized === '') {
            return null;
        }

        return $this->queryByNormalizedPlate($plateNumber)->first();
    }

    public function linkOrCreate(string $plateNumber): Vehicle
    {
        $normalized = $this->normalizePlateNumber($plateNumber);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Plate number cannot be empty after normalization.');
        }

        return DB::transaction(function () use ($plateNumber, $normalized) {
            $vehicle = $this->queryByNormalizedPlate($plateNumber)
                ->lockForUpdate()
                ->first();

            if ($vehicle) {
                return $vehicle;
            }

            return Vehicle::query()->create([
                'plate_number' => $normalized,
                'status' => 'normal',
                'source' => 'auto_detected',
                'owner_name' => null,
                'vehicle_type' => null,
                'notes' => null,
            ]);
        });
    }
}
