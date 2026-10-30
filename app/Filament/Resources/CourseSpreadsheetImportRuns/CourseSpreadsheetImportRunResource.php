<?php

namespace App\Filament\Resources\CourseSpreadsheetImportRuns;

use App\Filament\Resources\CourseSpreadsheetImportRuns\Pages\EditCourseSpreadsheetImportRun;
use App\Filament\Resources\CourseSpreadsheetImportRuns\Pages\ListCourseSpreadsheetImportRuns;
use App\Filament\Resources\CourseSpreadsheetImportRuns\Schemas\CourseSpreadsheetImportRunForm;
use App\Filament\Resources\CourseSpreadsheetImportRuns\Tables\CourseSpreadsheetImportRunsTable;
use App\Models\CourseSpreadsheetImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CourseSpreadsheetImportRunResource extends Resource
{
    protected static ?string $model = CourseSpreadsheetImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Operação';

    protected static ?string $modelLabel = 'Importação de planilha';

    protected static ?string $pluralModelLabel = 'Importações de planilhas';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return CourseSpreadsheetImportRunForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CourseSpreadsheetImportRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseSpreadsheetImportRuns::route('/'),
            'edit' => EditCourseSpreadsheetImportRun::route('/{record}/edit'),
        ];
    }
}
