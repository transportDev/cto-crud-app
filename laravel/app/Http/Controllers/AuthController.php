<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Controller Autentikasi
 *
 * Controller ini mengelola proses autentikasi user aplikasi CTO CRUD:
 * - Menampilkan form login
 * - Proses login dengan multiple credential attempts (email/username, case-insensitive)
 * - Logout dengan session invalidation
 * - Redirect otomatis berdasarkan permission user setelah login
 *
 * @package App\Http\Controllers
 * @author  CTO CRUD App Team
 * @version 1.0
 * @since   1.0.0
 */
class AuthController extends Controller
{
    /**
     * Menampilkan halaman form login
     *
     * GET /login
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->intended($this->defaultRedirectFor(Auth::user()));
        }

        return view('auth.login');
    }

    /**
     * Memproses login user dengan multiple credential attempts
     *
     * POST /login
     *
     * @param Request $request Request object dengan input login dan password
     * @return \Illuminate\Http\RedirectResponse Redirect ke halaman intended atau error ke form login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');
        $login = trim((string) $credentials['login']);
        $password = $credentials['password'];

        foreach ($this->credentialAttempts($login, $password) as $attempt) {
            if (! Auth::attempt($attempt, $remember)) {
                continue;
            }

            $request->session()->regenerate();

            $user = Auth::user();
            return redirect()->intended($this->defaultRedirectFor($user));
        }

        return back()->withErrors([
            'login' => __('auth.failed'),
        ])->onlyInput('login');
    }

    /**
     * Membuat array credential attempts untuk proses login
     *
     * Method ini menghasilkan array kombinasi kredensial yang akan dicoba untuk login.
     * Strategi multiple attempts meningkatkan UX dengan mendukung:
     * - Case-insensitive login (coba original case dan lowercase)
     * - Flexible field matching (coba email dan username untuk input yang sama)
     *
     * @param string $login Input login dari user (email atau username)
     * @param string $password Password user
     * @return array<int, array<string, string>> Array of credential attempts (email/username + password)
     */
    private function credentialAttempts(string $login, string $password): array
    {
        $login = trim($login);

        if ($login === '') {
            return [];
        }

        $normalized = Str::lower($login);
        $isEmail = filter_var($login, FILTER_VALIDATE_EMAIL) !== false;

        $attempts = [];

        if ($isEmail) {
            $attempts[] = ['email' => $login, 'password' => $password];
            if ($normalized !== $login) {
                $attempts[] = ['email' => $normalized, 'password' => $password];
            }
            $attempts[] = ['username' => $normalized, 'password' => $password];
        } else {
            $attempts[] = ['username' => $login, 'password' => $password];
            if ($normalized !== $login) {
                $attempts[] = ['username' => $normalized, 'password' => $password];
            }
            $attempts[] = ['email' => $login, 'password' => $password];
            if ($normalized !== $login) {
                $attempts[] = ['email' => $normalized, 'password' => $password];
            }
        }

        $seen = [];

        return array_values(array_filter($attempts, function (array $attempt) use (&$seen) {
            $key = json_encode($attempt);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
    }

    /**
     * Logout user dan membersihkan session
     *
     * POST /logout
     *
     * @param Request $request Request object untuk akses session
     * @return \Illuminate\Http\RedirectResponse Redirect ke halaman login
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    /**
     * Menentukan URL redirect default berdasarkan permission user
     *
     * @param mixed $user User object yang sudah terautentikasi
     * @return string URL redirect destination
     */
    private function defaultRedirectFor($user): string
    {
        if ($user && $user->can('access filament')) {
            return url('/admin');
        }

        return route('dashboard');
    }
}
