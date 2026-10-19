<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\FileParts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController
{
    /** Files the assistant can read: documents, images, text, spreadsheets. */
    public const ACCEPTED = 'pdf,png,jpg,jpeg,gif,webp,txt,md,csv,json,xlsx';

    public function store(Request $request, string $conversation): JsonResponse
    {
        $conversation = ConversationController::authorized($conversation, 'update');

        ChatAiSettings::apply();

        $request->validate([
            'file' => ['required', 'file', 'mimes:'.self::ACCEPTED, 'max:'.(int) config('chatai.files.max_kb', 10240)],
        ]);

        $upload = $request->file('file');
        $disk = FileParts::disk();
        $path = $upload->storeAs('chatai/uploads/'.$conversation->id, Str::ulid().'.'.$upload->getClientOriginalExtension(), $disk);

        $file = ConversationFile::query()->create([
            'conversation_id' => $conversation->id,
            'user_type' => $request->user()->getMorphClass(),
            'user_id' => $request->user()->getKey(),
            'kind' => ConversationFile::KIND_UPLOAD,
            'name' => Str::limit($upload->getClientOriginalName(), 180, ''),
            'mime' => (string) $upload->getMimeType(),
            'size' => (int) $upload->getSize(),
            'disk' => $disk,
            'path' => $path,
        ]);

        return response()->json($file->summary(), 201);
    }

    public function download(string $file): StreamedResponse
    {
        $file = ConversationFile::query()->with('conversation')->findOrFail($file);
        Gate::authorize('view', $file->conversation);

        return Storage::disk($file->disk)->download($file->path, $file->name, ['Content-Type' => $file->mime]);
    }
}
