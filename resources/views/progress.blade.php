{{-- Streamed into the table while Vibefilter scores rows. --}}
@php($percent = $total > 0 ? (int) round($done / $total * 100) : 0)
<div
    role="status"
    style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; padding: 0.625rem 1rem; font-size: 0.875rem; border-top: 1px solid color-mix(in oklab, currentColor 10%, transparent);"
>
    <span style="font-weight: 500; white-space: nowrap;">{{ trans_choice('vibefilter::vibefilter.progress.scoring', $rows, ['count' => \Vibefilter\Filament\Support\Numbers::format($rows)]) }}</span>

    <div
        role="progressbar"
        aria-label="{{ __('vibefilter::vibefilter.progress.label') }}"
        aria-valuemin="0"
        aria-valuemax="{{ $total }}"
        aria-valuenow="{{ $done }}"
        style="flex: 1 1 8rem; min-width: 6rem; height: 0.375rem; border-radius: 9999px; overflow: hidden; background: color-mix(in oklab, currentColor 12%, transparent);"
    >
        <div style="height: 100%; width: {{ $percent }}%; border-radius: 9999px; background: var(--primary-500); transition: width 0.3s;"></div>
    </div>

    <span style="white-space: nowrap; font-variant-numeric: tabular-nums; opacity: 0.75;">
        {{ trans_choice('vibefilter::vibefilter.progress.requests', $total, ['done' => $done, 'total' => $total]) }}
        @if ($retries)
            · {{ trans_choice('vibefilter::vibefilter.progress.retried', $retries, ['count' => $retries]) }}
        @endif
        @if (isset($cost))
            · {{ __('vibefilter::vibefilter.report.cost', ['amount' => \Vibefilter\Filament\Support\Numbers::money($cost)]) }}
        @endif
    </span>
</div>
