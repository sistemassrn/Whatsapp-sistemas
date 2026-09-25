<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NexoAuthController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('whatsapp.conversations');
        }

        return Inertia::render('home');
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{usuario: string, password: string} $validated */
        $validated = $request->validate([
            'usuario' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'usuario.required' => 'Ingresá tu usuario.',
            'password.required' => 'Ingresá tu contraseña.',
        ]);

        if (! Auth::attempt([
            'usuario' => $validated['usuario'],
            'password' => $validated['password'],
            'activo' => true,
        ])) {
            throw ValidationException::withMessages([
                'usuario' => 'Las credenciales no son válidas o el usuario no está activo.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('whatsapp.conversations'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
