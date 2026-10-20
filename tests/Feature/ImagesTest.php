<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Models\CustomTool;
use Filament\ChatAi\Models\ToolAction;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\ActionForm;
use Filament\ChatAi\Support\ChatAiContext;
use Filament\ChatAi\Support\FileParts;
use Filament\ChatAi\Support\ImageSource;
use Filament\ChatAi\Tests\Fixtures\Shop\Product;
use Filament\ChatAi\Tests\Fixtures\Shop\ProductResource;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\ChatAi\Tools\ChatAiTool;
use Filament\ChatAi\Tools\RecordTool;
use Filament\ChatAi\Ui\ComponentValidator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    ChatAiSettings::flush();
    Storage::fake('local');
    Storage::fake('public');
    config(['chatai.images.disk' => 'public']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->user = User::create(['name' => 'Ana', 'email' => 'ana@images.test', 'password' => 'x']));
    $this->conversation = Conversation::query()->create(['user_type' => $this->user->getMorphClass(), 'user_id' => $this->user->getKey(), 'panel_id' => 'admin', 'title' => 'Images']);

    Product::create(['name' => 'Classic Pillow', 'sku' => 'P-1', 'qty' => 2, 'photo' => 'shop_products/pillow.jpg']);
    Product::create(['name' => 'Oak Mug', 'sku' => 'M-1', 'qty' => 30]);
});

function imageTool(string $type, array $config, string $name = 'pictures'): ChatAiTool
{
    CustomTool::query()->create(['name' => $name, 'title' => ucfirst($name), 'description' => 'Test tool', 'type' => $type, 'enabled' => true, 'config' => $config]);

    /** @var ChatAiPlugin $plugin */
    $plugin = Filament::getPanel('admin')->getPlugin('chatai');

    return collect($plugin->resolveTools()->tools(test()->user, new ChatAiContext(panelId: 'admin')))->firstWhere('name', $name);
}

function upload(User $user, Conversation $conversation, string $mime = 'image/png', string $name = 'mug.png'): ConversationFile
{
    Storage::disk('local')->put($path = 'chatai/uploads/'.$name, 'fake-bytes');

    return ConversationFile::query()->create([
        'conversation_id' => $conversation->id, 'user_type' => $user->getMorphClass(), 'user_id' => $user->getKey(),
        'kind' => ConversationFile::KIND_UPLOAD, 'name' => $name, 'mime' => $mime, 'size' => 10, 'disk' => 'local', 'path' => $path,
    ]);
}

it('gives each listed row its picture and tells the model to show cards', function () {
    $tool = imageTool(CustomTool::TYPE_RESOURCE, ['resource' => ProductResource::class, 'fields' => ['name'], 'image' => 'photo', 'sort' => 'id', 'sort_dir' => 'asc']);
    $rows = $tool->call([])->data['rows'];

    expect($tool->description)->toContain('_image')
        ->and($rows[0]['_image'])->toEndWith('/storage/shop_products/pillow.jpg')
        ->and($rows[1])->not->toHaveKey('_image'); // no picture, no key
});

it('ignores a picture source the model does not have', function () {
    $rows = imageTool(CustomTool::TYPE_RESOURCE, ['resource' => ProductResource::class, 'fields' => ['name'], 'image' => 'password'])->call([])->data['rows'];

    expect(collect($rows)->pluck('_image')->filter()->all())->toBe([])
        ->and(ImageSource::options(new Product))->toHaveKey('photo')
        ->and(ImageSource::collections(new Product))->toBe([]);
});

it('puts an image the user attached onto the record', function () {
    $tool = imageTool(CustomTool::TYPE_UPDATE, ['resource' => ProductResource::class, 'lookup' => ['name', 'sku'], 'image' => 'photo']);
    $file = upload($this->user, $this->conversation);

    expect($tool->inputSchema['properties'][RecordTool::IMAGE]['format'])->toBe('chatai-image');

    $data = $tool->call(['record' => 'Oak Mug', RecordTool::IMAGE => $file->id])->data;
    $photo = Product::query()->where('sku', 'M-1')->value('photo');

    expect($photo)->toStartWith('shop_products/')->toEndWith('.png')
        ->and(Storage::disk('public')->get($photo))->toBe('fake-bytes')
        ->and($data['before'][RecordTool::IMAGE])->toBeNull()
        ->and($data['after'][RecordTool::IMAGE])->toBe('mug.png');
});

