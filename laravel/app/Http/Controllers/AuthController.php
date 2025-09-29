<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->intended($this->defaultRedirectFor(Auth::user()));
        }

        return view('auth.login');
    }

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
     * @return array<int, array<string, string>>
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

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    private function defaultRedirectFor($user): string
    {
        // Admins (or anyone with this permission) go to Filament panel; others go to dashboard.
        if ($user && $user->can('access filament')) {
            return url('/admin');
        }

        return route('dashboard');
    }
}
