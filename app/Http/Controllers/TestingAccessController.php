<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TestingAccessController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'access_key' => ['required', 'string'],
        ]);

        $configuredKey = (string) config('testing-access.key', '');

        if ($configuredKey === '' || ! hash_equals($configuredKey, $validated['access_key'])) {
            throw ValidationException::withMessages([
                'access_key' => __('The temporary access key is invalid.'),
            ]);
        }

        $request->session()->put('testing_operator', config('testing-access.operator'));

        return redirect()->route('whatsapp.connect');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('testing_operator');

        return redirect()->route('home');
    }
}
