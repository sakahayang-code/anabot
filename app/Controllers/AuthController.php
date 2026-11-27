<?php
declare(strict_types=1);

namespace AnaBot\Controllers;

use AnaBot\Core\Auth;
use AnaBot\Core\Response;
use AnaBot\Core\Validator;

/**
 * AnaBot Authentication Controller
 */
class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            Response::redirect('/dashboard');
        }

        // Minimal clean login page for Core Phase 1
        $html = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AnaBot</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2.5rem; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
        h1 { margin-top: 0; font-size: 1.5rem; text-align: center; color: #38bdf8; }
        p.subtitle { text-align: center; color: #94a3b8; font-size: 0.875rem; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.875rem; margin-bottom: 0.5rem; color: #cbd5e1; }
        input[type="text"], input[type="password"] { width: 100%; padding: 0.75rem; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; font-size: 0.95rem; }
        input:focus { border-color: #38bdf8; outline: none; }
        button { width: 100%; padding: 0.75rem; background: #0284c7; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; font-size: 1rem; }
        button:hover { background: #0369a1; }
        .alert { background: #ef4444; color: #fff; padding: 0.75rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1.25rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>AnaBot</h1>
        <p class="subtitle">AI Analytics Robot &bull; Personal Platform</p>';

        if (!empty($_SESSION['flash_error'])) {
            $html .= '<div class="alert">' . e($_SESSION['flash_error']) . '</div>';
            unset($_SESSION['flash_error']);
        }

        $html .= '<form method="POST" action="' . e(url('/login')) . '">
            ' . csrf_field() . '
            <div class="form-group">
                <label for="identifier">Username atau Email</label>
                <input type="text" id="identifier" name="identifier" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Masuk ke Dashboard</button>
        </form>
    </div>
</body>
</html>';

        echo $html;
        exit;
    }

    public function login(): void
    {
        $input = [
            'identifier' => trim((string) ($_POST['identifier'] ?? '')),
            'password' => (string) ($_POST['password'] ?? ''),
            '_csrf_token' => $_POST['_csrf_token'] ?? '',
        ];

        $validator = Validator::make($input, [
            'identifier' => 'required|min:3',
            'password' => 'required',
            '_csrf_token' => 'required|csrf',
        ]);

        if ($validator->fails()) {
            $_SESSION['flash_error'] = $validator->firstError();
            Response::redirect('/login');
        }

        $result = Auth::attempt($input['identifier'], $input['password']);

        if (!$result['success']) {
            $_SESSION['flash_error'] = $result['message'];
            Response::redirect('/login');
        }

        Response::redirect('/dashboard');
    }

    public function apiLogin(): void
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $validator = Validator::make($data, [
            'identifier' => 'required|min:3',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            Response::error('Validasi gagal', 'VALIDATION_FAILED', $validator->errors(), 422);
        }

        $result = Auth::attempt((string) $data['identifier'], (string) $data['password']);

        if (!$result['success']) {
            Response::error($result['message'], 'AUTH_FAILED', null, 401);
        }

        Response::success([
            'user' => Auth::user(),
        ], 'Login berhasil.');
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/login');
    }

    public function apiLogout(): void
    {
        Auth::logout();
        Response::success(null, 'Logout berhasil.');
    }

    public function me(): void
    {
        $user = Auth::user();
        if (!$user) {
            Response::error('Unauthenticated.', 'UNAUTHENTICATED', null, 401);
        }

        Response::success($user);
    }

    public function changePassword(): void
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $validator = Validator::make($data, [
            'old_password' => 'required',
            'new_password' => 'required|min:8',
            'new_password_confirmation' => 'required|same:new_password',
        ]);

        if ($validator->fails()) {
            Response::error('Validasi gagal', 'VALIDATION_FAILED', $validator->errors(), 422);
        }

        $userId = (int) Auth::id();
        $result = Auth::changePassword($userId, (string) $data['old_password'], (string) $data['new_password']);

        if (!$result['success']) {
            Response::error($result['message'], 'PASSWORD_CHANGE_FAILED', null, 400);
        }

        Response::success(null, $result['message']);
    }
}
