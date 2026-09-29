<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\OpenWaClient;
use App\Services\WhatsappConnectionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class WhatsappContactAvatarController extends Controller
{
    public function __invoke(Request $request, Contact $contact, WhatsappConnectionStatus $connectionStatus, OpenWaClient $client): JsonResponse
    {
        $force = $request->boolean('force');

        if (! $force && $this->hasFreshAvatarLookup($contact)) {
            return response()->json([
                'avatar_url' => $contact->profile_photo_url,
            ]);
        }

        $connection = $connectionStatus->openwa(autoStart: false, includeQr: false);
        $session = Arr::get($connection, 'session');
        $sessionId = is_array($session) ? $connectionStatus->sessionId($session) : null;

        if (! $connection['isReady'] || $sessionId === null) {
            return $this->markAvatarError($contact, 'No pudimos cargar la foto.');
        }

        try {
            $avatarUrl = $client->contactProfilePicture($sessionId, $contact->external_id);

            $contact->forceFill([
                'profile_photo_url' => $avatarUrl,
                'profile_photo_fetched_at' => now(),
                'profile_photo_error' => null,
            ])->save();

            return response()->json([
                'avatar_url' => $avatarUrl,
            ]);
        } catch (Throwable) {
            return $this->markAvatarError($contact, 'No pudimos cargar la foto.');
        }
    }

    private function hasFreshAvatarLookup(Contact $contact): bool
    {
        return $contact->profile_photo_fetched_at !== null
            && $contact->profile_photo_fetched_at->greaterThanOrEqualTo(now()->subDay());
    }

    private function markAvatarError(Contact $contact, string $message): JsonResponse
    {
        $contact->forceFill([
            'profile_photo_fetched_at' => now(),
            'profile_photo_error' => $message,
        ])->save();

        return response()->json([
            'avatar_url' => $contact->profile_photo_url,
            'message' => $message,
        ], 200);
    }
}
