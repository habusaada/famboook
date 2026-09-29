<?php

namespace Database\Factories;

use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    public function definition(): array
    {
        // Synthetic values only; never real registry data.
        return [
            'import_batch_id' => ImportBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(2, 100000),
            'raw_payload' => ['الاسم' => 'اسم تجريبي', 'أفراد الأسرة' => '5'],
            'status' => ImportRowStatus::PENDING->value,
            'issues' => null,
            'family_id' => null,
        ];
    }
}
