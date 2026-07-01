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

    /** GET /enroll — face enrollment page */
    public function enroll()
    {
        return view('recognition.enroll');
    }

    /** GET /users — list of enrolled users */
    public function users()
    {
        $users = [];
        try {
            $response = Http::timeout(5)->get($this->apiBaseUrl . '/api/users');
            if ($response->successful()) {
                $users = $response->json()['users'] ?? [];
            }
        } catch (\Exception $e) {
            // backend unreachable — show empty list with a flash message
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
}
