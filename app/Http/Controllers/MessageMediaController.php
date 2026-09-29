<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class MessageMediaController extends Controller
{
    public function show(Message $message): Response
    {
        if ($message->media_disk !== 'whatsapp_media' || ! is_string($message->media_path) || $message->media_path === '') {
            abort(404);
        }

        if (str_contains($message->media_path, '..') || str_starts_with($message->media_path, '/') || str_starts_with($message->media_path, '\\')) {
            abort(403);
        }

        try {
            $disk = Storage::disk('whatsapp_media');

            if (! $disk->exists($message->media_path)) {
                abort(404);
            }

            $contents = $disk->get($message->media_path);
        } catch (\Throwable) {
            abort(404);
        }

        $mimeType = $message->media_mime_type ?: 'application/octet-stream';
        $filename = $message->media_filename ?: basename($message->media_path);
        $disposition = $this->shouldRenderInline($mimeType) ? 'inline' : 'attachment';

        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition.'; filename="'.str_replace('"', '', $filename).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function shouldRenderInline(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            || str_starts_with($mimeType, 'audio/')
            || str_starts_with($mimeType, 'video/')
            || $mimeType === 'application/pdf';
    }
}
