<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>JobWarden</title>
    @livewireStyles
    <style>
        :root {
            --bg:#0d0f14; --panel:#161a22; --panel2:#1d222c; --line:#262c38;
            --text:#d7dee9; --muted:#8a93a3; --accent:#6ea8fe;
        }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--text);
            font:14px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
        a { color:var(--accent); text-decoration:none; }
        code { font-family:ui-monospace,"SF Mono",Menlo,monospace; font-size:12px; }
        .topbar { display:flex; align-items:center; gap:24px; padding:0 20px; height:52px;
            background:var(--panel); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:5; }
        .brand { font-weight:700; letter-spacing:.3px; }
        .brand .dot { color:var(--accent); }
        .tabs { display:flex; gap:4px; }
        .tabs a { padding:6px 12px; border-radius:6px; color:var(--muted); }
        .tabs a:hover { color:var(--text); background:var(--panel2); }
        .tabs a.active { color:var(--text); background:var(--panel2); }
        .content { max-width:1200px; margin:24px auto; padding:0 20px; }
        h1 { font-size:18px; margin:0 0 16px; font-weight:600; }
        h2 { font-size:14px; color:var(--muted); margin:24px 0 10px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:14px 16px; }
        .card .n { font-size:26px; font-weight:700; }
        .card .l { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.4px; }
        table { width:100%; border-collapse:collapse; background:var(--panel); border:1px solid var(--line);
            border-radius:10px; overflow:hidden; }
        th,td { text-align:left; padding:9px 12px; border-bottom:1px solid var(--line); white-space:nowrap; }
        th { color:var(--muted); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.4px; background:var(--panel2); }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:var(--panel2); }
        .badge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600;
            background:#2a3140; color:#aab4c4; }
        .state-running,.state-dispatched { background:#11315e; color:#8ec1ff; }
        .state-succeeded { background:#0f3d2a; color:#7ee0a8; }
        .state-failed { background:#48171a; color:#ff9ca0; }
        .state-orphaned { background:#4a2c0c; color:#ffc078; }
        .state-retrying,.state-partial { background:#473c0c; color:#ffe07a; }
        .state-canceled,.state-stopped { background:#33260a; color:#d6b478; }
        .state-pending,.state-queued { background:#2a3140; color:#aab4c4; }
        .muted { color:var(--muted); }
        .btn { display:inline-block; padding:4px 10px; border:1px solid var(--line); border-radius:6px;
            background:var(--panel2); color:var(--text); cursor:pointer; font-size:12px; }
        .btn:hover { border-color:var(--accent); color:#fff; }
        .btn.danger:hover { border-color:#ff6b6b; color:#ff9ca0; }
        .btn-row { display:flex; gap:6px; flex-wrap:wrap; }
        input,select { background:var(--panel2); border:1px solid var(--line); color:var(--text);
            border-radius:6px; padding:6px 9px; font:inherit; }
        input::placeholder { color:var(--muted); }
        .toolbar { display:flex; gap:8px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
        .pager { margin-top:12px; }
        .logs { background:#0a0c10; border:1px solid var(--line); border-radius:8px; padding:12px;
            font-family:ui-monospace,Menlo,monospace; font-size:12px; max-height:340px; overflow:auto; }
        .logs .ln { white-space:pre-wrap; }
        .logs .lvl-error,.logs .lvl-critical { color:#ff9ca0; }
        .logs .lvl-warning { color:#ffe07a; }
        .kv { display:grid; grid-template-columns:160px 1fr; gap:4px 16px; }
        .kv .k { color:var(--muted); }
        .flash { background:#0f3d2a; color:#7ee0a8; border:1px solid #14532d; padding:8px 12px;
            border-radius:8px; margin-bottom:14px; }
        pre.json { background:#0a0c10; border:1px solid var(--line); border-radius:8px; padding:10px;
            font-size:12px; overflow:auto; color:#aab4c4; }
        .err { background:var(--panel); border:1px solid var(--line); border-left:3px solid #ff6b6b;
            border-radius:8px; padding:12px 14px; }
        .err-class { font-family:ui-monospace,Menlo,monospace; font-weight:700; color:#ff9ca0; }
        .err-msg { margin-top:4px; }
        .err-file { font-size:12px; margin-top:6px; }
        pre.json.trace { margin:10px 0 0; line-height:1.7; max-height:360px; }
        .sec-head { display:flex; align-items:center; justify-content:space-between; margin:24px 0 10px; }
        .sec-head h2 { margin:0; }
        .modal-overlay { position:fixed; inset:0; z-index:20; background:rgba(0,0,0,.6);
            display:flex; align-items:center; justify-content:center; padding:24px; }
        .modal { background:var(--panel); border:1px solid var(--line); border-radius:12px;
            width:min(1040px,100%); max-height:85vh; display:flex; flex-direction:column; padding:16px 18px; }
        .modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
        .modal-head h2 { margin:0; }
        .modal-note { font-size:12px; margin-bottom:10px; }
        .modal-logs { max-height:none; flex:1; }
    </style>
</head>
<body>
    <nav class="topbar">
        <div class="brand"><span class="dot">▸</span> JobWarden</div>
        <div class="tabs">
            @php($is = fn ($n) => request()->routeIs($n) ? 'active' : '')
            <a class="{{ $is('jobwarden.overview') }}" href="{{ route('jobwarden.overview') }}">Overview</a>
            <a class="{{ $is('jobwarden.jobs') }}" href="{{ route('jobwarden.jobs') }}">Jobs</a>
            <a class="{{ $is('jobwarden.batches') }}" href="{{ route('jobwarden.batches') }}">Batches</a>
            <a class="{{ $is('jobwarden.schedules') }}" href="{{ route('jobwarden.schedules') }}">Schedules</a>
            <a class="{{ $is('jobwarden.workers') }}" href="{{ route('jobwarden.workers') }}">Workers</a>
        </div>
    </nav>
    <main class="content">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
