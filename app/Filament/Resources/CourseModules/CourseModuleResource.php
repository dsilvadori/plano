<?php

namespace App\Filament\Resources\CourseModules;

use App\Filament\Resources\CourseModules\Pages\CreateCourseModule;
use App\Filament\Resources\CourseModules\Pages\EditCourseModule;
use App\Filament\Resources\CourseModules\Pages\ListCourseModules;
use App\Filament\Resources\CourseModules\Schemas\CourseModuleForm;
use App\Filament\Resources\CourseModules\Tables\CourseModulesTable;
use App\Models\CourseModule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CourseModuleResource extends Resource
{
    protected static ?string $model = CourseModule::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Módulo';

    protected static ?string $pluralModelLabel = 'Módulos';

    public static function form(Schema $schema): Schema
    {
        return CourseModuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CourseModulesTable::configure($table);
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
            'index' => ListCourseModules::route('/'),
            'create' => CreateCourseModule::route('/create'),
            'edit' => EditCourseModule::route('/{record}/edit'),
        ];
    }
}
