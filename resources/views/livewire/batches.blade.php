<div wire:poll.{{ config('jobwarden.dashboard.poll', '5s') }}>
    <h1>Batches</h1>
    @if ($flash)<div class="flash">{{ $flash }}</div>@endif
    <table>
        <thead><tr><th>id</th><th>name</th><th>state</th><th>progress</th><th>policy</th><th>created</th><th></th></tr></thead>
        <tbody>
            @forelse ($batches as $b)
                <tr>
                    <td><a href="{{ route('jobwarden.jobs') }}?batch_id={{ $b->id }}"><code>{{ \Illuminate\Support\Str::substr($b->id,0,8) }}</code></a></td>
                    <td>{{ $b->name }}</td>
                    <td><span class="badge state-{{ $b->state->value }}">{{ $b->state->value }}</span></td>
                    <td class="muted">{{ $b->succeeded_count }}✓ {{ $b->failed_count }}✗ {{ $b->canceled_count }}⊘ / {{ $b->total_jobs }}</td>
                    <td class="muted">{{ $b->failure_policy }}</td>
                    <td class="muted">@include('jobwarden::partials.time', ['ms' => $b->created_at_ms, 'mode' => 'relative'])</td>
                    <td>
                        @if ($b->state->value === 'running')
                            <button class="btn danger" wire:click="cancel('{{ $b->id }}')" wire:confirm="Cancel this batch and all its members?">cancel</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">no batches</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
