<?php

namespace Database\Seeders;

use App\Models\CourseSphere;
use App\Models\EducationLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CourseTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'Municipal',
            'Estadual',
            'Federal',
            'Tribunais',
            'Policial',
            'Educação',
            'Fiscal',
        ])->each(function (string $name, int $index): void {
            CourseSphere::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ],
            );
        });

        collect([
            'Ensino Fundamental',
            'Ensino Médio',
            'Ensino Técnico',
            'Ensino Superior',
        ])->each(function (string $name, int $index): void {
            EducationLevel::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ],
            );
        });
    }
}
