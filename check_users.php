<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

echo "Total users: " . User::count() . PHP_EOL;
$user = User::first();
if ($user) {
    echo "First user: " . $user->name . " (" . $user->email . ")" . PHP_EOL;
} else {
    echo "No users found" . PHP_EOL;
}