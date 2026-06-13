<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FaceRecognitionController extends Controller
{
    protected $apiBaseUrl = 'http://127.0.0.1:5000';

    public function index(Request $request)
    {
        $recogDevice = $request->query('recog');
        $cctvDevice = $request->query('cctv');
        
        // Jika belum pilih kamera, arahkan ke halaman pilih kamera
        if (!$recogDevice || !$cctvDevice) {
            return view('recognition.camera-select');
        }
        
        return view('recognition.index', compact('recogDevice', 'cctvDevice'));
    }

    public function enroll()
    {
        return view('recognition.enroll');
    }

    public function users()
    {
        try {
            $response = Http::timeout(5)->get($this->apiBaseUrl . '/api/users');
            $users = $response->successful() ? $response->json()['users'] : [];
        } catch (\Exception $e) {
            $users = [];
        }
        
        return view('recognition.users', compact('users'));
    }

    public function deleteUser($name)
    {
        try {
            Http::timeout(5)->delete($this->apiBaseUrl . '/api/users/' . $name);
        } catch (\Exception $e) {
            // Handle error
        }
        
        return redirect()->route('users')->with('success', "User '{$name}' deleted successfully.");
    }
}