<?php

namespace Filament\ChatAi\Http\Controllers;

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Ui\ComponentValidator;
use Filament\ChatAi\Ui\TablePager;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Another page of a table that came from a list tool: the tool runs again
 * for this user, with the arguments the table keeps.
 */
class TableController
{
    public function __invoke(Request $request, ComponentValidator $validator): JsonResponse
    {
        $data = $request->validate([
            'tool' => ['required', 'string', 'max:64'],
            'args' => ['nullable', 'array'],
            'page' => ['required', 'integer', 'min:1'],
            'columns' => ['nullable', 'array'],
        ]);

        $tool = collect(ChatAiPlugin::get()->resolveTools()->tools($request->user(), new ChatAiContext(Filament::getCurrentPanel()->getId())))
            ->firstWhere('name', $data['tool']);

        abort_unless(TablePager::pageable($tool), 404);

        $page = TablePager::fetch($tool, TablePager::args($data['args'] ?? []), (int) $data['page']);

        abort_if($page === null, 422);

        // Same cleaning as any table: only the shown columns, links inside the app.
        $table = $validator->validate(['type' => 'table', 'columns' => $data['columns'] ?? [], 'rows' => $page['rows']], fromServer: true);

        return response()->json([
            'rows' => $table['rows'] ?? [],
            'page' => $page['page'],
            'pages' => $page['pages'],
            'total' => $page['total'],
        ]);
    }
}
