<?php

namespace App\Filament\Resources\StudyTracks;

use App\Filament\Resources\StudyTracks\Pages\CreateStudyTrack;
use App\Filament\Resources\StudyTracks\Pages\EditStudyTrack;
use App\Filament\Resources\StudyTracks\Pages\ListStudyTracks;
use App\Filament\Resources\StudyTracks\Schemas\StudyTrackForm;
use App\Filament\Resources\StudyTracks\Tables\StudyTracksTable;
use App\Models\StudyTrack;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StudyTrackResource extends Resource
{
    protected static ?string $model = StudyTrack::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Trilha';

    protected static ?string $pluralModelLabel = 'Trilhas';

    public static function form(Schema $schema): Schema
    {
        return StudyTrackForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudyTracksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudyTracks::route('/'),
            'create' => CreateStudyTrack::route('/create'),
            'edit' => EditStudyTrack::route('/{record}/edit'),
        ];
    }
}
