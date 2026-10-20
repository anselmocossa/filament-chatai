<?php

use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\Message;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\Shop\Product;
use Filament\ChatAi\Tests\Fixtures\Shop\ProductResource;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

beforeEach(function () {
    ChatAiSettings::flush();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->actingAs($this->user = User::create(['name' => 'Ana', 'email' => 'ana@paging.test', 'password' => 'x']));

    foreach (range(1, 25) as $i) {
        Product::create(['name' => sprintf('Product %02d', $i), 'sku' => "S-{$i}", 'qty' => $i]);
    }

    CustomTool::query()->create(['name' => 'all-products', 'title' => 'All products', 'description' => 'Every product', 'type' => CustomTool::TYPE_RESOURCE, 'enabled' => true,
        'config' => ['resource' => ProductResource::class, 'fields' => ['name', 'qty'], 'sort' => 'qty', 'sort_dir' => 'asc', 'limit' => 10]]);
});

function pagedTool()
{
    return collect(Filament::getPanel('admin')->getPlugin('chatai')->resolveTools()->tools(test()->user, new ChatAiContext(panelId: 'admin')))->firstWhere('name', 'all-products');
}

it('pages a list tool and tells the model how to show every row', function () {
    $first = pagedTool()->call([])->data;
    $third = pagedTool()->call(['page' => 3])->data;

    expect($first['total'])->toBe(25)
        ->and($first['pages'])->toBe(3)
        ->and($first['source'])->toBe(['tool' => 'all-products', 'args' => []])
        ->and(collect($third['rows'])->pluck('name')->all())->toBe(['Product 21', 'Product 22', 'Product 23', 'Product 24', 'Product 25']);
});

it('turns a table with a source into a paged table filled by the tool', function () {
    app()->instance(LlmClient::class, FakeLlmClient::script([
        [FakeLlmClient::call('show_ui', ['components' => [[
            'type' => 'table', 'title' => 'Products',
            'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'qty', 'label' => 'Qty', 'format' => 'number']],
            'rows' => [['name' => 'only one copied by the model', 'qty' => 0]],
            'source' => ['tool' => 'all-products', 'args' => []],
        ]]])],
        ['Here are all the products.'],
    ]));

    $conversation = Conversation::start($this->user, 'admin');
    $this->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Show all products'])->assertOk()->streamedContent();

    $table = collect($conversation->messages()->where('role', Message::ROLE_ASSISTANT)->get()->last()->components)->firstWhere('type', 'table');

    expect($table['pages'])->toBe(3)
        ->and($table['total'])->toBe(25)
        ->and($table['rows'])->toHaveCount(10)
        ->and($table['rows'][0]['name'])->toBe('Product 01')
        ->and($table)->not->toHaveKey('note');
});

it('serves the next pages from the tool, for this user only', function () {
    $this->postJson(route('filament.admin.chatai.tables.page'), [
        'tool' => 'all-products', 'args' => [], 'page' => 2,
        'columns' => [['key' => 'name', 'label' => 'Name']],
    ])->assertOk()
        ->assertJsonPath('page', 2)
        ->assertJsonPath('pages', 3)
        ->assertJsonPath('rows.0.name', 'Product 11')
        ->assertJsonMissingPath('rows.0.qty');

    $this->postJson(route('filament.admin.chatai.tables.page'), ['tool' => 'show_ui', 'page' => 2])->assertNotFound();
});
