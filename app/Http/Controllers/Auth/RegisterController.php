<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\RegistrationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Services\ClientRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Public sign-up of a trading client.
 *
 * The account is created in CRM (user + person + lead) and in the trading
 * tables (account with the configured starting balance); the client is logged
 * in straight away and lands on the dashboard, where the zero balance tells
 * them the next step is a deposit.
 */
class RegisterController extends Controller
{
    public function __construct(private readonly ClientRegistrationService $registration) {}

    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        try {
            $user = $this->registration->register(
                name: (string) $request->validated('name'),
                email: (string) $request->validated('email'),
                password: (string) $request->validated('password'),
                phone: $request->validated('phone') ?: null,
            );
        } catch (RegistrationException $exception) {
            Log::error('Client registration failed: '.$exception->getMessage());

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['registration' => $exception->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', sprintf(
                'Добро пожаловать, %s! Аккаунт создан — пополните счёт, чтобы открыть первую позицию.',
                $user->name,
            ));
    }
}