it('never uses another user\'s file or a file that is not an image', function () {
    $tool = imageTool(CustomTool::TYPE_UPDATE, ['resource' => ProductResource::class, 'lookup' => ['name'], 'image' => 'photo']);
    $other = User::create(['name' => 'Rui', 'email' => 'rui@images.test', 'password' => 'x']);
    $theirs = upload($other, $this->conversation, name: 'theirs.png');
    $pdf = upload($this->user, $this->conversation, 'application/pdf', 'doc.pdf');

    expect($tool->call(['record' => 'Oak Mug', RecordTool::IMAGE => $theirs->id])->isError())->toBeTrue()
        ->and($tool->call(['record' => 'Oak Mug', RecordTool::IMAGE => $pdf->id])->isError())->toBeTrue()
        ->and(Product::query()->where('sku', 'M-1')->value('photo'))->toBeNull();
});

it('shows the attached image on the confirmation card instead of an id field', function () {
    $tool = imageTool(CustomTool::TYPE_UPDATE, ['resource' => ProductResource::class, 'lookup' => ['name'], 'image' => 'photo']);
    $file = upload($this->user, $this->conversation);

    $field = collect(ActionForm::fields($tool->inputSchema, ['record' => 'Oak Mug', RecordTool::IMAGE => $file->id]))->firstWhere('key', RecordTool::IMAGE);
    $card = (new ComponentValidator)->validate(['type' => 'confirm', 'action_id' => 'a1', 'fields' => [$field]], fromServer: true);

    expect($field['type'])->toBe('image')
        ->and($card['fields'][0]['preview'])->toContain('/files/'.$file->id)
        ->and(ActionForm::validate($tool->inputSchema, ['record' => 'Oak Mug', RecordTool::IMAGE => 'not-an-id'])[1])->toHaveKey(RecordTool::IMAGE)
        ->and((new ToolAction(['result' => ['data' => ['record' => 'Oak Mug', 'before' => [RecordTool::IMAGE => null], 'after' => [RecordTool::IMAGE => 'mug.png']]]]))->resultFields()[1])
        ->toBe(['label' => 'Image', 'value' => '— → mug.png']);
});

it('tells the model the id of an attached image', function () {
    $parts = FileParts::for(upload($this->user, $this->conversation));

    expect($parts[0]['text'])->toContain('file id: ')
        ->and($parts[1]['type'])->toBe('image_url');
});

it('renders cards and image cells only with pictures from the app or allowed hosts', function () {
    $validator = new ComponentValidator;
    $cards = $validator->validate(['type' => 'cards', 'title' => 'Products', 'items' => [
        ['title' => 'Oak Mug', 'subtitle' => 'M-1', 'image' => url('/storage/mug.jpg'), 'badge' => 'Low', 'fields' => [['label' => 'Stock', 'value' => 3], ['label' => 'Bad']], 'url' => '/admin/products/1/edit'],
        ['title' => 'Spy', 'image' => 'https://tracker.example/pixel.gif', 'url' => 'javascript:alert(1)'],
        ['subtitle' => 'no title'],
    ]]);

    expect($cards['items'])->toHaveCount(2)
        ->and($cards['items'][0])->toMatchArray(['image' => url('/storage/mug.jpg'), 'badge' => 'Low', 'url' => '/admin/products/1/edit', 'fields' => [['label' => 'Stock', 'value' => '3']]])
        ->and($cards['items'][1])->not->toHaveKeys(['image', 'url']);

    config(['chatai.images.hosts' => ['cdn.shop.test']]);
    $table = $validator->validate(['type' => 'table', 'columns' => [['key' => '_image', 'label' => '', 'format' => 'image'], ['key' => 'name', 'label' => 'Name']], 'rows' => [
        ['_image' => 'https://cdn.shop.test/a.jpg', 'name' => 'A'],
        ['_image' => 'http://cdn.shop.test/b.jpg', 'name' => 'B'],
    ]]);

    expect(array_column($table['rows'], '_image'))->toBe(['https://cdn.shop.test/a.jpg', null]);
});
