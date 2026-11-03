@if ($summary === [])
    <span style="color:var(--gray-500)">Belum ada label yang dipasang dalam 30 hari terakhir.</span>
@else
    <table style="font-size:.875rem;border-collapse:collapse">
        <thead>
            <tr style="text-align:left;color:var(--gray-500)">
                <th style="padding:.2rem .9rem .2rem 0">Label</th>
                @foreach (['sent', 'failed', 'skipped'] as $s)
                    <th style="padding:.2rem .9rem .2rem 0">{{ ucfirst($statuses[$s]) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($summary as $label => $counts)
                <tr>
                    <td style="padding:.2rem .9rem .2rem 0;font-weight:600">{{ $label }}</td>
                    @foreach (['sent', 'failed', 'skipped'] as $s)
                        <td style="padding:.2rem .9rem .2rem 0">{{ $counts[$s] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
