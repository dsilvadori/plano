<?php

namespace App\Support;

use Illuminate\Support\Str;

class LessonTitleNormalizer
{
    public static function normalize(string $title, int $position): string
    {
        $title = self::cleanTitle($title);
        $title = self::applyCorrections($title);
        $title = self::titleCase($title);
        $title = self::applyCorrections($title);

        return str_pad((string) $position, 2, '0', STR_PAD_LEFT) . ' - ' . $title;
    }

    protected static function cleanTitle(string $title): string
    {
        return Str::of($title)
            ->replaceMatches('/\.(mp4|mov|m4v|avi|mkv|webm)$/i', '')
            ->replaceMatches('/\(\s*\d{3,4}p\s*\)/i', '')
            ->replace(['_', '–', '—'], [' ', '-', '-'])
            ->replaceMatches('/^\s*\d{1,3}\s*[-.]?\s*/', '')
            ->replaceMatches('/\b(Raciocínio\s+Lógico)\s+\d{1,3}\s*-\s*/iu', '$1 - ')
            ->replaceMatches('/\s*-\s*/', ' - ')
            ->replaceMatches('/\s+/', ' ')
            ->trim(" \t\n\r\0\x0B-")
            ->value();
    }

    protected static function titleCase(string $title): string
    {
        $minorWords = ['a', 'as', 'com', 'como', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'um', 'uma'];
        $parts = preg_split('/(\s+)/u', mb_strtolower($title, 'UTF-8'), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $isFirstWord = true;

        return collect($parts)
            ->map(function (string $part) use (&$isFirstWord, $minorWords): string {
                if (trim($part) === '') {
                    return $part;
                }

                if ($part === '-') {
                    $isFirstWord = true;

                    return $part;
                }

                $word = in_array($part, $minorWords, true) && ! $isFirstWord
                    ? $part
                    : mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');

                $isFirstWord = false;

                return $word;
            })
            ->join('');
    }

    protected static function applyCorrections(string $title): string
    {
        $corrections = [
            '/\bAdministracao\b/iu' => 'Administração',
            '/\bArquivistica\b/iu' => 'Arquivística',
            '/\bAtendimeento\b/iu' => 'Atendimento',
            '/\bClassica\b/iu' => 'Clássica',
            '/\bCientifica\b/iu' => 'Científica',
            '/\bComuicacao\b/iu' => 'Comunicação',
            '/\bComuicação\b/iu' => 'Comunicação',
            '/\bConjuncao\b/iu' => 'Conjunção',
            '/\bContingeencial\b/iu' => 'Contingencial',
            '/\bEquivalencia\b/iu' => 'Equivalência',
            '/\bInterpessoal\b/iu' => 'Interpessoal',
            '/\bInperpessoal\b/iu' => 'Interpessoal',
            '/\bIntepessoal\b/iu' => 'Interpessoal',
            '/\bLogico\b/iu' => 'Lógico',
            '/\bNegacoes\b/iu' => 'Negações',
            '/\bOrganizacional\b/iu' => 'Organizacional',
            '/\bPeriodo\b/iu' => 'Período',
            '/\bPreposicao\b/iu' => 'Preposição',
            '/\bProposicoes\b/iu' => 'Proposições',
            '/\bPrincipios\b/iu' => 'Princípios',
            '/\bArquivisticos\b/iu' => 'Arquivísticos',
            '/\bSequencias\b/iu' => 'Sequências',
            '/\bSintatica\b/iu' => 'Sintática',
            '/\bSubordinacao\b/iu' => 'Subordinação',
            '/\bTautologia\b/iu' => 'Tautologia',
            '/\bVerbais\b/iu' => 'Verbais',
            '/\bPopr\b/iu' => 'por',
            '/\bParte Ii\b/u' => 'Parte II',
            '/\bAcesso À Informação\b/u' => 'Acesso à Informação',
            '/O\(A\)/u' => 'o(a)',
            '/Os\(As\)/u' => 'os(as)',
            '/\bBsc\b/u' => 'BSC',
            '/\bPmbok\b/u' => 'PMBOK',
            '/\bSigad\b/u' => 'SIGAD',
            '/\bSwot\b/u' => 'SWOT',
            '/\bTj\b/u' => 'TJ',
            '/\bConarq\b/u' => 'CONARQ',
            '/\bSinar\b/u' => 'SINAR',
        ];

        return preg_replace(array_keys($corrections), array_values($corrections), $title) ?? $title;
    }
}
