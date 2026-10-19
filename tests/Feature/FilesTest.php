<?php

use Filament\ChatAi\Llm\AnthropicClient;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\LlmConfig;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ConversationFile;
use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\UrlContext;
use Filament\ChatAi\Testing\FakeLlmClient;
use Filament\ChatAi\Tests\Fixtures\EditWidgetPage;
use Filament\ChatAi\Tests\Fixtures\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

function fileUser(string $name = 'Ana'): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'x']);
}

beforeEach(function () {
    ChatAiSettings::flush();
    Storage::fake('local');
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Filament::getPanel('admin')->getPlugin('chatai')->tools(fn () => []);
});

afterEach(fn () => Filament::getPanel('admin')->getPlugin('chatai')->tools(null));

it('uploads a document to the user own conversation only', function () {
    $ana = fileUser('Ana');
    $conversation = Conversation::start($ana, 'admin');

    $id = $this->actingAs($ana)
        ->post(route('filament.admin.chatai.files.store', $conversation), ['file' => UploadedFile::fake()->createWithContent('vendas.csv', "mes,total\nJan,10\n")])
        ->assertCreated()
        ->assertJsonPath('name', 'vendas.csv')
        ->json('id');

    expect(Storage::disk('local')->exists(ConversationFile::query()->findOrFail($id)->path))->toBeTrue();

    $this->actingAs(fileUser('Rui'))
        ->post(route('filament.admin.chatai.files.store', $conversation), ['file' => UploadedFile::fake()->createWithContent('x.csv', 'a')])
        ->assertForbidden();

    $this->actingAs($ana)->withHeader('Accept', 'application/json')
        ->post(route('filament.admin.chatai.files.store', $conversation), ['file' => UploadedFile::fake()->create('virus.exe', 10)])
        ->assertUnprocessable();
});

it('hands attached files to the model with the question', function () {
    $ana = fileUser();
    $conversation = Conversation::start($ana, 'admin');
    $csv = $this->actingAs($ana)->post(route('filament.admin.chatai.files.store', $conversation), ['file' => UploadedFile::fake()->createWithContent('vendas.csv', "mes,total\nJan,10\n")])->json('id');
    $pdf = $this->actingAs($ana)->post(route('filament.admin.chatai.files.store', $conversation), ['file' => UploadedFile::fake()->createWithContent('fatura.pdf', '%PDF-1.4 test')])->json('id');
    $llm = FakeLlmClient::script([['Li os dois.']]);
    app()->instance(LlmClient::class, $llm);

    $this->actingAs($ana)->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Resume estes ficheiros', 'attachments' => [$csv, $pdf]])->assertOk()->streamedContent();

    $question = collect($llm->requests[0]['messages'])->where('role', 'user')->last()['content'];

    expect($question[0])->toBe(['type' => 'text', 'text' => 'Resume estes ficheiros'])
        ->and($question[1]['type'])->toBe('text')->and($question[1]['text'])->toContain('vendas.csv')->toContain('Jan,10')
        ->and($question[2]['type'])->toBe('file')->and($question[2]['file']['filename'])->toBe('fatura.pdf')
        ->and(ConversationFile::query()->whereNull('message_id')->count())->toBe(0);
});

it('turns file and image parts into Claude document and image blocks', function () {
    $params = (new AnthropicClient)->params([['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'Olha'],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBOR']],
        ['type' => 'file', 'file' => ['filename' => 'a.pdf', 'file_data' => 'data:application/pdf;base64,JVBER']],
    ]]], [], new LlmConfig('', 'k', 'claude-opus-5-5', provider: 'anthropic'));

    expect($params['messages'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Olha'],
        ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/png', 'data' => 'iVBOR']],
        ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => 'JVBER'], 'title' => 'a.pdf'],
    ]);
});

it('generates a document the user can download and nobody else', function (string $format, string $signature) {
    $ana = fileUser();
    $conversation = Conversation::start($ana, 'admin');
    app()->instance(LlmClient::class, FakeLlmClient::script([
        [FakeLlmClient::call('create_document', ['title' => 'Vendas de Outubro', 'format' => $format, 'columns' => ['Mês', 'Total'], 'rows' => [['Out', 1200]], 'content' => "# Vendas\n\nOutubro: **1200**"])],
        ['Aqui está o documento.'],
    ]));

    $body = $this->actingAs($ana)->post(route('filament.admin.chatai.messages.store', $conversation), ['message' => 'Gera o relatório'])->streamedContent();
    $file = ConversationFile::query()->where('kind', ConversationFile::KIND_GENERATED)->sole();

    expect($body)->toContain('"type":"file"')->toContain('Vendas de Outubro')
        ->and($file->name)->toEndWith('.'.$format);

    $download = $this->actingAs($ana)->get(route('filament.admin.chatai.files.download', $file));
    $download->assertOk();
    expect(substr($download->streamedContent(), 0, strlen($signature)))->toBe($signature);

    $this->actingAs(fileUser('Rui'))->get(route('filament.admin.chatai.files.download', $file))->assertForbidden();
})->with([
    'csv' => ['csv', "\u{FEFF}Mês"],
    'xlsx' => ['xlsx', 'PK'],
    'md' => ['md', '# Vendas'],
    'pdf' => ['pdf', '%PDF'],
]);

it('recognises links to records of this system in the question', function () {
    Route::get('/admin/widgets/{record}/edit', EditWidgetPage::class.'@show');

    expect(UrlContext::fromMessage('Vê este http://localhost/admin/widgets/42/edit e https://google.com/x'))
        ->toBe([['url' => 'http://localhost/admin/widgets/42/edit', 'resource' => 'Widget', 'record_id' => '42']]);
});

it('keeps files on the disk chosen on the settings page', function () {
    Storage::fake('s3');
    ChatAiSettings::save(['files.disk' => 's3']);
    ChatAiSettings::flush();
    $ana = fileUser();
    $conversation = Conversation::start($ana, 'admin');

    $id = $this->actingAs($ana)->post(route('filament.admin.chatai.files.store', $conversation), ['file' => UploadedFile::fake()->createWithContent('a.txt', 'olá')])->json('id');
    $file = ConversationFile::query()->findOrFail($id);

    expect($file->disk)->toBe('s3')->and(Storage::disk('s3')->exists($file->path))->toBeTrue();
});
