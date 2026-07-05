{{-- The whole design system, inline (zero-build). Tokens follow the design
     handoff exactly: light values on :root, dark via [data-theme="dark"],
     density via [data-density]. --}}
<style>
/* ---------- tokens ---------- */
:root{
  --bg:#f4f4f2;--panel:#ffffff;--panel-2:#fafaf8;--panel-3:#f1f0ed;--border:#e6e5e1;--border-2:#d8d7d2;
  --fg:#191917;--fg-2:#65645d;--fg-3:#9c9b91;--hover:#eeede9;--sel:#eaf0fb;--sel-bd:#c7d7f3;
  --accent:#3b6fd4;--accent-fg:#ffffff;--accent-soft:#e7effb;
  --font:'IBM Plex Sans',system-ui,-apple-system,sans-serif;
  --mono:'IBM Plex Mono',ui-monospace,Menlo,monospace;
  --shadow:0 1px 2px rgba(20,20,20,.05),0 1px 3px rgba(20,20,20,.04);
  --shadow-lg:0 10px 30px rgba(20,20,20,.14),0 2px 6px rgba(20,20,20,.08);
  --h-green-bg:#e6f4ec;--h-green-fg:#177d44;--h-green-dot:#22a05a;
  --h-red-bg:#fdece9;--h-red-fg:#c0392b;--h-red-dot:#e0483a;
  --h-blue-bg:#e8effc;--h-blue-fg:#2b5fd0;--h-blue-dot:#3b6fd4;
  --h-amber-bg:#fbf0d9;--h-amber-fg:#986006;--h-amber-dot:#d6890f;
  --h-purple-bg:#f0e9fc;--h-purple-fg:#6a3ebf;--h-purple-dot:#8a51e0;
  --h-slate-bg:#eceff2;--h-slate-fg:#485560;--h-slate-dot:#647581;
  --h-gray-bg:#edece8;--h-gray-fg:#69685f;--h-gray-dot:#98978d;
  --h-teal-bg:#e1f3f0;--h-teal-fg:#0f7267;--h-teal-dot:#1f9c8f;
  --row-h:32px;--fs:12.5px;--tfs:12px;
}
[data-theme="dark"]{
  --bg:#09090a;--panel:#131315;--panel-2:#0e0e10;--panel-3:#191a1c;--border:#242427;--border-2:#323236;
  --fg:#ededec;--fg-2:#9c9b97;--fg-3:#67665f;--hover:#1b1b1e;--sel:#152036;--sel-bd:#2a3f66;
  --accent:#6b93e6;--accent-fg:#0a0a0b;--accent-soft:#172640;
  --shadow:0 1px 2px rgba(0,0,0,.4),0 1px 3px rgba(0,0,0,.3);
  --shadow-lg:0 12px 34px rgba(0,0,0,.55),0 2px 8px rgba(0,0,0,.4);
  --h-green-bg:#12301f;--h-green-fg:#52c483;--h-green-dot:#34b46a;
  --h-red-bg:#391917;--h-red-fg:#f2796b;--h-red-dot:#e0594a;
  --h-blue-bg:#15233f;--h-blue-fg:#7ea6f2;--h-blue-dot:#5b8ae6;
  --h-amber-bg:#33260c;--h-amber-fg:#e3ac4d;--h-amber-dot:#d6890f;
  --h-purple-bg:#221838;--h-purple-fg:#b491f0;--h-purple-dot:#9a6ef0;
  --h-slate-bg:#1d232a;--h-slate-fg:#96a4b2;--h-slate-dot:#6d7c8a;
  --h-gray-bg:#1e1e21;--h-gray-fg:#9b9a97;--h-gray-dot:#6d6d6c;
  --h-teal-bg:#0e2f2b;--h-teal-fg:#4fc4b6;--h-teal-dot:#2fa89a;
}
[data-density="comfortable"]{--row-h:40px;--fs:13.5px;--tfs:13px}

