<?php

namespace Filament\ChatAi\Support;

use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Tools\NoCode\ResourceInspector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where a record keeps its picture: a column holding a path or URL, or a
 * Spatie Media Library collection ("media:product-images"). Used to show the
 * picture in answers and to put an image the user attached onto the record.
 */
final class ImageSource
{
    public const MEDIA = 'media:';

    /**
     * What the tool editor offers for a model: its media collections, then its columns.
     *
     * @return array<string, string>
     */
    public static function options(?Model $model): array
    {
        if (! $model) {
            return [];
        }

        $options = [];

        foreach (self::collections($model) as $collection) {
            $options[self::MEDIA.$collection] = __('chatai::chatai.tools.image_media', ['collection' => $collection]);
        }

        return $options + ResourceInspector::columnOptions($model);
    }

    /**
     * Only sources that really exist on the model.
     */
    public static function valid(?Model $model, mixed $source): ?string
    {
        return is_string($source) && $source !== '' && array_key_exists($source, self::options($model)) ? $source : null;
    }

    public static function url(Model $record, string $source): ?string
    {
        try {
            if (str_starts_with($source, self::MEDIA)) {
                return method_exists($record, 'getFirstMediaUrl') ? ($record->getFirstMediaUrl(substr($source, strlen(self::MEDIA))) ?: null) : null;
            }

            $value = $record->getAttribute($source);
            $value = is_array($value) ? reset($value) : $value; // multiple uploads: the first

            if (! is_string($value) || $value === '') {
                return null;
            }

            return preg_match('#^(https?:)?//|^/#', $value) ? $value : Storage::disk(self::disk())->url($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Puts the image onto the record. Returns an error message, or null when done.
     */
    public static function attach(Model $record, string $source, ConversationFile $file): ?string
    {
        if (! $file->isImage()) {
            return 'The attached file is not an image.';
        }

        try {
            if (str_starts_with($source, self::MEDIA)) {
                $record->addMediaFromString($file->contents())
                    ->usingFileName(Str::slug(pathinfo($file->name, PATHINFO_FILENAME)).'.'.self::extension($file))
                    ->usingName(pathinfo($file->name, PATHINFO_FILENAME))
                    ->toMediaCollection(substr($source, strlen(self::MEDIA)));

                return null;
            }

            $path = $record->getTable().'/'.Str::ulid().'.'.self::extension($file);
            Storage::disk(self::disk())->put($path, $file->contents(), 'public');
            $record->setAttribute($source, $path)->save();

            return null;
        } catch (Throwable $e) {
            // e.g. a collection that only accepts JPEG
            return 'The image could not be saved: '.Str::limit($e->getMessage(), 200);
        }
    }

    /**
     * Media collections the model registers (Spatie Media Library), if any.
     *
     * @return array<int, string>
     */
    public static function collections(Model $model): array
    {
        if (! method_exists($model, 'registerMediaCollections') || ! method_exists($model, 'getRegisteredMediaCollections')) {
            return [];
        }

        try {
            // A fresh instance: registering again on the same one adds the collections twice.
            return $model->newInstance()->getRegisteredMediaCollections()->pluck('name')->filter()->unique()->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    protected static function disk(): string
    {
        return (string) (config('chatai.images.disk') ?: config('filament.default_filesystem_disk', 'public'));
    }

    protected static function extension(ConversationFile $file): string
    {
        return match ($file->mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => strtolower(pathinfo($file->name, PATHINFO_EXTENSION)) ?: 'jpg',
        };
    }
}
