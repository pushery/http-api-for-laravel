<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Idempotency;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * What makes a request the one it is, for two requests under one `Idempotency-Key`.
 *
 * Its method, its path with its query, and what it sends. A write reads its fields from the query and the body alike,
 * so two requests that differ only in their query are two requests. PHP hands a multipart body over as fields and
 * files and leaves its content empty, so the fields, sorted by name, and each file by the hash of what it holds are
 * part of it too: without them two uploads of different files under one key would read as the same request.
 */
final class RequestFingerprint
{
    public static function of(Request $request): string
    {
        $query = $request->getQueryString();

        return hash('sha256', $request->method().' '.$request->path().($query === null ? '' : '?'.$query)."\n".$request->getContent().self::fieldsOf($request).self::filesOf($request));
    }

    /** The fields of a multipart request, where the content is empty. A request with content carries them in it. */
    private static function fieldsOf(Request $request): string
    {
        if ($request->getContent() !== '' || $request->request->count() === 0) {
            return '';
        }

        // serialize() rather than JSON: a field in another encoding than UTF-8 is a byte string like any other here.
        return "\n".serialize(Arr::sortRecursive($request->request->all()));
    }

    /** Each uploaded file by its field and the hash of what it holds. */
    private static function filesOf(Request $request): string
    {
        $files = '';

        foreach (Arr::dot($request->allFiles()) as $field => $file) {
            if ($file instanceof UploadedFile) {
                $hash = hash_file('sha256', $file->getPathname());
                $files .= "\n".$field.' '.($hash === false ? '' : $hash);
            }
        }

        return $files;
    }
}