/* ---------- base ---------- */
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%}
body{font-family:var(--font);background:var(--bg);color:var(--fg);font-size:var(--fs);-webkit-font-smoothing:antialiased;line-height:1.5}
input,button,select,textarea{font-family:inherit;color:inherit;font-size:inherit}
button{cursor:pointer;background:none;border:0;padding:0}
a{color:inherit;text-decoration:none}
::-webkit-scrollbar{width:11px;height:11px}
::-webkit-scrollbar-thumb{background:var(--border-2);border-radius:7px;border:3px solid var(--bg)}
::-webkit-scrollbar-thumb:hover{background:var(--fg-3)}
::-webkit-scrollbar-track{background:transparent}
@keyframes jw-pulse{0%,100%{opacity:1}50%{opacity:.28}}
@keyframes jw-spin{to{transform:rotate(360deg)}}
@keyframes jw-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
@keyframes jw-toast{from{opacity:0;transform:translate(-50%,10px)}to{opacity:1;transform:translate(-50%,0)}}
.mono{font-family:var(--mono)}
.muted{color:var(--fg-3)}

/* ---------- app shell ---------- */
.jw-app{display:grid;grid-template-columns:228px 1fr;height:100vh;width:100%;overflow:hidden}
.jw-main{display:flex;flex-direction:column;min-width:0;height:100vh}
.jw-content{flex:1;overflow:auto;position:relative;display:flex;flex-direction:column}
.jw-content>*{flex:1;display:flex;flex-direction:column;min-height:0}
.view{animation:jw-in .18s ease;display:flex;flex-direction:column;min-height:100%}
.view-pad{padding:18px 20px 40px;display:flex;flex-direction:column;gap:16px;flex:1}

/* ---------- sidebar ---------- */
.sb{display:flex;flex-direction:column;background:var(--panel-2);border-right:1px solid var(--border);min-width:0;overflow:hidden}
.sb-brand{display:flex;align-items:center;gap:9px;padding:15px 15px 12px}
.sb-logo{width:27px;height:27px;border-radius:7px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex:none}
.sb-name{font-weight:600;font-size:14px;letter-spacing:-.01em;line-height:1.1}
.sb-sub{font-size:10px;color:var(--fg-3);font-family:var(--mono);letter-spacing:.02em}
.sb-env{margin:0 12px 8px;display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:6px;background:var(--h-amber-bg);color:var(--h-amber-fg)}
.sb-env .sdot{width:6px;height:6px}
.sb-env b{font-family:var(--mono);font-size:10.5px;font-weight:500}
.sb-env span{margin-left:auto;font-size:10px;opacity:.75}
.sb-nav{padding:4px 10px;display:flex;flex-direction:column;gap:2px;flex:1;overflow:auto}
.sb-link{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:7px;font-size:12.5px;color:var(--fg-2);text-align:left}
.sb-link:hover{background:var(--hover);color:var(--fg)}
.sb-link.active{background:var(--accent-soft);color:var(--accent);font-weight:500}
.sb-link svg{flex:none}
.sb-link .grow{flex:1}
.sb-badge{font-family:var(--mono);font-size:10px;font-weight:600;padding:1px 6px;border-radius:999px;background:var(--panel-3);color:var(--fg-3)}
.sb-link.active .sb-badge{background:var(--accent-soft);color:var(--accent)}
.sb-badge.red{background:var(--h-red-bg);color:var(--h-red-fg)}
.sb-foot{padding:10px 12px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:9px}
.sb-health{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--fg-2)}
.sb-health .sdot{box-shadow:0 0 0 3px var(--h-green-bg)}
.sb-health.bad .sdot{box-shadow:0 0 0 3px var(--h-red-bg)}
.sb-tools{display:flex;gap:6px}
.sb-tbtn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:6px;border:1px solid var(--border-2);border-radius:6px;background:var(--panel);color:var(--fg-2);font-size:11px}
.sb-tbtn:hover{background:var(--hover)}
.sb-tbtn.icon{flex:none;width:34px}

