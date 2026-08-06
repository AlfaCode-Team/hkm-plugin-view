<?php

declare(strict_types=1);

if (!function_exists('esc')) {
    /**
     * Escape a value for HTML TEXT content.
     *
     *     <p><?= esc($post->title) ?></p>
     *
     * Templates are plain PHP and therefore NOT auto-escaped: every `<?= $x ?>`
     * writes raw bytes. That is the single biggest XSS footgun in the renderer,
     * so the convention is that every interpolation of non-constant data goes
     * through esc() (or esc_attr()/esc_url() where the context differs), and
     * anything deliberately trusted is marked with a comment saying why.
     *
     * ENT_QUOTES|ENT_SUBSTITUTE: quotes matter because the same helper is often
     * reached for inside an attribute, and ENT_SUBSTITUTE turns malformed UTF-8
     * into U+FFFD rather than returning an EMPTY string — silently dropping the
     * whole value is how escaping bugs hide.
     */
    function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    /**
     * Escape for an HTML ATTRIBUTE value. Always quote the attribute:
     *
     *     <a title="<?= esc_attr($t) ?>">
     *
     * An unquoted attribute cannot be made safe by escaping alone, because a
     * bare space ends the value.
     */
    function esc_attr(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    /**
     * Escape a URL for an href/src attribute, rejecting dangerous schemes.
     *
     * Escaping alone does not help against `javascript:alert(1)` — it contains
     * no HTML metacharacters, so htmlspecialchars() passes it through untouched
     * and the browser still executes it on click. Only http/https/mailto/tel and
     * relative URLs survive; anything else becomes '' (an inert link).
     */
    function esc_url(mixed $value): string
    {
        $url    = trim((string) $value);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme !== null && !in_array(strtolower((string) $scheme), ['http', 'https', 'mailto', 'tel'], true)) {
            return '';
        }

        // Scheme-relative "//evil.test" inherits the page scheme — still a
        // remote origin, but not script execution; escape and allow.
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_json')) {
    /**
     * Encode a value for embedding in an inline <script> block.
     *
     * JSON_HEX_TAG is the point: without it a value containing "</script>"
     * closes the tag and everything after it is parsed as HTML.
     */
    function esc_json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );
    }
}
