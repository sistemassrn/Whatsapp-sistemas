<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

class WhatsappConversationResolver
{
    public function verifyLid(string $externalId, ?string $sessionId = null): void
    {
        if (! str_ends_with(strtolower($externalId), '@lid') || Contact::query()->where('external_id', $externalId)->whereNotNull('verified_phone')->exists()) {
            return;
        }

        try {
            $client = app(OpenWaClient::class);

            if ($sessionId === null) {
                $session = $client->findSessionByName((string) config('openwa.session_name'));
                $sessionId = $session['id'] ?? $session['_id'] ?? $session['sessionId'] ?? null;
            }

            if (! is_string($sessionId) || $sessionId === '') {
                return;
            }

            $phone = $this->normalizePhone($client->contactPhone($sessionId, $externalId));
        } catch (ConnectionException|RequestException) {
            return;
        }

        if ($phone !== null) {
            $contact = Contact::query()->firstOrCreate(['external_id' => $externalId]);
            $contact->forceFill(['verified_phone' => $phone])->save();
        }
    }

    public function resolve(string $externalId, ?Contact $contact = null, ?string $title = null, bool $unhide = false): Conversation
    {
        return DB::transaction(function () use ($externalId, $contact, $title, $unhide): Conversation {
            /**
             * Legacy phones are not normalized/indexed. Keep normalization in PHP (also
             * works on SQL Server), and lock selection and creation together. SQL Server
             * compiles this to UPDLOCK/HOLDLOCK, including the empty range.
             */
            $conversations = Conversation::query()->orderBy('id')->lockForUpdate()->get();
            $contacts = Contact::query()->get()->keyBy('id');
            $identities = $contacts->keyBy('external_id');
            $exact = $conversations->firstWhere('external_id', $externalId);
            $phone = $this->identityPhone($externalId, $contact, $identities->get($externalId)?->verified_phone)
                ?? ($exact === null ? null : $this->identityPhone($exact->external_id, $contacts->get($exact->contact_id), $identities->get($exact->external_id)?->verified_phone));

            $conversation = $conversations
                ->filter(function (Conversation $candidate) use ($externalId, $phone, $contacts, $identities): bool {
                    return $candidate->external_id === $externalId
                        || ($phone !== null && $this->identityPhone($candidate->external_id, $contacts->get($candidate->contact_id), $identities->get($candidate->external_id)?->verified_phone) === $phone);
                })
                ->sortBy(fn (Conversation $candidate): array => [$candidate->hidden_at !== null, $candidate->id])
                ->first();

            if ($conversation === null) {
                $conversation = new Conversation(['external_id' => $externalId, 'title' => $title]);
            }

            if ($conversation->contact_id === null && $contact !== null) {
                $conversation->contact_id = $contact->id;
            }

            if ($unhide) {
                $conversation->hidden_at = null;
                $conversation->hidden_reason = null;
            }

            if (! $conversation->exists || $conversation->isDirty()) {
                $conversation->save();
            }

            return $conversation;
        }, attempts: 3);
    }

    public function normalizePhone(?string $value): ?string
    {
        $value = trim($value ?? '');

        if (preg_match('/^(\d+)@(c\.us|s\.whatsapp\.net)$/i', $value, $matches) === 1) {
            $value = $matches[1];
        }

        if (preg_match('/^\+?[\d\s().-]+$/', $value) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    private function identityPhone(string $externalId, ?Contact $contact, ?string $verifiedPhone = null): ?string
    {
        if (preg_match('/@(g\.us|broadcast|newsletter)$/i', $externalId) === 1) {
            return null;
        }

        $addressPhone = $this->normalizePhone($externalId);
        $contactAddressPhone = $this->normalizePhone($contact?->external_id);

        if ($addressPhone !== null && $contactAddressPhone !== null && $addressPhone !== $contactAddressPhone) {
            return $addressPhone;
        }

        if (str_ends_with(strtolower($externalId), '@lid')) {
            return $this->normalizePhone($verifiedPhone);
        }

        if ($contact !== null && str_contains($contact->external_id, '@') && $contactAddressPhone === null) {
            return $addressPhone;
        }

        return $addressPhone ?? $contactAddressPhone ?? $this->normalizePhone($contact?->phone);
    }
}
