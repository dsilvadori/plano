<?php

namespace App\Filament\Resources\GoogleDriveImportRuns;

use App\Filament\Resources\GoogleDriveImportRuns\Pages\EditGoogleDriveImportRun;
use App\Filament\Resources\GoogleDriveImportRuns\Pages\ListGoogleDriveImportRuns;
use App\Filament\Resources\GoogleDriveImportRuns\Schemas\GoogleDriveImportRunForm;
use App\Filament\Resources\GoogleDriveImportRuns\Tables\GoogleDriveImportRunsTable;
use App\Models\GoogleDriveImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GoogleDriveImportRunResource extends Resource
{
    protected static ?string $model = GoogleDriveImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Operação';

    protected static ?string $modelLabel = 'Importação Drive';

    protected static ?string $pluralModelLabel = 'Importações Drive';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return GoogleDriveImportRunForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoogleDriveImportRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoogleDriveImportRuns::route('/'),
            'edit' => EditGoogleDriveImportRun::route('/{record}/edit'),
        ];
    }
}
