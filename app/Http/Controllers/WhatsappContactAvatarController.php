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

        $resolvedAvatarUrl = null;
        $lookupSucceeded = false;

        foreach ($this->avatarContactIds($contact) as $contactId) {
            try {
                $avatarUrl = $client->contactProfilePicture($sessionId, $contactId);
                $lookupSucceeded = true;

                if ($avatarUrl !== null && $avatarUrl !== '') {
                    $resolvedAvatarUrl = $avatarUrl;

                    break;
                }
            } catch (Throwable) {
                continue;
            }
        }

        if (! $lookupSucceeded) {
            return $this->markAvatarError($contact, 'No pudimos cargar la foto.');
        }

        $contact->forceFill([
            'profile_photo_url' => $resolvedAvatarUrl,
            'profile_photo_fetched_at' => now(),
            'profile_photo_error' => null,
        ])->save();

        return response()->json([
            'avatar_url' => $resolvedAvatarUrl,
        ]);
    }

    private function hasFreshAvatarLookup(Contact $contact): bool
    {
        return $contact->profile_photo_fetched_at !== null
            && $contact->profile_photo_fetched_at->greaterThanOrEqualTo(now()->subDay());
    }

    /**
     * @return list<string>
     */
    private function avatarContactIds(Contact $contact): array
    {
        $contactIds = [];

        $this->addAvatarContactId($contactIds, $contact->external_id);

        if (is_string($contact->phone)) {
            $phone = preg_replace('/\D+/', '', $contact->phone);

            if (is_string($phone) && $phone !== '') {
                $this->addAvatarContactId($contactIds, "{$phone}@c.us");
            }
        }

        return $contactIds;
    }

    /**
     * @param  list<string>  $contactIds
     */
    private function addAvatarContactId(array &$contactIds, ?string $contactId): void
    {
        if ($contactId === null || $contactId === '') {
            return;
        }

        if (in_array($contactId, $contactIds, true)) {
            return;
        }

        $contactIds[] = $contactId;
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