/* ---------- topbar ---------- */
.tb{display:flex;align-items:center;gap:12px;padding:0 18px;height:52px;border-bottom:1px solid var(--border);background:var(--panel-2);flex:none}
.tb-left{display:flex;align-items:center;gap:9px;min-width:0}
.tb-title{font-weight:600;font-size:14px;letter-spacing:-.01em;white-space:nowrap}
.tb-pill{font-family:var(--mono);font-size:10.5px;color:var(--fg-3);background:var(--panel-3);border:1px solid var(--border);padding:2px 7px;border-radius:5px;white-space:nowrap}
.tb-mid{flex:1;display:flex;justify-content:center;min-width:0;padding:0 8px}
.tb-search{position:relative;width:100%;max-width:460px}
.tb-search svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.5}
.tb-search input{width:100%;height:32px;padding:0 10px 0 30px;border:1px solid var(--border-2);border-radius:7px;background:var(--panel);font-size:12px;font-family:var(--mono);outline:none}
.tb-search input:focus{border-color:var(--accent)}
.tb-search input::placeholder{color:var(--fg-3)}
.tb-right{display:flex;align-items:center;gap:8px;flex:none}
.tb-updated{font-size:11px;color:var(--fg-3);white-space:nowrap}
.tb-refresh{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border-2);border-radius:7px;background:var(--panel);color:var(--fg-2)}
.tb-refresh:hover{background:var(--hover)}
.tb-refresh.spin svg{animation:jw-spin .7s linear}

/* ---------- badges / dots / chips ---------- */
.sdot{display:inline-block;width:7px;height:7px;border-radius:50%;flex:none;background:var(--fg-3)}
.badge{display:inline-flex;align-items:center;gap:5px;font-family:var(--mono);font-size:10.5px;font-weight:500;padding:2px 8px;border-radius:999px;white-space:nowrap;background:var(--h-slate-bg);color:var(--h-slate-fg)}
.badge .sdot{width:6px;height:6px}
.pulse{animation:jw-pulse 1.4s ease infinite}
@foreach (['green','red','blue','amber','purple','slate','gray','teal'] as $hue)
.badge.h-{{ $hue }}{background:var(--h-{{ $hue }}-bg);color:var(--h-{{ $hue }}-fg)}
.badge.h-{{ $hue }} .sdot{background:var(--h-{{ $hue }}-dot)}
.sdot.h-{{ $hue }}{background:var(--h-{{ $hue }}-dot)}
.fill-{{ $hue }}{background:var(--h-{{ $hue }}-dot)}
.text-{{ $hue }}{color:var(--h-{{ $hue }}-fg)}
@endforeach
.chip{display:inline-flex;align-items:center;gap:6px;font-size:11px;padding:3px 10px;border:1px solid var(--border-2);border-radius:999px;background:var(--panel);color:var(--fg-2);white-space:nowrap}
.chip:hover{border-color:var(--fg-3)}
.chip.on{border-color:var(--accent);background:var(--accent-soft);color:var(--accent);font-weight:500}
.chip .sdot{width:6px;height:6px}
.tagchip{font-family:var(--mono);font-size:12px;padding:3px 9px;border:1px solid var(--border-2);border-radius:6px;background:var(--panel-2);display:inline-flex;gap:1px}
.tagchip i{color:var(--fg-3);font-style:normal}
.tagchip:hover{border-color:var(--accent)}

/* ---------- buttons ---------- */
.btn{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:500;padding:5px 11px;border:1px solid var(--border-2);border-radius:7px;background:var(--panel);color:var(--fg-2);white-space:nowrap}
.btn:hover{background:var(--hover);color:var(--fg)}
.btn.sm{padding:3px 9px;font-size:11px}
.btn-accent{border-color:var(--accent);background:var(--accent);color:var(--accent-fg)}
.btn-accent:hover{filter:brightness(1.06);background:var(--accent);color:var(--accent-fg)}
.btn-purple{border-color:var(--h-purple-dot);background:var(--h-purple-dot);color:#fff}
.btn-purple:hover{filter:brightness(1.06);background:var(--h-purple-dot);color:#fff}
.btn-red{border-color:var(--h-red-dot);background:var(--h-red-bg);color:var(--h-red-fg)}
.btn-red:hover{filter:brightness(1.04);background:var(--h-red-bg);color:var(--h-red-fg)}
.btn-link{font-size:11px;color:var(--accent);padding:2px 4px}
.btn-link:hover{text-decoration:underline}

/* ---------- panels ---------- */
.panel{background:var(--panel);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);overflow:hidden}
.panel-head{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid var(--border)}
.panel-title{font-weight:600;font-size:13px}
.panel-note{font-family:var(--mono);font-size:10.5px;color:var(--fg-3)}
.panel-pad{padding:13px 14px}

/* ---------- KPI tiles ---------- */
.kpi-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}
.kpi{display:flex;flex-direction:column;gap:6px;text-align:left;background:var(--panel);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);padding:12px 14px;cursor:pointer}
.kpi:hover{border-color:var(--border-2)}
.kpi.warn-red{border-color:var(--h-red-dot)}
.kpi.warn-purple{border-color:var(--h-purple-dot)}
.kpi-label{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--fg-2);font-weight:500}
.kpi-label .sdot{width:6px;height:6px}
.kpi-value{font-size:26px;font-weight:600;letter-spacing:-.02em;font-family:var(--mono);line-height:1.1}
.kpi-sub{font-size:10.5px;color:var(--fg-3)}

