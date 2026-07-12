<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FaceRecognitionController extends Controller
{
    protected string $apiBaseUrl;

    public function __construct()
    {
        $this->apiBaseUrl = config('app.backend_url', env('BACKEND_URL', 'http://127.0.0.1:5001'));
    }

    /** GET / — live recognition page */
    public function index()
    {
        return view('recognition.index');
    }

    /** GET /cameras — dual camera fullscreen view */
    public function cameras()
    {
        return view('recognition.cameras');
    }

    /** GET /enroll — face enrollment page */
    public function enroll()
    {
        return view('recognition.enroll');
    }

    /** GET /users — list of enrolled users (dari Pi langsung) */
    public function users()
    {
        $users = [];
        try {
            $response = Http::timeout(5)->get($this->apiBaseUrl . '/api/users');
            if ($response->successful()) {
                $users = $response->json()['users'] ?? [];
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Backend tidak dapat dijangkau: ' . $e->getMessage());
        }

        return view('recognition.users', compact('users'));
    }

    /** DELETE /users/{name} */
    public function deleteUser(string $name)
    {
        try {
            Http::timeout(5)->delete($this->apiBaseUrl . '/api/users/' . urlencode($name));
        } catch (\Exception $e) {
            return redirect()->route('users')
                ->with('error', "Gagal menghapus user '{$name}': " . $e->getMessage());
        }

        return redirect()->route('users')
            ->with('success', "User '{$name}' berhasil dihapus.");
    }

    /** GET /history — access history page */
    public function history()
    {
        return view('recognition.history');
    }

    /**
     * GET /database — fetch semua data LANGSUNG dari Raspberry Pi via API.
     * Tidak ada SQLite, tidak ada seeder, tidak ada sinkronisasi manual.
     * Data selalu real-time sesuai kondisi Pi saat ini.
     */
    public function database(Request $request)
    {
        $tab        = $request->query('tab', 'profiles');
        $piOnline   = false;
        $profiles   = [];
        $accessLogs = [];
        $history    = [];
        $error      = null;

        try {
            // ── Ambil profil wajah dari Pi ─────────────────────────────────────
            $usersRes = Http::timeout(5)->get($this->apiBaseUrl . '/api/users/detail');
            if ($usersRes->successful()) {
                $profiles = $usersRes->json()['profiles'] ?? [];
                $piOnline = true;
            }

            // ── Ambil history akses dari Pi ────────────────────────────────────
            $histRes = Http::timeout(5)->get($this->apiBaseUrl . '/api/history?limit=200');
            if ($histRes->successful()) {
                $history = $histRes->json()['history'] ?? [];
            }

        } catch (\Exception $e) {
            $error = 'Raspberry Pi tidak dapat dijangkau: ' . $e->getMessage();
        }

        // ── Hitung statistik dari data yang diterima ───────────────────────────
        $stats = [
            'pi_online'      => $piOnline,
            'total_profiles' => count($profiles),
            'total_access'   => count($history),
            'access_today'   => collect($history)->filter(function ($h) {
                return isset($h['timestamp']) &&
                       str_starts_with($h['timestamp'], now()->format('Y-m-d'));
            })->count(),
            'backend_url'    => $this->apiBaseUrl,
        ];

        // ── Pagination manual (20 per halaman) ─────────────────────────────────
        $perPage     = 20;
        $accessPage  = max(1, (int) $request->query('aPage', 1));
        $profilePage = max(1, (int) $request->query('pPage', 1));

        $accessPaged   = array_slice($history,  ($accessPage  - 1) * $perPage, $perPage);
        $profilePaged  = array_slice($profiles, ($profilePage - 1) * $perPage, $perPage);

        $accessTotal   = count($history);
        $profileTotal  = count($profiles);

        return view('recognition.database', compact(
            'tab', 'stats', 'error',
            'profilePaged',  'profileTotal',  'profilePage',
            'accessPaged',   'accessTotal',   'accessPage',
            'perPage'
        ));
    }
}
