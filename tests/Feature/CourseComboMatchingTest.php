<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Services\CourseAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CourseComboMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected const JUNDIAI_TUTORY = 'Gabaritando Câmara de Jundiaí - Técnico Legislativo | Agente de Serviços | Agnte de Transportes';

    protected const JUNDIAI_CORRIGIDO = 'Gabaritando Câmara de Jundiaí - Técnico Legislativo | Agente de Serviços | Agente de Transportes';

    protected function purchase(string $productName, string $email = 'aluno@example.com'): void
    {
        Notification::fake();

        $this->postJson('/webhooks/tutory', [
            'evento' => 'pagamento_aprovado',
            'id' => 'or_'.md5($productName.$email),
            'nome' => 'Aluno Exemplo',
            'email' => $email,
            'produto' => ['id' => 'prod_'.md5($productName), 'nome' => $productName],
            'status' => 'paid',
        ], ['Authorization' => 'Bearer secret-local'])->assertOk()->assertJsonPath('status', 'processed');
    }

    /**
     * Configuração real de produção: só um dos três cursos tem no campo Combo a grafia da Tutory.
     */
    protected function jundiaiCourses(): array
    {
        return [
            Course::factory()->create(['name' => 'Gabaritando Câmara de Jundiaí - Técnico Legislativo', 'combo_name' => self::JUNDIAI_CORRIGIDO]),
            Course::factory()->create(['name' => 'Gabaritando Câmara de Jundiaí - Agente de Serviços', 'combo_name' => self::JUNDIAI_TUTORY]),
            Course::factory()->create(['name' => 'Gabaritando Câmara de Jundiaí - Agente de Transportes', 'combo_name' => self::JUNDIAI_CORRIGIDO]),
        ];
    }

    public function test_combo_requires_exact_name_and_does_not_tolerate_typos(): void
    {
        [$tecnico, $servicos, $transportes] = $this->jundiaiCourses();

        $this->purchase(self::JUNDIAI_TUTORY);

        $this->assertSame(
            [$servicos->id],
            User::findByEmail('aluno@example.com')->courses()->pluck('courses.id')->all(),
        );
    }

    public function test_combo_with_corrected_name_links_only_courses_with_that_exact_combo(): void
    {
        [$tecnico, $servicos, $transportes] = $this->jundiaiCourses();

        $this->purchase(self::JUNDIAI_CORRIGIDO);

        $this->assertEqualsCanonicalizing(
            [$tecnico->id, $transportes->id],
            User::findByEmail('aluno@example.com')->courses()->pluck('courses.id')->all(),
        );
    }

    public function test_combo_name_ignores_case_accents_and_extra_spaces(): void
    {
        $courses = collect([
            Course::factory()->create(['name' => 'Jundiaí A', 'combo_name' => 'Gabaritando Câmara de Jundiaí']),
            Course::factory()->create(['name' => 'Jundiaí B', 'combo_name' => 'Outro Combo, Gabaritando Câmara de Jundiaí']),
        ]);

        $this->assertEqualsCanonicalizing(
            $courses->pluck('id')->all(),
            app(CourseAccessResolver::class)->coursesForCombo('  GABARITANDO camara de  jundiai ')->pluck('id')->all(),
        );
    }

    public function test_setec_combo_links_published_courses_and_skips_archived_one(): void
    {
        $archived = Course::factory()->create(['name' => 'Gabaritando SETEC Campinas', 'combo_name' => 'Gabaritando SETEC Campinas', 'status' => 'archived']);
        $published = collect(['Agente Administrativo', 'Agente de Fiscalização', 'Motorista Especializado'])
            ->map(fn (string $cargo) => Course::factory()->create(['name' => "Gabaritando SETEC - {$cargo}", 'combo_name' => 'Gabaritando SETEC Campinas']));

        $this->purchase('Gabaritando SETEC Campinas');

        $linked = User::findByEmail('aluno@example.com')->courses()->pluck('courses.id');
        $this->assertEqualsCanonicalizing($published->pluck('id')->all(), $linked->all());
        $this->assertNotContains($archived->id, $linked);
    }

    public function test_combo_match_does_not_mix_different_combos(): void
    {
        $resolver = app(CourseAccessResolver::class);
        $medio = Course::factory()->create(['name' => 'Boituva Técnico', 'combo_name' => 'Gabaritando Câmara de Boituva - Nível Médio']);
        $superior = Course::factory()->create(['name' => 'Boituva Agente', 'combo_name' => 'Gabaritando Câmara de Boituva - Nível Superior']);
        $turma1 = Course::factory()->create(['name' => 'Santos Turma 1', 'combo_name' => 'Gabaritando Prefeitura de Santos Turma 1']);
        $turma2 = Course::factory()->create(['name' => 'Santos Turma 2', 'combo_name' => 'Gabaritando Prefeitura de Santos Turma 2']);
        $curto = Course::factory()->create(['name' => 'Combo Curto', 'combo_name' => 'Combo Santos']);

        $this->assertSame([$superior->id], $resolver->coursesForCombo('Gabaritando Câmara de Boituva - Nível Superior')->pluck('id')->all());
        $this->assertSame([$medio->id], $resolver->coursesForCombo('Gabaritando Câmara de Boituva - Nível Médio')->pluck('id')->all());
        $this->assertSame([$turma2->id], $resolver->coursesForCombo('Gabaritando Prefeitura de Santos Turma 2')->pluck('id')->all());
        $this->assertSame([$curto->id], $resolver->coursesForCombo('Combo Santos')->pluck('id')->all());
        $this->assertSame([], $resolver->coursesForCombo('Combo Santas')->pluck('id')->all());
        $this->assertNotContains($turma1->id, $resolver->coursesForCombo('Gabaritando Prefeitura de Santos Turma 2')->pluck('id'));
    }

    public function test_single_course_product_from_same_city_does_not_trigger_combo(): void
    {
        $this->jundiaiCourses();
        $avulso = Course::factory()->create(['name' => 'Gabaritando Câmara de Jundiaí - Agente Administrativo', 'tutory_product_id' => 'jund-adm']);

        Notification::fake();
        $this->postJson('/webhooks/tutory', [
            'evento' => 'pagamento_aprovado',
            'id' => 'or_avulso',
            'nome' => 'Aluno Avulso',
            'email' => 'avulso@example.com',
            'produto' => ['id' => 'jund-adm', 'nome' => 'Gabaritando Câmara de Jundiaí - Agente Administrativo'],
            'status' => 'paid',
        ], ['Authorization' => 'Bearer secret-local'])->assertOk();

        $this->assertSame([$avulso->id], User::findByEmail('avulso@example.com')->courses()->pluck('courses.id')->all());
    }
}
