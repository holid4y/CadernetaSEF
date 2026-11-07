<div class="max-h-96 overflow-y-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-ink-muted">
                <th class="py-2 pe-3 text-start font-medium">{{ __('clients.import.row') }}</th>
                <th class="py-2 pe-3 text-start font-medium">{{ __('clients.import.skipped_name') }}</th>
                <th class="py-2 text-start font-medium">{{ __('clients.import.reason') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @foreach ($lines as $line)
                <tr>
                    <td class="py-2 pe-3 text-ink-muted">{{ $line['row'] }}</td>
                    <td class="py-2 pe-3">{{ $line['name'] !== '' ? $line['name'] : '—' }}</td>
                    <td class="py-2 text-danger">{{ $line[$field] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
