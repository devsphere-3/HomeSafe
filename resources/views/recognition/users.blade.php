@extends('layouts.app')

@section('title', 'Pengguna Terdaftar — HomeSafe')

@push('styles')
<style>
    .user-card {
        position: relative;
        background: rgba(12, 24, 45, 0.65);
        backdrop-filter: blur(22px) saturate(1.3);
        -webkit-backdrop-filter: blur(22px) saturate(1.3);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 14px;
        padding: 20px;
        display: flex; align-items: center; gap: 16px;
        transition: all 0.25s ease;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.22), inset 0 1px 0 rgba(255,255,255,0.06);
    }
    html:not(.dark) .user-card {
        background: rgba(210,222,240,0.65);
        border-color: rgba(148,163,184,0.22);
        box-shadow: 0 2px 12px rgba(15,23,42,0.09), inset 0 1px 0 rgba(255,255,255,0.5);
    }
    .user-card:hover {
        border-color: rgba(59,130,246,0.3);
        transform: translateY(-2px);
        box-shadow: 0 8px 32px rgba(59,130,246,0.08);
    }
    html:not(.dark) .user-card:hover { box-shadow: 0 8px 32px rgba(37,99,235,0.1); }

    .user-avatar {
        width: 44px; height: 44px;
        border-radius: 12px;
        background: rgba(59,130,246,0.15);
        border: 1px solid rgba(59,130,246,0.2);
        display: flex; align-items: center; justify-content: center;
        color: #3b82f6;
        font-size: 18px;
        flex-shrink: 0;
    }
    html:not(.dark) .user-avatar {
        background: rgba(37,99,235,0.08);
        border-color: rgba(37,99,235,0.15);
        color: #2563eb;
    }

    .btn-delete {
        margin-left: auto;
        padding: 7px 14px;
        border-radius: 9px;
        font-size: 0.75rem;
        font-weight: 500;
        background: rgba(239,68,68,0.1);
        border: 1px solid rgba(239,68,68,0.2);
        color: #f87171;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex; align-items: center; gap: 5px;
        white-space: nowrap;
    }
    .btn-delete:hover {
        background: rgba(239,68,68,0.2);
        border-color: rgba(239,68,68,0.4);
        transform: scale(1.03);
    }
    html:not(.dark) .btn-delete {
        background: rgba(220,38,38,0.07);
        border-color: rgba(220,38,38,0.15);
        color: #dc2626;
    }

    .stat-glass {
        position: relative;
        background: rgba(30,41,59,0.45);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 14px;
        padding: 20px 24px;
        overflow: hidden;
    }
    html:not(.dark) .stat-glass {
        background: rgba(255,255,255,0.55);
        border-color: rgba(0,0,0,0.06);
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .enroll-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 22px;
        border-radius: 10px;
        font-size: 0.875rem;
        font-weight: 600;
        background: #3b82f6;
        color: #fff;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .enroll-btn:hover { background: #2563eb; transform: scale(1.02); box-shadow: 0 4px 16px rgba(59,130,246,0.35); }
    html:not(.dark) .enroll-btn:hover { box-shadow: 0 4px 16px rgba(37,99,235,0.3); }

    .empty-glass {
        background: rgba(30,41,59,0.4);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 20px;
        padding: 64px 32px;
        text-align: center;
    }
    html:not(.dark) .empty-glass {
        background: rgba(255,255,255,0.5);
        border-color: rgba(0,0,0,0.06);
    }

    .page-title-area {
        background: rgba(30,41,59,0.4);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 16px;
        padding: 20px 24px;
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 16px;
        margin-bottom: 24px;
    }
    html:not(.dark) .page-title-area {
        background: rgba(255,255,255,0.55);
        border-color: rgba(0,0,0,0.06);
    }
</style>
@endpush

@section('content')

<!-- Page header -->
<div class="page-title-area">
    <div>
        <h1 class="text-xl font-bold text-primary flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-blue-500/15 border border-blue-500/20 flex items-center justify-center text-blue-400">
                <i class="fas fa-users text-sm"></i>
            </span>
            Pengguna Terdaftar
        </h1>
        <p class="text-secondary text-sm mt-1">Kelola daftar wajah yang diizinkan mengakses sistem</p>
    </div>
    <a href="{{ route('enroll') }}" class="enroll-btn">
        <i class="fas fa-user-plus text-sm"></i>Tambah Pengguna
    </a>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
    <div class="stat-glass">
        <div class="text-2xl font-bold text-blue-400">{{ count($users) }}</div>
        <div class="text-secondary text-xs mt-1 font-medium">Total Terdaftar</div>
    </div>
    <div class="stat-glass">
        <div class="text-2xl font-bold text-emerald-400">
            <i class="fas fa-circle text-[8px] mb-0.5 mr-1"></i>Aktif
        </div>
        <div class="text-secondary text-xs mt-1 font-medium">Status Sistem</div>
    </div>
    <div class="stat-glass col-span-2 sm:col-span-1">
        <div class="text-2xl font-bold text-primary">Face ID</div>
        <div class="text-secondary text-xs mt-1 font-medium">Metode Autentikasi</div>
    </div>
</div>

<!-- User list -->
@if(empty($users))
    <div class="empty-glass">
        <div class="w-16 h-16 rounded-2xl bg-slate-700/40 flex items-center justify-center mx-auto mb-5">
            <i class="fas fa-user-slash text-2xl text-slate-500"></i>
        </div>
        <h3 class="text-primary font-semibold text-lg mb-2">Belum ada pengguna</h3>
        <p class="text-secondary text-sm mb-6 max-w-xs mx-auto">
            Tambahkan wajah penghuni untuk mengaktifkan sistem pengenalan otomatis.
        </p>
        <a href="{{ route('enroll') }}" class="enroll-btn" style="margin: 0 auto; text-decoration:none;">
            <i class="fas fa-user-plus text-sm"></i>Tambah Pengguna Pertama
        </a>
    </div>
@else
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        @foreach($users as $user)
        @php $userName = is_string($user) ? $user : ($user['name'] ?? 'Unknown'); @endphp
        <div class="user-card">
            <div class="user-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="font-semibold text-primary text-sm truncate">{{ $userName }}</div>
                <div class="text-secondary text-xs mt-0.5 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                    Terdaftar · Face Recognition
                </div>
            </div>
            <form action="{{ route('users.delete', $userName) }}" method="POST"
                  onsubmit="return confirm('Hapus pengguna \'{{ $userName }}\'?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-delete">
                    <i class="fas fa-trash text-[10px]"></i>Hapus
                </button>
            </form>
        </div>
        @endforeach
    </div>
@endif

@endsection
