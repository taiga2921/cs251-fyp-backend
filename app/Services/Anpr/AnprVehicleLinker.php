<?php

namespace App\Services\Anpr;

use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

class AnprVehicleLinker
{
    public function normalizePlateNumber(string $plateNumber): string
    {
        $upper = strtoupper(trim($plateNumber));

        return preg_replace('/[^A-Z0-9]/', '', $upper) ?? '';
    }

    public function linkOrCreate(string $plateNumber): Vehicle
    {
        $normalized = $this->normalizePlateNumber($plateNumber);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Plate number cannot be empty after normalization.');
        }

        return DB::transaction(function () use ($normalized) {
            $vehicle = Vehicle::query()
                ->whereRaw(
                    "REPLACE(REPLACE(REPLACE(UPPER(plate_number), '-', ''), ' ', ''), '.', '') = ?",
                    [$normalized]
                )
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
