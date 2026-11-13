<?php

namespace Filament\ChatAi\Tests\Fixtures\Shop;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;
}

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;
}

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;
}

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static ?string $slug = 'students';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record instanceof Student ? trim("{$record->nome} {$record->apelido}") : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'view' => ViewStudent::route('/{record}'),
            'edit' => EditStudent::route('/{record}/edit'),
        ];
    }
}

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;
}

class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;
}

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;
}

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function getPages(): array
    {
        return [];
    }
}

class OrderItemResource extends Resource
{
    protected static ?string $model = OrderItem::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function getPages(): array
    {
        return [];
    }
}
