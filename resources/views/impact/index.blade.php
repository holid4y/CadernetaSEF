@php
    $rate = $data['attendance_rate'] === null ? '—' : round($data['attendance_rate'] * 100).'%';
    $people = $data['individuals'] + $data['organizations'];
    $individualShare = $people > 0 ? round($data['individuals'] / $people * 100) : 0;
    $busiestMonth = max(1, ...array_column($data['people_by_month'], 'count'));
    $query = array_filter(['periodo' => $period, 'de' => request('de'), 'ate' => request('ate')]);
@endphp

<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header :title="__('nav.impact')" subtitle="Indicadores do projeto para os relatórios.">
            <x-slot name="actions">
                <a href="{{ route('impact.export', $query) }}" class="press inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-line bg-surface px-5 py-2.5 text-sm font-semibold text-ink hover:bg-sunken">
                    Exportar em CSV
                </a>
            </x-slot>
        </x-page-header>

        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Período">
            @foreach (App\Http\Requests\ImpactRequest::PERIODS as $value => $label)
                <a href="{{ route('impact', ['periodo' => $value]) }}" wire:navigate @class([
                    'press inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold',
                    'bg-primary text-on-primary' => $period === $value,
                    'border border-line bg-surface text-ink hover:bg-sunken' => $period !== $value,
                ]) @if ($period === $value) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </div>

        @if ($period === 'datas')
            <form method="GET" action="{{ route('impact') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="periodo" value="datas">
                <div>
                    <x-input-label for="impact-from" value="De" />
                    <x-text-input id="impact-from" name="de" type="date" :value="$from->format('Y-m-d')" class="mt-1 block" />
                </div>
                <div>
                    <x-input-label for="impact-until" value="Até" />
                    <x-text-input id="impact-until" name="ate" type="date" :value="$until->format('Y-m-d')" class="mt-1 block" />
                </div>
                <x-primary-button>Ver</x-primary-button>
            </form>
            <x-input-error :messages="array_merge($errors->get('de'), $errors->get('ate'))" />
        @endif

        <p class="text-sm text-ink-muted">{{ $from->format('d/m/Y') }} a {{ $until->format('d/m/Y') }}</p>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-stat-tile :value="$data['total']" label="atendimentos no período" />
            <x-stat-tile :value="$data['unique_clients']" label="pessoas atendidas (únicas)" tone="moss" />
            <x-stat-tile :value="$data['status']['completed']" label="concluídos" tone="plum" />
            <x-stat-tile :value="$rate" label="taxa de comparecimento" tone="ochre" />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-card class="space-y-4">
                <h2 class="text-2xl">Pessoas atendidas por mês</h2>
                <div class="flex h-48 items-end gap-3" role="img" aria-label="{{ collect($data['people_by_month'])->map(fn ($month) => $month['label'].': '.$month['count'])->implode(', ') }}">
                    @foreach ($data['people_by_month'] as $month)
                        <div class="flex flex-1 flex-col items-center gap-1 text-xs">
                            <span class="font-semibold">{{ $month['count'] }}</span>
                            <span class="w-full rounded-t-lg bg-primary" style="height: {{ round($month['count'] / $busiestMonth * 140) }}px"></span>
                            <span class="text-ink-muted">{{ $month['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <x-card class="space-y-4">
                <h2 class="text-2xl">Pessoa física e jurídica</h2>
                <div class="flex h-3 overflow-hidden rounded-full bg-ochre" role="img" aria-label="{{ $data['individuals'] }} pessoas físicas e {{ $data['organizations'] }} pessoas jurídicas">
                    <span class="h-full bg-primary" style="width: {{ $individualShare }}%"></span>
                </div>
                <p class="text-sm text-ink-muted">Pessoa física: <b class="text-ink">{{ $data['individuals'] }}</b> · Pessoa jurídica: <b class="text-ink">{{ $data['organizations'] }}</b></p>
                <div class="grid grid-cols-2 gap-3">
                    <x-stat-tile :value="$data['mei']" label="atendimentos de MEI" tone="clay" />
                    <x-stat-tile :value="$data['third_sector']" label="ONGs e terceiro setor" tone="moss" />
                </div>
            </x-card>

            @foreach ([['Demandas mais frequentes', array_slice($data['demands'], 0, 5), 'bg-primary'], ['Atendimentos por responsável', $data['by_responsible'], 'bg-moss']] as [$title, $items, $color])
                <x-card class="space-y-4">
                    <h2 class="text-2xl">{{ $title }}</h2>
                    @forelse ($items as $item)
                        <div>
                            <div class="flex justify-between gap-3 text-sm"><span class="min-w-0 break-words">{{ $item['name'] }}</span><b>{{ $item['count'] }}</b></div>
                            <div class="mt-1 h-2 rounded-full bg-sunken"><div class="h-full rounded-full {{ $color }}" style="width: {{ round($item['count'] / max(1, $items[0]['count']) * 100) }}%"></div></div>
                        </div>
                    @empty
                        <p class="text-sm text-ink-muted">Nenhum atendimento no período.</p>
                    @endforelse
                </x-card>
            @endforeach

            <x-card class="space-y-3 lg:col-span-2">
                <h2 class="text-2xl">Por situação</h2>
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach (App\Domain\Appointments\Enums\AppointmentStatus::cases() as $status)
                        <li class="flex items-center justify-between gap-2 rounded-soft border border-line px-3 py-2 text-sm">
                            <x-status-badge :status="$status" />
                            <b>{{ $data['status'][$status->value] }}</b>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-app-layout>