/* ---------- bars ---------- */
.bar{display:flex;height:9px;border-radius:5px;overflow:hidden;background:var(--panel-3)}
.bar.thin{height:7px;border-radius:4px}
.bar>div{height:100%}
.legend{display:flex;flex-wrap:wrap;gap:8px 14px}
.legend>div{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--fg-2)}
.legend b{font-family:var(--mono);font-size:11px;font-weight:500;color:var(--fg)}
.lane-row{display:flex;align-items:center;gap:10px}
.lane-row .name{font-family:var(--mono);font-size:11.5px;width:104px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lane-row .bar{flex:1}
.lane-row .n{font-family:var(--mono);font-size:11px;font-weight:500;width:44px;text-align:right}

/* ---------- grid tables ---------- */
.tbl-head{display:grid;gap:10px;align-items:center;padding:0 14px;height:32px;border-bottom:1px solid var(--border-2);background:var(--panel-2);font-size:10.5px;font-weight:600;color:var(--fg-3);text-transform:uppercase;letter-spacing:.03em;flex:none}
.tbl-row{display:grid;gap:10px;align-items:center;padding:0 14px;min-height:var(--row-h);border-bottom:1px solid var(--border);font-size:var(--tfs)}
.tbl-row.click{cursor:pointer}
.tbl-row.click:hover{background:var(--hover)}
.tbl-scroll{flex:1;overflow:auto;min-height:0}
.cell-main{min-width:0}
.cell-main .t{font-size:12.5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cell-main .s{font-family:var(--mono);font-size:10px;color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cell-mono{font-family:var(--mono);font-size:11px;color:var(--fg-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cell-dim{font-family:var(--mono);font-size:11px;color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cell-txt{font-size:11px;color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jobs-grid{grid-template-columns:30px 96px minmax(170px,1fr) 110px 110px 54px 66px minmax(110px,168px)}
.batches-grid{grid-template-columns:104px minmax(220px,1.6fr) 104px minmax(120px,1fr) 56px 150px}
.sched-grid{grid-template-columns:46px minmax(200px,1.5fr) 140px 108px 116px 130px 92px}
.workers-grid{grid-template-columns:110px minmax(180px,1fr) 88px 64px 110px 126px 92px}
.attempts-grid{grid-template-columns:44px 104px 1fr 90px 64px 110px}
.runs-grid{grid-template-columns:130px 120px 1fr 150px}
.activity-grid{grid-template-columns:70px 150px 1fr auto;padding-top:7px;padding-bottom:7px}
.members-grid{grid-template-columns:96px minmax(0,1fr) 110px 60px 72px}

/* ---------- filter bar / bulk bar ---------- */
.filterbar{display:flex;flex-direction:column;gap:9px;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--panel-2);flex:none}
.filterrow{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.filterrow .lbl{font-size:11px;color:var(--fg-3);width:38px;flex:none}
.filterrow .sep{width:1px;height:20px;background:var(--border-2);margin:0 6px}
.filterrow select,.filterrow input{height:26px;border:1px solid var(--border-2);border-radius:6px;background:var(--panel);font-family:var(--mono);font-size:11px;padding:0 6px;outline:none}
.filterrow input:focus,.filterrow select:focus{border-color:var(--accent)}
.filterrow .count{margin-left:auto;font-family:var(--mono);font-size:11px;color:var(--fg-3)}
.bulkbar{display:flex;align-items:center;gap:10px;padding:8px 16px;background:var(--sel);border-bottom:1px solid var(--sel-bd);flex:none;position:sticky;top:0;z-index:4}
.bulkbar .n{font-size:12px;font-weight:600;color:var(--accent)}
.bulkbar .clear{margin-left:auto;font-size:11px;color:var(--fg-2)}

/* ---------- checkboxes ---------- */
.cb{position:relative;width:15px;height:15px;display:inline-flex;align-items:center;justify-content:center}
.cb input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer}
.cb .box{width:15px;height:15px;border-radius:4px;border:1.5px solid var(--border-2);background:var(--panel);display:flex;align-items:center;justify-content:center;pointer-events:none}
.cb input:checked+.box{background:var(--accent);border-color:var(--accent)}
.cb svg{opacity:0}
.cb input:checked+.box svg{opacity:1}

/* ---------- detail pages ---------- */
.detail-head{padding:16px 20px 0;border-bottom:1px solid var(--border);flex:none}
.detail-head.pad-b{padding-bottom:14px}
.backlink{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--fg-2);margin-bottom:10px}
.backlink:hover{color:var(--fg)}
.detail-title-row{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap}
.detail-title-row .grow{min-width:0;flex:1}
.detail-title{display:flex;align-items:center;gap:10px;margin-bottom:5px;flex-wrap:wrap}
.detail-title .name{font-size:18px;font-weight:600;letter-spacing:-.01em}
.detail-sub{font-family:var(--mono);font-size:11.5px;color:var(--fg-3);word-break:break-all}
.detail-actions{display:flex;gap:8px}
.meta-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--border);border:1px solid var(--border);border-radius:9px;overflow:hidden;margin:14px 0}
.meta-cell{background:var(--panel);padding:9px 12px;min-width:0}
.meta-cell .k{font-size:10px;color:var(--fg-3);text-transform:uppercase;letter-spacing:.03em;margin-bottom:3px}
.meta-cell .v{font-size:12px;font-family:var(--mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tabs{display:flex;gap:2px}
.tab{padding:7px 12px;font-size:12px;font-weight:500;color:var(--fg-2);border-bottom:2px solid transparent;border-radius:6px 6px 0 0}
.tab:hover{color:var(--fg);background:var(--hover)}
.tab.on{color:var(--fg);border-bottom-color:var(--accent)}
.tab-body{flex:1;overflow:auto;padding:16px 20px 40px;min-height:0}

/* ---------- params / kv ---------- */
.pt-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(220px,1fr);gap:12px;margin-bottom:14px}
.kv-panel{border:1px solid var(--border);border-radius:9px;overflow:hidden;background:var(--panel)}
.kv-head{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--border)}
.kv-head b{font-size:11px;font-weight:600;color:var(--fg-2)}
.kv-head i{font-family:var(--mono);font-size:10px;color:var(--fg-3);font-style:normal}
.kv-body{padding:4px 12px;max-height:200px;overflow:auto}
.kv-row{display:grid;grid-template-columns:132px 1fr;gap:12px;align-items:baseline;padding:6px 0;border-bottom:1px solid var(--border)}
.kv-row:last-child{border-bottom:0}
.kv-row .k{font-family:var(--mono);font-size:11.5px;color:var(--fg-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kv-row .v{font-family:var(--mono);font-size:11.5px;word-break:break-all}
.v-str{color:var(--h-green-fg)}
.v-num{color:var(--h-blue-fg)}
.v-bool{color:var(--h-amber-fg)}
.v-null{color:var(--fg-3)}
.kv-empty{padding:12px;font-size:11.5px;color:var(--fg-3)}

/* ---------- error panel ---------- */
.errpanel{background:var(--h-red-bg);border:1px solid var(--h-red-dot);border-radius:9px;padding:12px 14px;margin-bottom:14px}
.errpanel .head{display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap}
.errpanel .tag{font-family:var(--mono);font-size:11px;font-weight:600;color:var(--h-red-fg)}
.errpanel .cls{font-family:var(--mono);font-size:11px;color:var(--h-red-fg);opacity:.85;word-break:break-all}
.errpanel .msg{font-size:13px;font-weight:500;margin-bottom:6px}
.errpanel .loc{font-family:var(--mono);font-size:11px;color:var(--fg-2);word-break:break-all}
.errpanel .trace{margin-top:8px;padding-top:8px;border-top:1px solid var(--h-red-dot);font-family:var(--mono);font-size:10.5px;color:var(--fg-2);white-space:pre;overflow-x:auto;line-height:1.7}

/* ---------- logs terminal ---------- */
.logbar{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.logbar .note{font-family:var(--mono);font-size:10.5px;color:var(--fg-3)}
.logview{background:#0c0d10;border:1px solid var(--border-2);border-radius:9px;padding:10px 0;font-family:var(--mono);font-size:11.5px;line-height:1.85;overflow:auto;max-height:60vh}
.logline{display:grid;grid-template-columns:40px 78px 66px 1fr;gap:10px;padding:0 14px;white-space:nowrap}
.logline:hover{background:rgba(255,255,255,.03)}
.logline .seq{color:#4a4d55}
.logline .t{color:#7d818b}
.logline .msg{color:#c9ccd4;white-space:pre-wrap;word-break:break-word}
.logline time{color:#7d818b}
.lvl{font-weight:500}
.lvl-debug{color:#5c6068}.lvl-info{color:#7ea6f2}.lvl-notice{color:#4fc4b6}
.lvl-warning{color:#e3ac4d}
.lvl-error,.lvl-critical,.lvl-alert,.lvl-emergency{color:#f2796b}
.logmeta{padding:2px 14px;color:#5c6068;font-size:10.5px}

/* ---------- timeline ---------- */
.tl{display:flex;flex-direction:column;padding-left:6px}
.tl-item{display:grid;grid-template-columns:16px 1fr;gap:12px;padding-bottom:14px;position:relative}
.tl-rail{display:flex;flex-direction:column;align-items:center}
.tl-rail .line{flex:1;width:1px;background:var(--border-2);margin-top:2px}
.tl-item:last-child .line{display:none}
.tl-head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.tl-arrow{font-family:var(--mono);font-size:12px;font-weight:500}
.tl-time{font-family:var(--mono);font-size:10.5px;color:var(--fg-3)}
.tl-actor{font-size:10.5px;color:var(--fg-3);padding:1px 6px;border:1px solid var(--border);border-radius:4px}
.tl-reason{font-size:12px;color:var(--fg-2);margin-top:3px}

/* ---------- result / json ---------- */
.jsonbox{font-family:var(--mono);font-size:12px;line-height:1.7;background:var(--panel-3);border:1px solid var(--border);border-radius:9px;padding:14px;white-space:pre-wrap;word-break:break-word}
.footnote{font-size:11px;color:var(--fg-3);margin-top:8px;max-width:760px;line-height:1.55}

/* ---------- DAG ---------- */
.dag-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:10px;background:var(--panel);padding:14px 16px}
.dag-row{display:grid;gap:6px;align-items:center;min-width:min-content}
.dag-lanehead{font-family:var(--mono);font-size:11.5px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:left;padding:2px 0;color:var(--fg)}
button.dag-lanehead:hover{color:var(--accent)}
.dag-colhead{font-size:9px;color:var(--fg-3);text-align:center;line-height:1.15;padding:0 3px;overflow:hidden}
.dag-cornhead{font-size:10px;font-weight:600;color:var(--fg-3);text-transform:uppercase;letter-spacing:.03em}
.dag-cell{position:relative;display:flex;flex-wrap:wrap;gap:3px;align-items:center;justify-content:center;min-height:34px;padding:4px 2px}
.dag-cell.linked:before{content:'';position:absolute;left:-6px;right:50%;top:50%;height:1px;background:var(--border-2)}
.dag-node{position:relative;z-index:1;display:block;width:26px;height:16px;border-radius:5px}
.dag-node:hover{filter:brightness(1.12);outline:2px solid var(--accent-soft)}
.dag-node.dim{opacity:.32}
.dag-collapsed{display:flex;align-items:center;gap:10px;padding:6px 0}
.dag-collapsed .bar{width:180px}
.dag-collapsed .n{font-family:var(--mono);font-size:10.5px;color:var(--fg-3)}
.count-chips{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.count-chip{display:flex;align-items:center;gap:6px;padding:5px 11px;border:1px solid var(--border);border-radius:8px;background:var(--panel)}
.count-chip span{font-size:11px;color:var(--fg-2)}
.count-chip b{font-family:var(--mono);font-size:13px;font-weight:600}

/* ---------- schedules ---------- */
.switch{width:30px;height:18px;border-radius:999px;background:var(--border-2);display:inline-flex;align-items:center;padding:2px;transition:background .15s;flex:none}
.switch .knob{width:13px;height:13px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.3);transition:transform .15s}
.switch.on{background:var(--h-green-dot)}
.switch.on .knob{transform:translateX(12px)}

/* ---------- toolbar rows ---------- */
.toolbar{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--panel-2);flex-wrap:wrap;flex:none}
.toolbar .info{font-size:11px;color:var(--fg-3);font-family:var(--mono)}
.toolbar .right{margin-left:auto;display:flex;gap:8px;align-items:center}
.sum-chip{display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid var(--border);border-radius:8px;background:var(--panel)}
.sum-chip span{font-family:var(--mono);font-size:11px;color:var(--fg-2)}
.sum-chip b{font-family:var(--mono);font-size:12px;font-weight:600}
.sum-chip.bad{border-color:var(--h-red-dot);background:var(--h-red-bg)}
.sum-chip.bad span,.sum-chip.bad b{color:var(--h-red-fg)}

/* ---------- load bar ---------- */
.loadbar{display:flex;align-items:center;gap:6px}
.loadbar .bar{flex:1;height:6px;border-radius:3px}
.loadbar .cap{font-family:var(--mono);font-size:10px;color:var(--fg-3);white-space:nowrap}

/* ---------- modal ---------- */
.modal-ov{position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.44);display:flex;align-items:center;justify-content:center;padding:24px;animation:jw-in .14s ease}
.modal{width:520px;max-width:100%;max-height:90vh;overflow:auto;background:var(--panel);border:1px solid var(--border-2);border-radius:12px;box-shadow:var(--shadow-lg)}
.modal-head{display:flex;align-items:center;gap:8px;padding:15px 18px;border-bottom:1px solid var(--border)}
.modal-head b{font-size:15px;font-weight:600}
.modal-head .pill{font-family:var(--mono);font-size:10.5px;color:var(--fg-3);background:var(--panel-3);border:1px solid var(--border);padding:2px 7px;border-radius:5px}
.modal-head .x{margin-left:auto;width:26px;height:26px;color:var(--fg-3);font-size:17px}
.modal-body{padding:18px;display:flex;flex-direction:column;gap:14px}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 18px;border-top:1px solid var(--border)}
.f-label{font-size:11px;color:var(--fg-2);margin-bottom:5px;font-weight:500}
.f-input,.f-select{width:100%;height:34px;padding:0 10px;border:1px solid var(--border-2);border-radius:7px;background:var(--panel-2);font-size:13px;outline:none}
.f-input:focus,.f-select:focus{border-color:var(--accent)}
.f-input.mono,.f-select.mono{font-family:var(--mono)}
.f-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.f-3col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.f-err{font-size:11px;color:var(--h-red-fg);margin-top:4px}
.seg{display:flex;gap:4px;padding:3px;background:var(--panel-3);border-radius:8px}
.seg button{flex:1;padding:6px;border-radius:6px;font-size:12px;color:var(--fg-2)}
.seg button.on{background:var(--panel);color:var(--fg);font-weight:500;box-shadow:var(--shadow)}

/* ---------- toast ---------- */
#jw-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:80;display:flex;align-items:center;gap:10px;padding:10px 15px;background:var(--panel);border:1px solid var(--border-2);border-radius:9px;box-shadow:var(--shadow-lg);animation:jw-toast .2s ease}
#jw-toast[hidden]{display:none}
#jw-toast .t-msg{font-size:12.5px;font-weight:500}
#jw-toast .t-detail{font-family:var(--mono);font-size:11px;color:var(--fg-3)}

/* ---------- empty / pager ---------- */
.empty{margin:24px 16px;padding:24px;text-align:center;color:var(--fg-3);font-size:12px;border:1px dashed var(--border-2);border-radius:9px}
.pager{display:flex;align-items:center;gap:8px;padding:10px 16px;border-top:1px solid var(--border);background:var(--panel-2);flex:none}
.pager .info{font-family:var(--mono);font-size:11px;color:var(--fg-3)}
.pager .right{margin-left:auto;display:flex;gap:6px;align-items:center}
.pager select{height:26px;border:1px solid var(--border-2);border-radius:6px;background:var(--panel);font-family:var(--mono);font-size:11px}
.pager button[disabled]{opacity:.4;cursor:default}
</style>
