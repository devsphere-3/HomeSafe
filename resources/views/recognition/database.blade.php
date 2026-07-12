@extends('layouts.app')
@section('title', 'HomeSafe — Database')

@push('styles')
<style>
.db-wrap { display: flex; flex-direction: column; gap: 1.25rem; }

.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 0.75rem;
}
.stat-card {
    background: rgba(12,24,45,0.68);
    border: 1px solid rgba(255,255,255,0.09);
    border-radius: 14px; padding: 1rem;
    display: flex; flex-direction: column; gap: 0.3rem;
}
html:not(.dark) .stat-card {
    background: rgba(210,222,240,0.68);
    border-color: rgba(148,163,184,0.24);
}
.stat-val  { font-size: 2rem; font-weight: 700; line-height: 1; color: #f1f5f9; }
.stat-label{ font-size: 0.72rem; color: #64748b; }
html:not(.dark) .stat-val { color: #0f172a; }

.tab-bar {
    display: flex; gap: 0.25rem;
    background: rgba(0,0,0,0.2);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 12px; padding: 0.25rem;
    width: fit-content;
}
html:not(.dark) .tab-bar { background: rgba(0,0,0,0.05); border-color: rgba(148,163,184,0.2); }
.tab-btn {
    padding: 0.4rem 1rem; border-radius: 9px;
    font-size: 0.78rem; font-weight: 500; color: #64748b;
    cursor: pointer; display: flex; align-items: center; gap: 0.35rem;
    text-decoration: none; transition: all 0.15s; border: 1px solid transparent;
}
.tab-btn:hover { color: #cbd5e1; background: rgba(255,255,255,0.05); }
.tab-btn.active { background: #3b82f6; color: #fff; border-color: rgba(59,130,246,0.4); }

.tbl-card {
    background: rgba(12,24,45,0.68);
    border: 1px solid rgba(255,255,255,0.09);
    border-radius: 14px; overflow: hidden;
}
html:not(.dark) .tbl-card { background: rgba(210,222,240,0.68); border-color: rgba(148,163,184,0.24); }
.tbl-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
html:not(.dark) .tbl-header { border-bottom-color: rgba(148,163,184,0.18); }

.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
thead th {
    padding: 0.6rem 1rem; text-align: left;
    font-size: 0.68rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.06em; color: #475569;
    background: rgba(0,0,0,0.15);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    white-space: nowrap;
}
html:not(.dark) thead th { background: rgba(0,0,0,0.04); border-bottom-color: rgba(148,163,184,0.2); color: #64748b; }
tbody tr { border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.1s; }
html:not(.dark) tbody tr { border-bottom-color: rgba(148,163,184,0.12); }
tbody tr:hover { background: rgba(255,255,255,0.03); }
tbody tr:last-child { border-bottom: none; }
td { padding: 0.65rem 1rem; color: #cbd5e1; vertical-align: middle; }
html:not(.dark) td { color: #1e293b; }

.b { display:inline-flex; align-items:center; gap:0.2rem;
     padding:0.15rem 0.55rem; border-radius:999px;
     font-size:0.68rem; font-weight:500; border:1px solid transparent; }
.b-green  { background:rgba(16,185,129,0.15); border-color:rgba(16,185,129,0.3); color:#6ee7b7; }
.b-blue   { background:rgba(59,130,246,0.15); border-color:rgba(59,130,246,0.3); color:#93c5fd; }
.b-gray   { background:rgba(100,116,139,0.15);border-color:rgba(100,116,139,0.25);color:#94a3b8;}

/* ── simple pagination ── */
.pg { display:flex; gap:0.25rem; flex-wrap:wrap; padding: 0.75rem 1rem; }
.pg a, .pg span {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:2rem; height:2rem; padding:0 0.5rem;
    border-radius:7px; font-size:0.75rem; font-weight:500;
    color:#64748b; text-decoration:none;
    border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.04);
    transition: all 0.15s;
}
.pg a:hover { background:rgba(255,255,255,0.08); color:#f1f5f9; }
.pg .cur { background:#3b82f6; border-color:#3b82f6; color:#fff; }
.pg .dis { opacity:0.4; pointer-events:none; }

.pi-offline {
    background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.25);
    border-radius: 12px; padding: 1.25rem 1.5rem;
    display: flex; align-items: flex-start; gap: 0.75rem;
}
.schema-box {
    background: rgba(12,24,45,0.68); border: 1px solid rgba(255,255,255,0.09);
    border-radius: 14px; padding: 1.25rem;
}
html:not(.dark) .schema-box { background: rgba(210,222,240,0.68); border-color: rgba(148,163,184,0.24); }
</style>
@endpush

@section('content')
<div class="db-wrap">

{{-- ── Judul ── --}}
<div class="flex items-center justify-between flex-wrap gap-3">
    <div>
        <h1 class="text-lg font-bold text-primary flex items-center gap-2">
            <i class="fas fa-database text-blue-400"></i>
            Database HomeSafe
        </h1>
        <p class="text-xs text-secondary mt-0.5">
            Sumber data: Raspberry Pi ·
            <code class="text-blue-300">{{ $stats['backend_url'] }}</code>
            @if($stats['pi_online'])
                <span class="ml-2 inline-flex items-center gap-1 text-emerald-400">
                    <i class="fas fa-circle text-[7px]"></i> Online
                </span>
            @else
                <span class="ml-2 inline-flex items-center gap-1 text-red-400">
                    <i class="fas fa-circle text-[7px]"></i> Offline
                </span>
            @endif
        </p>
    </div>
    <a href="{{ route('database', ['tab' => $tab]) }}"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium
              bg-slate-700/40 border border-white/10 text-slate-300 hover:bg-slate-700/70 transition">
        <i class="fas fa-sync-alt text-[10px]"></i>Refresh
    </a>
</div>

{{-- ── Error Pi offline ── --}}
@if($error)
<div class="pi-offline">
    <i class="fas fa-exclamation-triangle text-red-400 mt-0.5 flex-shrink-0"></i>
    <div>
        <p class="text-sm font-semibold text-red-300">Raspberry Pi Tidak Terjangkau</p>
        <p class="text-xs text-red-400/80 mt-0.5">{{ $error }}</p>
        <p class="text-xs text-slate-500 mt-1">
            Pastikan Pi menyala, terhubung ke jaringan yang sama, dan backend berjalan di port 5001.
        </p>
    </div>
</div>
@endif

{{-- ── Stat cards ── --}}
<div class="stat-grid">
    <div class="stat-card" style="border-left:3px solid #3b82f6;">
        <span class="stat-val text-blue-400">{{ $stats['total_profiles'] }}</span>
        <span class="stat-label"><i class="fas fa-users mr-1"></i>Wajah Terdaftar</span>
    </div>
    <div class="stat-card" style="border-left:3px solid #10b981;">
        <span class="stat-val text-emerald-400">{{ $stats['total_access'] }}</span>
        <span class="stat-label"><i class="fas fa-key mr-1"></i>Total Log Akses</span>
    </div>
    <div class="stat-card" style="border-left:3px solid #f59e0b;">
        <span class="stat-val text-amber-400">{{ $stats['access_today'] }}</span>
        <span class="stat-label"><i class="fas fa-calendar-day mr-1"></i>Akses Hari Ini</span>
    </div>
    <div class="stat-card" style="border-left:3px solid {{ $stats['pi_online'] ? '#10b981' : '#ef4444' }};">
        <span class="stat-val {{ $stats['pi_online'] ? 'text-emerald-400' : 'text-red-400' }}">
            {{ $stats['pi_online'] ? 'ON' : 'OFF' }}
        </span>
        <span class="stat-label"><i class="fas fa-server mr-1"></i>Status Pi</span>
    </div>
</div>

{{-- ── Tab bar ── --}}
<div class="tab-bar">
    <a href="{{ route('database', ['tab' => 'profiles']) }}"
       class="tab-btn {{ $tab === 'profiles' ? 'active' : '' }}">
        <i class="fas fa-id-card text-[11px]"></i>
        Profil Wajah
        <span class="text-[10px] opacity-70">({{ $stats['total_profiles'] }})</span>
    </a>
    <a href="{{ route('database', ['tab' => 'access']) }}"
       class="tab-btn {{ $tab === 'access' ? 'active' : '' }}">
        <i class="fas fa-key text-[11px]"></i>
        Log Akses
        <span class="text-[10px] opacity-70">({{ $stats['total_access'] }})</span>
    </a>
    <a href="{{ route('database', ['tab' => 'schema']) }}"
       class="tab-btn {{ $tab === 'schema' ? 'active' : '' }}">
        <i class="fas fa-diagram-project text-[11px]"></i>
        Struktur Data
    </a>
</div>

{{-- ════ TAB: Profil Wajah ════ --}}
@if($tab === 'profiles')
<div class="tbl-card">
    <div class="tbl-header">
        <div>
            <p class="text-sm font-semibold text-primary flex items-center gap-2">
                <i class="fas fa-id-card text-blue-400"></i>
                Profil Wajah Terdaftar
                <span class="text-xs text-slate-500 font-normal">— database.json @ Raspberry Pi</span>
            </p>
            <p class="text-xs text-secondary mt-0.5">
                Embedding 128-dim tersimpan lokal di Pi. Data ini real-time, selalu sinkron otomatis.
            </p>
        </div>
    </div>

    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama</th>
                    <th>Status</th>
                    <th>Sumber</th>
                    <th>Embedding</th>
                </tr>
            </thead>
            <tbody>
                @forelse($profilePaged as $p)
                <tr>
                    <td class="text-slate-500 text-xs">{{ $p['id'] }}</td>
                    <td>
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-full bg-blue-500/20 border border-blue-500/30
                                        flex items-center justify-center text-blue-400 text-xs font-bold flex-shrink-0">
                                {{ strtoupper(substr($p['name'], 0, 1)) }}
                            </div>
                            <span class="font-semibold">{{ $p['name'] }}</span>
                        </div>
                    </td>
                    <td><span class="b b-green"><i class="fas fa-circle text-[6px]"></i>Aktif</span></td>
                    <td class="text-xs font-mono text-slate-400">{{ $p['node'] }}</td>
                    <td class="text-xs text-slate-500">128-dim float32 (SFace)</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center py-8 text-secondary">
                        @if($error)
                            <i class="fas fa-wifi-slash mr-2 opacity-40"></i>Pi tidak terjangkau
                        @else
                            <i class="fas fa-database mr-2 opacity-40"></i>Belum ada wajah terdaftar
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($profileTotal > $perPage)
    @php
        $totalPages = (int) ceil($profileTotal / $perPage);
    @endphp
    <div class="pg">
        <a href="{{ route('database', ['tab'=>'profiles','pPage'=>max(1,$profilePage-1)]) }}"
           class="{{ $profilePage<=1 ? 'dis' : '' }}">‹</a>
        @for($i=1; $i<=$totalPages; $i++)
            <a href="{{ route('database', ['tab'=>'profiles','pPage'=>$i]) }}"
               class="{{ $i===$profilePage ? 'cur' : '' }}">{{ $i }}</a>
        @endfor
        <a href="{{ route('database', ['tab'=>'profiles','pPage'=>min($totalPages,$profilePage+1)]) }}"
           class="{{ $profilePage>=$totalPages ? 'dis' : '' }}">›</a>
        <span class="dis text-xs">{{ $profileTotal }} total</span>
    </div>
    @endif
</div>
@endif

{{-- ════ TAB: Log Akses ════ --}}
@if($tab === 'access')
<div class="tbl-card">
    <div class="tbl-header">
        <div>
            <p class="text-sm font-semibold text-primary flex items-center gap-2">
                <i class="fas fa-key text-emerald-400"></i>
                Log Akses Pintu
                <span class="text-xs text-slate-500 font-normal">— history.json @ Raspberry Pi</span>
            </p>
            <p class="text-xs text-secondary mt-0.5">
                Setiap baris = satu event unlock berhasil. Foto snapshot diambil langsung dari Pi.
            </p>
        </div>
    </div>

    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama</th>
                    <th>Kemiripan</th>
                    <th>Snapshot</th>
                    <th>Waktu Akses</th>
                </tr>
            </thead>
            <tbody>
                @forelse($accessPaged as $i => $log)
                @php
                    $no  = ($accessPage - 1) * $perPage + $i + 1;
                    $pct = (float) ($log['percentage'] ?? 0);
                    $ts  = $log['timestamp'] ?? '';
                @endphp
                <tr>
                    <td class="text-slate-500 text-xs">{{ $no }}</td>
                    <td>
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-full bg-emerald-500/20 border border-emerald-500/30
                                        flex items-center justify-center text-emerald-400 text-[10px] font-bold flex-shrink-0">
                                {{ strtoupper(substr($log['name'] ?? '?', 0, 1)) }}
                            </div>
                            <span class="font-medium">{{ $log['name'] ?? 'Unknown' }}</span>
                        </div>
                    </td>
                    <td>
                        <div class="flex items-center gap-2">
                            <div class="w-16 h-1.5 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full"
                                     style="width:{{ min($pct,100) }}%;
                                     background:{{ $pct>=80?'#10b981':($pct>=60?'#f59e0b':'#ef4444') }};"></div>
                            </div>
                            <span class="text-xs font-semibold
                                {{ $pct>=80?'text-emerald-400':($pct>=60?'text-amber-400':'text-red-400') }}">
                                {{ number_format($pct, 1) }}%
                            </span>
                        </div>
                    </td>
                    <td>
                        @if(!empty($log['image']))
                            <a href="{{ $stats['backend_url'] }}/history/{{ $log['image'] }}"
                               target="_blank"
                               class="inline-flex items-center gap-1 text-xs text-blue-400 hover:text-blue-300">
                                <i class="fas fa-image text-[10px]"></i>Lihat
                            </a>
                        @else
                            <span class="text-xs text-slate-600">—</span>
                        @endif
                    </td>
                    <td class="text-xs text-secondary whitespace-nowrap">
                        {{ $ts ? str_replace('T', ' ', substr($ts, 0, 19)) : '—' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center py-8 text-secondary">
                        @if($error)
                            <i class="fas fa-wifi-slash mr-2 opacity-40"></i>Pi tidak terjangkau
                        @else
                            <i class="fas fa-key mr-2 opacity-40"></i>Belum ada log akses
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($accessTotal > $perPage)
    @php $totalPages = (int) ceil($accessTotal / $perPage); @endphp
    <div class="pg">
        <a href="{{ route('database', ['tab'=>'access','aPage'=>max(1,$accessPage-1)]) }}"
           class="{{ $accessPage<=1 ? 'dis' : '' }}">‹</a>
        @for($i=1; $i<=$totalPages; $i++)
            <a href="{{ route('database', ['tab'=>'access','aPage'=>$i]) }}"
               class="{{ $i===$accessPage ? 'cur' : '' }}">{{ $i }}</a>
        @endfor
        <a href="{{ route('database', ['tab'=>'access','aPage'=>min($totalPages,$accessPage+1)]) }}"
           class="{{ $accessPage>=$totalPages ? 'dis' : '' }}">›</a>
        <span class="dis text-xs">{{ $accessTotal }} total</span>
    </div>
    @endif
</div>
@endif

{{-- ════ TAB: Struktur Data ════ --}}
@if($tab === 'schema')
<div class="schema-box">
    <p class="text-sm font-bold text-primary mb-4 flex items-center gap-2">
        <i class="fas fa-diagram-project text-violet-400"></i>
        Arsitektur Penyimpanan Data HomeSafe
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs mb-6">

        {{-- database.json --}}
        <div class="rounded-xl border border-blue-500/25 bg-blue-500/5 p-4">
            <p class="font-bold text-blue-400 mb-1 flex items-center gap-1.5">
                <i class="fas fa-file-code"></i> database.json
                <span class="ml-auto font-normal text-slate-500">Raspberry Pi</span>
            </p>
            <p class="text-slate-500 mb-3 text-[11px]">
                Disimpan lokal di Pi — tidak pernah dikirim ke Laravel.
                Dimuat ke memori saat startup, selalu sinkron real-time.
            </p>
            <div class="space-y-1 font-mono text-slate-400 text-[11px]">
                <p><span class="text-green-300">"NamaPengguna"</span>: <span class="text-amber-300">[float32 × 128]</span></p>
                <p class="text-slate-600 pl-3">// Embedding wajah 128-dimensi (SFace ONNX)</p>
                <p class="text-slate-600 pl-3">// Dibaca oleh cv2.FaceRecognizerSF</p>
            </div>
            <div class="mt-3 pt-3 border-t border-white/5 flex items-center gap-2">
                <span class="b b-blue text-[10px]">In-memory</span>
                <span class="b b-green text-[10px]">Real-time</span>
                <span class="text-slate-600 text-[10px] ml-auto">≈ 0.5KB / profil</span>
            </div>
        </div>

        {{-- history.json --}}
        <div class="rounded-xl border border-emerald-500/25 bg-emerald-500/5 p-4">
            <p class="font-bold text-emerald-400 mb-1 flex items-center gap-1.5">
                <i class="fas fa-file-alt"></i> history.json
                <span class="ml-auto font-normal text-slate-500">Raspberry Pi</span>
            </p>
            <p class="text-slate-500 mb-3 text-[11px]">
                Ditulis setiap kali unlock berhasil. Di-serve via
                <code class="text-amber-300">GET /api/history</code> ke Laravel.
            </p>
            <div class="space-y-1 font-mono text-slate-400 text-[11px]">
                <p><span class="text-blue-300">"name"</span>: <span class="text-green-300">"string"</span></p>
                <p><span class="text-blue-300">"timestamp"</span>: <span class="text-green-300">"ISO-8601"</span></p>
                <p><span class="text-blue-300">"percentage"</span>: <span class="text-amber-300">float</span> <span class="text-slate-600">// 0–100%</span></p>
                <p><span class="text-blue-300">"image"</span>: <span class="text-green-300">"*.webp"</span></p>
            </div>
            <div class="mt-3 pt-3 border-t border-white/5 flex items-center gap-2">
                <span class="b b-green text-[10px]">Max 200 entri</span>
                <span class="text-slate-600 text-[10px] ml-auto">Snapshot: /history/*.webp</span>
            </div>
        </div>

    </div>

    {{-- Alur data --}}
    <p class="text-xs font-semibold text-slate-400 mb-3 uppercase tracking-wider">
        Alur Data Real-time
    </p>
    <div class="flex flex-wrap items-center gap-2 text-xs font-mono">
        <span class="px-2.5 py-1.5 rounded-lg bg-blue-500/15 border border-blue-500/25 text-blue-300">
            <i class="fas fa-camera mr-1"></i>Kamera USB
        </span>
        <i class="fas fa-arrow-right text-slate-600"></i>
        <span class="px-2.5 py-1.5 rounded-lg bg-violet-500/15 border border-violet-500/25 text-violet-300">
            <i class="fas fa-microchip mr-1"></i>FastAPI + SFace
        </span>
        <i class="fas fa-arrow-right text-slate-600"></i>
        <span class="px-2.5 py-1.5 rounded-lg bg-emerald-500/15 border border-emerald-500/25 text-emerald-300">
            <i class="fas fa-plug mr-1"></i>WebSocket
        </span>
        <i class="fas fa-arrow-right text-slate-600"></i>
        <span class="px-2.5 py-1.5 rounded-lg bg-amber-500/15 border border-amber-500/25 text-amber-300">
            <i class="fas fa-globe mr-1"></i>Laravel Dashboard
        </span>
    </div>

    <div class="mt-4 p-3 rounded-xl bg-white/3 border border-white/6 text-[11px] text-slate-500 leading-relaxed">
        <i class="fas fa-info-circle text-blue-400 mr-1"></i>
        <strong class="text-slate-400">Tidak ada sinkronisasi manual.</strong>
        Data mengalir langsung Pi → Browser via WebSocket dan REST API.
        Laravel hanya sebagai <em>dashboard viewer</em> — tidak menyimpan embedding wajah.
        Halaman ini fetch ulang data setiap kali dibuka atau tombol Refresh ditekan.
    </div>
</div>
@endif

</div>{{-- end db-wrap --}}
@endsection
