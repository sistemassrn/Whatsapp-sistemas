<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Str;

class WhatsappContactSynchronizer
{
    private const DEFAULT_LIMIT = 100;

    private const DEFAULT_MAX_PAGES = 100;

    public function __construct(private OpenWaClient $client) {}

    /**
     * @return array{processed: int, created: int, updated: int, skipped: int}
     */
    public function sync(string $sessionId, ?int $limit = null, ?int $maxPages = null, bool $includeDiscovered = false): array
    {
        $limit = max(1, $limit ?? self::DEFAULT_LIMIT);
        $maxPages = max(1, $maxPages ?? self::DEFAULT_MAX_PAGES);
        $offset = 0;
        $processed = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $contacts = $this->items($this->client->contacts($sessionId, $limit, $offset), ['data', 'contacts', 'items']);

            if ($contacts === []) {
                break;
            }

            foreach ($contacts as $contactPayload) {
                if (! is_array($contactPayload)) {
                    $skipped++;

                    continue;
                }

                $contact = $this->upsertContact($contactPayload, $includeDiscovered);

                if ($contact === null) {
                    $skipped++;

                    continue;
                }

                $processed++;

                app(WhatsappConversationResolver::class)->verifyLid($contact->external_id, $sessionId);

                if ($contact->wasRecentlyCreated) {
                    $created++;
                } elseif ($contact->wasChanged()) {
                    $updated++;
                }
            }

            if (count($contacts) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return [
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertContact(array $payload, bool $includeDiscovered): ?Contact
    {
        $externalId = $this->firstString($payload, ['id', 'contactId', 'externalId', '_serialized']);

        if ($externalId === null) {
            return null;
        }

        $agendaContact = $this->agendaContact($payload, $externalId);

        if ($agendaContact === false && ! $includeDiscovered) {
            return null;
        }

        $contact = Contact::query()->firstOrNew(['external_id' => $externalId]);
        $attributes = [];

        foreach ($this->mappedAttributes($payload) as $key => $value) {
            if ($value !== null || ! $this->hasNonEmptyLocalValue($contact, $key)) {
                $attributes[$key] = $value;
            }
        }

        $attributes['is_my_contact'] = $agendaContact;
        $attributes['source'] = $agendaContact ? 'openwa-addressbook' : 'openwa-discovered';
        $attributes['last_synced_at'] = now();

        $contact->forceFill($attributes)->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{name: string|null, push_name: string|null, phone: string|null, profile_photo_url: string|null}
     */
    private function mappedAttributes(array $payload): array
    {
        return [
            'name' => $this->firstString($payload, ['name', 'shortName', 'formattedName']),
            'push_name' => $this->firstString($payload, ['pushName', 'notifyName']),
            'phone' => $this->firstString($payload, ['phone', 'number', 'user']),
            'profile_photo_url' => $this->firstString($payload, ['profilePicUrl', 'picture', 'profilePhotoUrl']),
        ];
    }

    private function hasNonEmptyLocalValue(Contact $contact, string $key): bool
    {
        $value = $contact->getAttribute($key);

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function agendaContact(array $payload, string $externalId): bool
    {
        $explicit = $this->firstBoolean($payload, ['isMyContact', 'is_my_contact', 'isAddressBookContact']);

        if ($explicit !== null) {
            return $explicit;
        }

        return $this->isIndividualContact($payload, $externalId)
            && $this->hasRealSavedName($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isIndividualContact(array $payload, string $externalId): bool
    {
        if ($this->firstBoolean($payload, ['isGroup', 'is_group', 'isBroadcast', 'is_broadcast', 'isStatus', 'is_status', 'isNewsletter', 'is_newsletter']) === true) {
            return false;
        }

        $normalizedId = Str::lower($externalId);

        if (str_contains($normalizedId, 'status@broadcast') || Str::endsWith($normalizedId, ['@g.us', '@broadcast', '@newsletter'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasRealSavedName(array $payload): bool
    {
        $name = $this->firstString($payload, ['name', 'shortName', 'formattedName']);

        if ($name === null) {
            return false;
        }

        $name = trim($name);

        if ($name === '' || strtolower($name) === 'mi num' || str_contains($name, '@')) {
            return false;
        }

        return preg_match('/[\p{L}]/u', $name) === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstBoolean(array $payload, array $keys): ?bool
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value === 1 ? true : ($value === 0 ? false : null);
            }

            if (is_string($value)) {
                $normalized = strtolower(trim($value));

                if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                    return true;
                }

                if (in_array($normalized, ['0', 'false', 'no'], true)) {
                    return false;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     * @param  list<string>  $containerKeys
     * @return list<mixed>
     */
    private function items(array $payload, array $containerKeys): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach ($containerKeys as $key) {
            $value = data_get($payload, $key);

            if (is_array($value)) {
                return array_is_list($value) ? $value : [$value];
            }
        }

        return [$payload];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
