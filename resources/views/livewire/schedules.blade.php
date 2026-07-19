<div class="view">
    <div class="toolbar">
        <span class="info">{{ $schedules->count() }} schedule{{ $schedules->count() === 1 ? '' : 's' }} · {{ $enabledCount }} enabled · the scheduler tier runs on the `scheduled` lane</span>
        <div class="right">
            <button type="button" class="btn btn-accent" wire:click="$set('showCreate', true)">+ New schedule</button>
        </div>
    </div>

    <div class="tbl-head sched-grid" style="padding:0 16px">
        <span>On</span><span>Name</span><span>Cron</span><span>Next due</span><span>Last</span><span>Policy</span><span>Lane</span>
    </div>
    <div class="tbl-scroll">
        @forelse ($schedules as $s)
            <div class="tbl-row sched-grid click" style="padding:0 16px;min-height:42px" data-jw-href="{{ route('jobwarden.schedules.show', $s->id) }}" wire:key="sched-{{ $s->id }}">
                <button type="button" class="switch {{ $s->enabled ? 'on' : '' }}" wire:click="toggle('{{ $s->id }}')" title="{{ $s->enabled ? 'Disable' : 'Enable' }}">
                    <span class="knob"></span>
                </button>
                <div class="cell-main">
                    <div class="t">{{ $s->name }}</div>
                    <div class="s">
                        @if ($s->job_class === \JobWarden\Jobs\RunArtisanCommand::class)
                            {{ data_get($s->params, 'command') }}
                        @else
                            {{ $s->job_class }}
                        @endif
                    </div>
                </div>
                <span class="cell-mono">{{ $s->cron_expression ?? 'once' }}</span>
                <span class="cell-mono">@include('jobwarden::partials.time', ['ms' => $s->next_due_at_ms])</span>
                <span class="cell-dim">@include('jobwarden::partials.time', ['ms' => $s->last_enqueued_for_ms])</span>
                <span class="cell-dim" style="font-size:10px">{{ $s->missed_policy }}/{{ $s->overlap_policy }}</span>
                <span>@include('jobwarden::partials.lane-badge', ['lane' => 'scheduled'])</span>
            </div>
        @empty
            <div class="empty">No schedules yet — create one.</div>
        @endforelse
    </div>

    {{-- create modal --}}
    @if ($showCreate)
        <div class="modal-ov" wire:click.self="$set('showCreate', false)" wire:keydown.escape.window="$set('showCreate', false)">
            <div class="modal">
                <div class="modal-head">
                    <b>New schedule</b>
                    <span class="pill">POST /schedules</span>
                    <button type="button" class="x" wire:click="$set('showCreate', false)">✕</button>
                </div>
                <div class="modal-body">
                    <div>
                        <div class="f-label">Name</div>
                        <input class="f-input" type="text" wire:model="name" placeholder="nightly-reconcile">
                        @error('name')<div class="f-err">{{ $message }}</div>@enderror
                    </div>
                    <div class="f-2col">
                        <div>
                            <div class="f-label">Cron (5-field)</div>
                            <input class="f-input mono" type="text" wire:model="cron" placeholder="0 3 * * *">
                            @error('cron')<div class="f-err">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <div class="f-label">Timezone</div>
                            <select class="f-select mono" wire:model="timezone">
                                @foreach (timezone_identifiers_list() as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                            @error('timezone')<div class="f-err">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div>
                        <div class="f-label">Type</div>
                        <div class="seg">
                            <button type="button" class="{{ $type === 'command' ? 'on' : '' }}" wire:click="$set('type', 'command')">command</button>
                            <button type="button" class="{{ $type === 'job' ? 'on' : '' }}" wire:click="$set('type', 'job')">job</button>
                        </div>
                    </div>
                    @if ($type === 'command')
                        <div>
                            <div class="f-label">Artisan command</div>
                            <input class="f-input mono" type="text" wire:model="command" placeholder="metrics:daily --all-stores">
                            @error('command')<div class="f-err">{{ $message }}</div>@enderror
                        </div>
                    @else
                        <div>
                            <div class="f-label">Job class</div>
                            <input class="f-input mono" type="text" wire:model="job_class" placeholder="App\Jobs\ReconcileInventory">
                            @error('job_class')<div class="f-err">{{ $message }}</div>@enderror
                        </div>
                    @endif
                    <div class="f-3col">
                        <div>
                            <div class="f-label">Idempotent</div>
                            <select class="f-select mono" wire:model="idempotent">
                                <option value="0">false</option>
                                <option value="1">true</option>
                            </select>
                        </div>
                        <div>
                            <div class="f-label">Missed</div>
                            <select class="f-select mono" wire:model="missed_policy">
                                <option value="run_latest">run_latest</option>
                                <option value="run_all">run_all</option>
                                <option value="skip">skip</option>
                                <option value="coalesce">coalesce</option>
                            </select>
                        </div>
                        <div>
                            <div class="f-label">Overlap</div>
                            <select class="f-select mono" wire:model="overlap_policy">
                                <option value="skip">skip</option>
                                <option value="allow">allow</option>
                                <option value="queue">queue</option>
                            </select>
                        </div>
                    </div>
                    <div class="footnote" style="margin:0">A non-idempotent schedule's lost run parks for an operator; idempotent runs retry on another host.</div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn" wire:click="$set('showCreate', false)">Cancel</button>
                    <button type="button" class="btn btn-accent" wire:click="create">Create schedule</button>
                </div>
            </div>
        </div>
    @endif
</div>
