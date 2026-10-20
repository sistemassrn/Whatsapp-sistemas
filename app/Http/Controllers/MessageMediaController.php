<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageMediaController extends Controller
{
    public function show(Request $request, Message $message): Response|StreamedResponse
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

            $size = $disk->size($message->media_path);
        } catch (\Throwable) {
            abort(404);
        }

        $mimeType = $message->media_mime_type ?: 'application/octet-stream';
        $filename = $message->media_filename ?: basename($message->media_path);
        $disposition = $this->shouldRenderInline($mimeType) ? 'inline' : 'attachment';

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition.'; filename="'.str_replace('"', '', $filename).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ];
        $range = $this->parseRange($request->header('Range'), $size);

        if ($range === false) {
            return response('', 416, ['Content-Range' => "bytes */{$size}"]);
        }

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $range === null ? $size : $end - $start + 1;

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $headers['Content-Length'] = (string) $length;

        return response()->stream(function () use ($disk, $message, $start, $length): void {
            $stream = $disk->readStream($message->media_path);

            if (! is_resource($stream)) {
                return;
            }

            try {
                if ($start > 0) {
                    fseek($stream, $start);
                }

                $remaining = $length;

                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $remaining));

                    if ($chunk === false) {
                        break;
                    }

                    $remaining -= strlen($chunk);
                    echo $chunk;
                }
            } finally {
                fclose($stream);
            }
        }, $range === null ? 200 : 206, $headers);
    }

    /**
     * @return array{int, int}|false|null
     */
    private function parseRange(?string $header, int $size): array|false|null
    {
        if ($header === null || $header === '') {
            return null;
        }

        if ($size === 0 || preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
            return false;
        }

        if ($matches[1] === '') {
            $suffixLength = $this->rangeInteger($matches[2]);

            if ($suffixLength === null || $suffixLength === 0) {
                return false;
            }

            return [max(0, $size - min($suffixLength, $size)), $size - 1];
        }

        $start = $this->rangeInteger($matches[1]);
        $requestedEnd = $matches[2] === '' ? null : $this->rangeInteger($matches[2]);

        if ($start === null || ($matches[2] !== '' && $requestedEnd === null) || $start >= $size || ($requestedEnd !== null && $requestedEnd < $start)) {
            return false;
        }

        return [$start, min($requestedEnd ?? ($size - 1), $size - 1)];
    }

    private function rangeInteger(string $value): ?int
    {
        if (strlen($value) > 18) {
            return null;
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    private function shouldRenderInline(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            || str_starts_with($mimeType, 'audio/')
            || str_starts_with($mimeType, 'video/')
            || $mimeType === 'application/pdf';
    }
}
