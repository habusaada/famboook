<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportMode;
use App\Models\Clan;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            // An explicit synthetic Clan — never a default AL_BREEM.
            'clan_id' => fn () => Clan::create(['code' => 'SYN_'.strtoupper(fake()->unique()->bothify('????##')), 'name' => 'عشيرة تجريبية'])->id,
            'import_mode' => ImportMode::INITIAL->value,
            'source_filename' => 'synthetic-families.xlsx',
            'source_checksum' => hash('sha256', fake()->uuid()),
            'status' => ImportBatchStatus::UPLOADED->value,
            'row_count' => 0,
        ];
    }
}
