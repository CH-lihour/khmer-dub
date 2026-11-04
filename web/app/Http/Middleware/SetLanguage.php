<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the site in the visitor's language: the one they chose, or else their browser's.
 */
class SetLanguage
{
    /** The site's languages. The first is the default. Khmer text is in lang/km.json. */
    public const LANGUAGES = ['en', 'km'];

    /** The cookie that remembers the choice, also after logging out. */
    public const COOKIE = 'lang';

    /**
     * Set the language for this request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $chosen = $request->cookie(self::COOKIE);
        App::setLocale(in_array($chosen, self::LANGUAGES, true) ? $chosen : $request->getPreferredLanguage(self::LANGUAGES));

        return $next($request);
    }
}
