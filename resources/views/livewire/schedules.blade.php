<div wire:poll.30s>
    <h1>Schedules</h1>
    @if ($flash)<div class="flash">{{ $flash }}</div>@endif

    <div class="toolbar">
        <button class="btn" wire:click="$toggle('showCreate')">{{ $showCreate ? 'close' : '+ new schedule' }}</button>
    </div>

    @if ($showCreate)
        <div class="card" style="margin-bottom:16px">
            <div class="toolbar">
                <input type="text" wire:model="name" placeholder="name (unique)">
                <input type="text" wire:model="cron" placeholder="cron e.g. 0 3 * * *" style="min-width:160px">
                <select wire:model.live="type">
                    <option value="command">artisan command</option>
                    <option value="job">job class</option>
                </select>
                @if ($type === 'command')
                    <input type="text" wire:model="command" placeholder="cache:prune">
                @else
                    <input type="text" wire:model="job_class" placeholder="App\Jobs\Foo" style="min-width:200px">
                @endif
                <label class="muted"><input type="checkbox" wire:model="idempotent"> idempotent (retry on host-loss)</label>
                <button class="btn" wire:click="create">create</button>
            </div>
            @error('name')<div class="muted" style="color:#ff9ca0">{{ $message }}</div>@enderror
            @error('cron')<div class="muted" style="color:#ff9ca0">{{ $message }}</div>@enderror
            @error('command')<div class="muted" style="color:#ff9ca0">{{ $message }}</div>@enderror
            @error('job_class')<div class="muted" style="color:#ff9ca0">{{ $message }}</div>@enderror
        </div>
    @endif

    <table>
        <thead><tr><th>name</th><th>cron</th><th>target</th><th>enabled</th><th>idem</th><th>next due</th><th></th></tr></thead>
        <tbody>
            @forelse ($schedules as $s)
                <tr>
                    <td>{{ $s->name }}</td>
                    <td><code>{{ $s->cron_expression ?? $s->run_at }}</code></td>
                    <td class="muted">
                        @if ($s->job_class === \JobWarden\Jobs\RunArtisanCommand::class)
                            cmd: <code>{{ data_get($s->params, 'command') }}</code>
                        @else
                            {{ class_basename($s->job_class) }}
                        @endif
                    </td>
                    <td><span class="badge state-{{ $s->enabled ? 'running' : 'stopped' }}">{{ $s->enabled ? 'on' : 'off' }}</span></td>
                    <td class="muted">{{ $s->idempotent ? 'yes' : 'no' }}</td>
                    <td class="muted">{{ optional($s->next_due_at)->diffForHumans() ?? '—' }}</td>
                    <td>
                        <div class="btn-row">
                            <button class="btn" wire:click="runNow('{{ $s->id }}')">run now</button>
                            <button class="btn" wire:click="toggle('{{ $s->id }}')">{{ $s->enabled ? 'disable' : 'enable' }}</button>
                            <button class="btn danger" wire:click="deleteSchedule('{{ $s->id }}')" wire:confirm="Delete schedule {{ $s->name }}?">delete</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">no schedules</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
