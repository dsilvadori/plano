<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\StudyTrack;
use Illuminate\Database\Seeder;

class DemoCourseSeeder extends Seeder
{
    public function run(): void
    {
        Course::where('slug', 'gabaritando-prefeitura-de-santos')->delete();

        $this->seedInspetorDeAlunosCourse();
    }

    protected function seedInspetorDeAlunosCourse(): void
    {
        $course = Course::updateOrCreate(
            ['slug' => 'gabaritando-santos-inspetor-de-alunos'],
            [
                'name' => 'Gabaritando Santos - Inspetor de Alunos',
                'description' => 'Plano base inspirado na estrutura real do curso da Prefeitura de Santos para Inspetor de Alunos, com foco em blocos curtos, constância e distribuição entre base, legislação, específicas, revisão e questões.',
                'tutory_product_id' => 'gabaritando-santos-inspetor-de-alunos',
                'is_active' => true,
            ],
        );

        $modules = [
            ['name' => 'Apresentação e Boas-Vindas', 'type' => 'review', 'workload_minutes' => 60, 'sort_order' => 1],
            ['name' => 'Livro Digital - Matérias Básicas e Legislação', 'type' => 'review', 'workload_minutes' => 120, 'sort_order' => 2],
            ['name' => 'Livro Digital - Conhecimentos Específicos', 'type' => 'review', 'workload_minutes' => 180, 'sort_order' => 3],
            ['name' => 'Cadernos de Questões', 'type' => 'questions', 'workload_minutes' => $this->estimateMinutes(5), 'sort_order' => 4],
            ['name' => 'Português - Classes de Palavras', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(8), 'sort_order' => 5],
            ['name' => 'Português - Análise Sintática', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(3), 'sort_order' => 6],
            ['name' => 'Português - Período Simples e Composto', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(5), 'sort_order' => 7],
            ['name' => 'Português - Concordância Verbal e Nominal', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(7), 'sort_order' => 8],
            ['name' => 'Português - Regência Verbal e Nominal', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(2), 'sort_order' => 9],
            ['name' => 'Português - Crase e Colocação Pronominal', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(6), 'sort_order' => 10],
            ['name' => 'Português - Pontuação e Acentuação', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(8), 'sort_order' => 11],
            ['name' => 'Português - Interpretação de Textos', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(14), 'sort_order' => 12],
            ['name' => 'Matemática - Matemática Básica', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(5), 'sort_order' => 13],
            ['name' => 'Matemática - Regra de Três', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(2), 'sort_order' => 14],
            ['name' => 'Matemática - Porcentagem e Juros', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(3), 'sort_order' => 15],
            ['name' => 'Matemática - Equação de Primeiro e Segundo Grau', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(3), 'sort_order' => 16],
            ['name' => 'Matemática - Sistema Métrico e Geometria', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(5), 'sort_order' => 17],
            ['name' => 'Informática - Windows 10', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(8), 'sort_order' => 18],
            ['name' => 'Informática - Word', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(7), 'sort_order' => 19],
            ['name' => 'Informática - Excel', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(18), 'sort_order' => 20],
            ['name' => 'Informática - PowerPoint', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(7), 'sort_order' => 21],
            ['name' => 'Informática - Internet e Segurança', 'type' => 'basic', 'workload_minutes' => $this->estimateMinutes(14), 'sort_order' => 22],
            ['name' => 'Legislação - Lei Orgânica de Santos', 'type' => 'specific', 'workload_minutes' => $this->estimateMinutes(9), 'sort_order' => 23],
            ['name' => 'Legislação - Estatuto de Santos', 'type' => 'specific', 'workload_minutes' => $this->estimateMinutes(7), 'sort_order' => 24],
            ['name' => 'Conhecimentos Específicos - ECA', 'type' => 'specific', 'workload_minutes' => $this->estimateMinutes(7), 'sort_order' => 25],
            ['name' => 'Conhecimentos Específicos - LDB', 'type' => 'specific', 'workload_minutes' => $this->estimateMinutes(4), 'sort_order' => 26],
            ['name' => 'Conhecimentos Específicos - Área Escolar', 'type' => 'specific', 'workload_minutes' => $this->estimateMinutes(18), 'sort_order' => 27],
            ['name' => 'Conhecimentos Específicos - Primeiros Socorros', 'type' => 'specific', 'workload_minutes' => $this->estimateMinutes(2), 'sort_order' => 28],
        ];

        foreach ($modules as $moduleData) {
            $course->modules()->updateOrCreate(
                ['name' => $moduleData['name']],
                $moduleData + ['is_active' => true],
            );
        }

        $track = StudyTrack::updateOrCreate(
            ['course_id' => $course->id, 'name' => 'Trilha Oficial - Inspetor de Alunos'],
            [
                'description' => 'Trilha equilibrada construída a partir da vitrine atual do curso, priorizando Português, Matemática, Informática, Legislação e conhecimentos específicos.',
                'is_active' => true,
            ],
        );

        $sync = [];
        foreach ($course->modules()->orderBy('sort_order')->get() as $module) {
            $sync[$module->id] = [
                'weight' => 1,
                'sort_order' => $module->sort_order,
            ];
        }

        $track->modules()->sync($sync);
    }

    protected function estimateMinutes(int $lessons, int $minutesPerLesson = 25): int
    {
        return (int) (ceil((max(1, $lessons) * $minutesPerLesson) / 30) * 30);
    }
}
