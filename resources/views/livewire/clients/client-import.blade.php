<div class="space-y-6">
    @if ($report !== null)
        <x-card class="space-y-4">
            <h2 class="text-2xl">{{ __('clients.import.report_title') }}</h2>
            <p>{{ __('clients.import.report_summary', ['imported' => $report['imported'], 'skipped' => count($report['skipped'])]) }}</p>

            @if ($report['skipped'] !== [])
                @include('livewire.clients.partials.import-problems', ['lines' => $report['skipped'], 'field' => 'reason'])
            @endif

            <div class="flex flex-wrap gap-3">
                <x-button-link :href="route('clients.index')">{{ __('clients.import.back') }}</x-button-link>
            </div>
        </x-card>
    @endif

    <x-card class="space-y-5">
        <div>
            <x-input-label for="import-file" :value="__('clients.import.file')" />
            <input id="import-file" type="file" wire:model="file" accept=".csv,.xlsx"
                   class="mt-2 block w-full text-sm text-ink file:me-4 file:min-h-11 file:rounded-full file:border-0 file:bg-sunken file:px-4 file:text-sm file:font-semibold file:text-ink hover:file:bg-line" />
            <p class="mt-2 text-sm text-ink-muted">{{ __('clients.import.file_hint') }}</p>
            <x-input-error :messages="$errors->get('file')" class="mt-2" />
        </div>

        <div class="sm:w-40">
            <x-input-label for="import-area-code" :value="__('clients.import.area_code')" />
            <x-text-input id="import-area-code" wire:model.live.debounce.500ms="areaCode" inputmode="numeric" maxlength="2" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('areaCode')" class="mt-2" />
        </div>
        <p class="text-sm text-ink-muted">{{ __('clients.import.area_code_hint') }}</p>

        <x-secondary-button wire:click="read" wire:loading.attr="disabled" wire:target="read,file">
            {{ __('clients.import.load') }}
        </x-secondary-button>
    </x-card>

    @if ($headers !== [])
        <x-card class="space-y-4">
            <div>
                <h2 class="text-2xl">{{ __('clients.import.mapping_title') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ __('clients.import.mapping_hint') }}</p>
                @if ($truncated)
                    <p class="mt-2 text-sm text-ochre">{{ __('clients.import.truncated') }}</p>
                @endif
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($headers as $column => $header)
                    <div wire:key="mapping-{{ $column }}">
                        <x-input-label :for="'mapping-'.$column" :value="$header !== '' ? $header : __('clients.import.unnamed_column', ['number' => $column + 1])" />
                        <x-select-input :id="'mapping-'.$column" wire:model.live="mapping.{{ $column }}" class="mt-1 block w-full">
                            @foreach ($targets as $target)
                                <option value="{{ $target }}">{{ __('clients.import.targets.'.$target) }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                @endforeach
            </div>
        </x-card>

        @php($preview = $this->preview)

        <x-card class="space-y-4">
            <h2 class="text-2xl">{{ __('clients.import.preview_title') }}</h2>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-ink-muted">
                            <th class="py-2 pe-3 text-start font-medium">{{ __('clients.import.row') }}</th>
                            @foreach ($headers as $column => $header)
                                @continue(($mapping[$column] ?? 'ignore') === 'ignore')
                                <th class="py-2 pe-3 text-start font-medium">{{ $header }}</th>
                            @endforeach
                            <th class="py-2 text-start font-medium">{{ __('clients.import.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($preview['rows'] as $line)
                            <tr wire:key="preview-{{ $line['row'] }}">
                                <td class="py-2 pe-3 text-ink-muted">{{ $line['row'] }}</td>
                                @foreach ($headers as $column => $header)
                                    @continue(($mapping[$column] ?? 'ignore') === 'ignore')
                                    <td class="max-w-48 truncate py-2 pe-3">{{ $line['values'][$column] ?? '' }}</td>
                                @endforeach
                                <td class="py-2 {{ $line['problem'] === null ? 'text-moss' : 'text-danger' }}">{{ $line['problem'] ?? __('clients.import.status_ok') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($preview['problems'] !== [])
                <h3 class="text-lg">{{ trans_choice('clients.import.problems_title', count($preview['problems']), ['count' => count($preview['problems'])]) }}</h3>
                @include('livewire.clients.partials.import-problems', ['lines' => $preview['problems'], 'field' => 'problem'])
            @endif

            <p class="text-sm text-ink-muted">{{ __('clients.import.no_contact_hint') }}</p>

            <x-primary-button wire:click="import" wire:loading.attr="disabled" wire:target="import" :disabled="$preview['importable'] === 0">
                {{ trans_choice('clients.import.import_button', $preview['importable'], ['count' => $preview['importable']]) }}
            </x-primary-button>
        </x-card>
    @endif
</div>
