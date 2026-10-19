<?php

namespace Filament\ChatAi\Tools;

use Dompdf\Dompdf;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Support\FileParts;
use Filament\ChatAi\Ui\Catalog;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Lets the assistant hand the user a file: a table as CSV or Excel, or a text
 * as Markdown, HTML, plain text or PDF (when dompdf is installed). The file is
 * kept on the configured disk and only the conversation owner can download it.
 */
final class DocumentTool
{
    public const NAME = 'create_document';

    /**
     * @return array<int, string>
     */
    public static function formats(): array
    {
        return ['csv', 'xlsx', 'md', 'html', 'txt', ...(class_exists(Dompdf::class) ? ['pdf'] : [])];
    }

    public static function make(Conversation $conversation, Authenticatable $user): ChatAiTool
    {
        return new ChatAiTool(
            name: self::NAME,
            description: 'Create a file the user can download. Tables (columns + rows) as csv or xlsx; text (content, Markdown) as md, html, txt'
                .(in_array('pdf', self::formats(), true) ? ' or pdf' : '').'. Use it when the user asks for a file, export, report or document. Get the data with the other tools first.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Document title, also used for the file name.'],
                    'format' => ['type' => 'string', 'enum' => self::formats()],
                    'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Table header (csv, xlsx, and optionally a table in the text formats).'],
                    'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => ['string', 'number', 'boolean', 'null']]], 'description' => 'Table rows, one array of values per row.'],
                    'content' => ['type' => 'string', 'description' => 'Body in Markdown, for md, html, txt and pdf.'],
                ],
                'required' => ['title', 'format'],
            ],
            handler: fn (array $args): ToolResult => self::create($conversation, $user, $args),
            readOnly: true,
            title: 'Document',
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected static function create(Conversation $conversation, Authenticatable $user, array $args): ToolResult
    {
        $format = in_array($args['format'] ?? null, self::formats(), true) ? $args['format'] : 'md';
        $title = Str::limit(trim((string) ($args['title'] ?? 'Document')) ?: 'Document', 120, '');
        $columns = array_map('strval', (array) ($args['columns'] ?? []));
        $rows = array_values(array_filter((array) ($args['rows'] ?? []), 'is_array'));
        $content = (string) ($args['content'] ?? '');

        $bytes = match ($format) {
            'csv' => self::csv($columns, $rows),
            'xlsx' => self::xlsx($columns, $rows),
            'html' => self::html($title, $content, $columns, $rows),
            'pdf' => self::pdf($title, $content, $columns, $rows),
            default => trim(($content !== '' ? $content : '# '.$title)."\n\n".self::markdownTable($columns, $rows))."\n",
        };

        $disk = FileParts::disk();
        $name = (Str::slug($title) ?: 'document').'.'.$format;
        $path = 'chatai/generated/'.$conversation->id.'/'.Str::ulid().'.'.$format;
        Storage::disk($disk)->put($path, $bytes);

        /** @var Model $user */
        $file = ConversationFile::query()->create([
            'conversation_id' => $conversation->id,
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'kind' => ConversationFile::KIND_GENERATED,
            'name' => $name,
            'mime' => self::mime($format),
            'size' => strlen($bytes),
            'disk' => $disk,
            'path' => $path,
        ]);

        $url = Filament::getCurrentPanel()?->route('chatai.files.download', ['file' => $file->id]) ?? '';

        return ToolResult::data(
            ['created' => $name, 'size_bytes' => $file->size, 'note' => 'The user sees a download card for this file; do not paste a link.'],
            ['type' => Catalog::FILE, 'title' => $title, 'name' => $name, 'format' => $format, 'size' => $file->size, 'url' => $url],
        );
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected static function csv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach (array_filter([$columns, ...$rows]) as $row) {
            fputcsv($handle, array_map(fn ($v) => is_scalar($v) ? $v : json_encode($v), $row), escape: '\\');
        }

        rewind($handle);

        return "\u{FEFF}".stream_get_contents($handle); // BOM: Excel opens accents correctly
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected static function xlsx(array $columns, array $rows): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'chatai').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($temp);

        foreach (array_filter([$columns, ...$rows]) as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $row)));
        }

        $writer->close();
        $bytes = (string) file_get_contents($temp);
        @unlink($temp);

        return $bytes;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected static function html(string $title, string $content, array $columns, array $rows): string
    {
        $body = Str::markdown(($content !== '' ? $content : '# '.$title)."\n\n".self::markdownTable($columns, $rows), ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return '<!doctype html><html><head><meta charset="utf-8"><title>'.e($title).'</title>'
            .'<style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#111;margin:32px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:6px;text-align:left}th{background:#f3f4f6}</style>'
            .'</head><body>'.$body.'</body></html>';
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected static function pdf(string $title, string $content, array $columns, array $rows): string
    {
        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml(self::html($title, $content, $columns, $rows), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected static function markdownTable(array $columns, array $rows): string
    {
        if ($columns === []) {
            return '';
        }

        $cell = fn ($v): string => str_replace(['|', "\n"], ['\|', ' '], is_scalar($v) ? (string) $v : (string) json_encode($v));
        $lines = ['| '.implode(' | ', array_map($cell, $columns)).' |', '|'.str_repeat(' --- |', count($columns))];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map($cell, array_pad(array_values($row), count($columns), ''))).' |';
        }

        return implode("\n", $lines);
    }

    protected static function mime(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'html' => 'text/html',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            default => 'text/markdown',
        };
    }
}
