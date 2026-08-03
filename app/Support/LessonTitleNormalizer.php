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

        return str_pad((string) $position, 2, '0', STR_PAD_LEFT).' - '.$title;
    }

    public static function normalizePreservingNumber(string $title, int $fallbackPosition): string
    {
        return self::normalize($title, self::leadingNumber($title) ?? $fallbackPosition);
    }

    public static function matchKey(string $title): string
    {
        return Str::of(self::expandAliases(self::cleanTitle($title)))
            ->lower()
            ->ascii()
            ->replaceMatches('/\baula\b/u', ' ')
            ->replaceMatches('/\bparte\b/u', ' ')
            ->replaceMatches('/^\s*0*\d{1,3}\b/u', ' ')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    public static function matches(string $left, string $right, int $minimumPercent = 72): bool
    {
        return self::matchScore($left, $right) >= $minimumPercent;
    }

    public static function matchScore(string $left, string $right): float
    {
        $leftKey = self::matchKey($left);
        $rightKey = self::matchKey($right);

        if ($leftKey === '' || $rightKey === '') {
            return 0.0;
        }

        if ($leftKey === $rightKey) {
            return 100.0;
        }

        similar_text($leftKey, $rightKey, $percent);

        return max(self::tokenOverlapPercent($leftKey, $rightKey), $percent);
    }

    public static function leadingNumber(string $title): ?int
    {
        $normalizedTitle = Str::of($title)
            ->replaceMatches('/\.(mp4|mov|m4v|avi|mkv|webm)$/i', '')
            ->replaceMatches('/\(\s*\d{3,4}p\s*\)/i', '')
            ->replace(['_', '–', '—'], [' ', '-', '-'])
            ->squish()
            ->value();

        return preg_match('/^\s*(?:aula\s*)?0*(\d{1,3})(?:\b|[\s.-])/iu', $normalizedTitle, $matches) === 1
            ? max(1, (int) $matches[1])
            : null;
    }

    protected static function tokenOverlapPercent(string $leftKey, string $rightKey): float
    {
        $leftTokens = self::comparisonTokens($leftKey);
        $rightTokens = self::comparisonTokens($rightKey);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);
        $union = array_unique([...$leftTokens, ...$rightTokens]);

        return count($union) > 0 ? (count($intersection) / count($union)) * 100 : 0.0;
    }

    protected static function comparisonTokens(string $key): array
    {
        $ignored = ['a', 'as', 'ao', 'aos', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'um', 'uma'];

        return collect(explode(' ', $key))
            ->filter(fn (string $token): bool => $token !== '' && ! in_array($token, $ignored, true))
            ->unique()
            ->values()
            ->all();
    }

    protected static function cleanTitle(string $title): string
    {
        return Str::of($title)
            ->replaceMatches('/\.(mp4|mov|m4v|avi|mkv|webm)$/i', '')
            ->replaceMatches('/\(\s*\d{3,4}p\s*\)/i', '')
            ->replace(['–', '—'], '-')
            ->replaceMatches('/_{3,}/u', ' - ')
            ->replaceMatches('/[_]+/u', ' ')
            ->replaceMatches('/\s*-\s*/', ' - ')
            ->replaceMatches('/^\s*(?:aula\s*)?\d{1,3}\s*(?:[-.]|\s+-\s+)?\s*/iu', '')
            ->replaceMatches('/\b(Raciocínio\s+Lógico)\s+\d{1,3}\s*-\s*/iu', '$1 - ')
            ->replaceMatches('/\s+/', ' ')
            ->trim(" \t\n\r\0\x0B-")
            ->value();
    }

    protected static function expandAliases(string $title): string
    {
        return Str::of($title)
            ->replaceMatches('/\bL\.?\s*O\.?\s+Santos\b/iu', 'Lei Orgânica Santos')
            ->value();
    }

    protected static function titleCase(string $title): string
    {
        $minorWords = ['a', 'as', 'ao', 'aos', 'com', 'como', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'um', 'uma'];
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
            '/\bIntroducao\b/iu' => 'Introdução',
            '/\bLogico\b/iu' => 'Lógico',
            '/\bPowerpoint\b/iu' => 'PowerPoint',
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
