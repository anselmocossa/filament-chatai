<?php

namespace Filament\ChatAi\Support;

use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Settings\ChatAiSettings;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * A file as OpenAI-style content parts: images and PDFs go as they are (the
 * Claude and OpenAI APIs read them), text, CSV and Excel become text so every
 * model can use them.
 */
final class FileParts
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function for(ConversationFile $file): array
    {
        if ($file->isImage()) {
            // The id lets a tool put this image onto a record.
            return [
                ['type' => 'text', 'text' => "Attached image \"{$file->name}\" (file id: {$file->id})"],
                ['type' => 'image_url', 'image_url' => ['url' => self::dataUrl($file)]],
            ];
        }

        if ($file->isPdf()) {
            return [['type' => 'file', 'file' => ['filename' => $file->name, 'file_data' => self::dataUrl($file)]]];
        }

        return [['type' => 'text', 'text' => "Attached file \"{$file->name}\":\n```\n".self::text($file)."\n```"]];
    }

    /**
     * Where files are kept: the settings page, then config / CHATAI_FILES_DISK.
     */
    public static function disk(): string
    {
        ChatAiSettings::apply();

        return (string) (config('chatai.files.disk') ?: 'local');
    }

    protected static function dataUrl(ConversationFile $file): string
    {
        return 'data:'.$file->mime.';base64,'.base64_encode($file->contents());
    }

    protected static function text(ConversationFile $file): string
    {
        $text = str_ends_with(strtolower($file->name), '.xlsx') ? self::spreadsheet($file) : $file->contents();

        return Str::limit((string) $text, (int) config('chatai.files.max_text_chars', 100_000), "\n[…cut]");
    }

    /**
     * First sheets of an Excel file as CSV-like lines.
     */
    protected static function spreadsheet(ConversationFile $file): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'chatai').'.xlsx';
        file_put_contents($temp, $file->contents());
        $lines = [];

        try {
            $reader = new Reader;
            $reader->open($temp);

            foreach ($reader->getSheetIterator() as $sheet) {
                $lines[] = "# {$sheet->getName()}";

                foreach ($sheet->getRowIterator() as $row) {
                    $lines[] = implode(',', array_map(fn ($cell) => str_contains((string) $cell, ',') ? '"'.$cell.'"' : (string) $cell, $row->toArray()));
                }
            }

            $reader->close();
        } catch (Throwable) {
            $lines[] = '[the spreadsheet could not be read]';
        } finally {
            @unlink($temp);
        }

        return implode("\n", $lines);
    }
}
