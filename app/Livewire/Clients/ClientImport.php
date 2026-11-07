<?php

declare(strict_types=1);

namespace App\Livewire\Clients;

use App\Domain\Clients\Actions\ImportClients;
use App\Models\Client;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

class ClientImport extends Component
{
    use WithFileUploads;

    private const PREVIEW_ROWS = 15;

    public ?TemporaryUploadedFile $file = null;

    /** @var list<string> */
    public array $headers = [];

    /** @var array<int, string> column => target */
    public array $mapping = [];

    public string $areaCode = '51';

    public bool $truncated = false;

    /** @var ?array{imported: int, skipped: list<array{row: int, name: string, reason: string}>} */
    public ?array $report = null;

    public function mount(): void
    {
        $this->authorize('create', Client::class);
    }

    public function read(): void
    {
        $this->authorize('create', Client::class);
        $this->validate([
            'file' => ['required', 'file', 'max:1024', 'extensions:csv,xlsx'],
            'areaCode' => ['nullable', 'digits:2'],
        ]);

        try {
            $all = SpreadsheetReader::read($this->file->getRealPath(), $this->file->getClientOriginalExtension(), ImportClients::MAX_ROWS + 2);
        } catch (RuntimeException) {
            $this->addError('file', __('clients.import.unreadable'));

            return;
        }

        if (count($all) < 2) {
            $this->addError('file', __('clients.import.empty_file'));

            return;
        }

        $this->headers = array_shift($all);
        $this->truncated = count($all) > ImportClients::MAX_ROWS;
        $this->mapping = ImportClients::guessMapping($this->headers);
        $this->report = null;

        // The rows hold CPF and addresses: they wait in the cache, encrypted,
        // instead of travelling with every request of the page.
        Cache::put($this->cacheKey(), Crypt::encrypt(array_slice($all, 0, ImportClients::MAX_ROWS)), now()->addHour());
    }

    public function import(ImportClients $import): void
    {
        $this->authorize('create', Client::class);
        $this->validate([
            'areaCode' => ['nullable', 'digits:2'],
            'mapping.*' => [Rule::in(ImportClients::TARGETS)],
        ]);

        // Taken out of the cache, so a second click finds nothing to import.
        $rows = Cache::pull($this->cacheKey());

        if ($rows === null) {
            $this->addError('file', __('clients.import.upload_first'));

            return;
        }

        $this->report = $import->handle($this->headers, Crypt::decrypt($rows), $this->mapping, $this->areaCode ?: null);
        $this->reset('file', 'headers', 'mapping', 'truncated');
    }

    /**
     * @return array{rows: list<array{row: int, values: list<string>, problem: ?string}>, problems: list<array{row: int, name: string, problem: string}>, importable: int}
     */
    #[Computed]
    public function preview(): array
    {
        $encrypted = Cache::get($this->cacheKey());
        $rows = $encrypted === null ? [] : Crypt::decrypt($encrypted);
        $lines = app(ImportClients::class)->check($this->headers, $rows, $this->mapping, $this->areaCode ?: null);
        $problems = array_values(array_filter($lines, fn (array $line): bool => $line['problem'] !== null));

        return [
            'rows' => array_map(fn (array $line): array => [
                'row' => $line['row'],
                'values' => $rows[$line['row'] - 2],
                'problem' => $line['problem'],
            ], array_slice($lines, 0, self::PREVIEW_ROWS)),
            'problems' => array_map(fn (array $line): array => [
                'row' => $line['row'],
                'name' => $line['input']['name'],
                'problem' => $line['problem'],
            ], $problems),
            'importable' => count($lines) - count($problems),
        ];
    }

    public function render(): View
    {
        return view('livewire.clients.client-import', [
            'targets' => ImportClients::TARGETS,
        ]);
    }

    private function cacheKey(): string
    {
        return 'client-import.'.auth()->id().'.'.$this->getId();
    }
}
