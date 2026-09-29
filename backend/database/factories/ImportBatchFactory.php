<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            'source_filename' => 'synthetic-families.xlsx',
            'source_checksum' => hash('sha256', fake()->uuid()),
            'status' => ImportBatchStatus::UPLOADED->value,
            'row_count' => 0,
        ];
    }
}
