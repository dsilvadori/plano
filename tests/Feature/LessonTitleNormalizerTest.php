<?php

namespace Tests\Feature;

use App\Support\LessonTitleNormalizer;
use Tests\TestCase;

class LessonTitleNormalizerTest extends TestCase
{
    public function test_it_cleans_drive_video_file_names_with_resolution_and_underscores(): void
    {
        $this->assertSame(
            '01 - Excel 365 - Introdução e Backstage Arquivo',
            LessonTitleNormalizer::normalizePreservingNumber('01_-_excel_365_-_introdução_e_backstage_arquivo (720p)', 99),
        );

        $this->assertSame(
            '01 - Introdução ao Word 365',
            LessonTitleNormalizer::normalizePreservingNumber('01_-_introdução_ao_word_365 (720p)', 99),
        );

        $this->assertSame(
            '01 - Microsoft Edge',
            LessonTitleNormalizer::normalizePreservingNumber('1__microsoft_edge (720p)', 99),
        );

        $this->assertSame(
            '01 - PowerPoint 365 - Backstage Arquivo',
            LessonTitleNormalizer::normalizePreservingNumber('01___powerpoint_365___backstage_arquivo (720p)', 99),
        );

        $this->assertSame(
            '01 - Linux',
            LessonTitleNormalizer::normalizePreservingNumber('1____linux (720p)', 99),
        );
    }

    public function test_it_removes_repeated_aula_prefix_before_numbering(): void
    {
        $this->assertSame(
            '01 - Introdução',
            LessonTitleNormalizer::normalizePreservingNumber('AULA 01 - INTRODUCAO.mp4', 99),
        );
    }
}
