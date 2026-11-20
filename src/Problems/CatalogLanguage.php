<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Problems;

use Illuminate\Contracts\Translation\Translator;

/**
 * The language the package's own texts are written in under the locale of the application, as `Content-Language`
 * names it.
 *
 * Each catalog of the package names its language under `http-api::messages.language`. The translator reads the
 * catalog of the application's locale, for a regional locale the one of its language, and the fallback locale's where
 * neither exists, so the language it returns is the one the document's texts came from. Where no catalog answers at
 * all, the titles are the English phrases of their statuses, and the language is English.
 *
 * An application that adds a catalog for a language the package does not ship names the language in it the same way.
 */
final class CatalogLanguage
{
    private const string KEY = 'http-api::messages.language';

    public static function of(Translator $translator): string
    {
        $language = $translator->get(self::KEY);

        return is_string($language) && $language !== self::KEY ? $language : 'en';
    }
}
