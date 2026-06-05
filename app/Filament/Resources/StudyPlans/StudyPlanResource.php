<?php

namespace App\Filament\Resources\StudyPlans;

use App\Filament\Resources\StudyPlans\Pages\CreateStudyPlan;
use App\Filament\Resources\StudyPlans\Pages\EditStudyPlan;
use App\Filament\Resources\StudyPlans\Pages\ListStudyPlans;
use App\Filament\Resources\StudyPlans\Schemas\StudyPlanForm;
use App\Filament\Resources\StudyPlans\Tables\StudyPlansTable;
use App\Models\StudyPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StudyPlanResource extends Resource
{
    protected static ?string $model = StudyPlan::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Operação';

    protected static ?string $modelLabel = 'Plano';

    protected static ?string $pluralModelLabel = 'Planos';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return StudyPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudyPlansTable::configure($table);
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
            'index' => ListStudyPlans::route('/'),
            'create' => CreateStudyPlan::route('/create'),
            'edit' => EditStudyPlan::route('/{record}/edit'),
        ];
    }
}
