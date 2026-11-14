<?php namespace Elv\Kit\Classes;

/**
 * Icons — инлайн-SVG вместо иконочного шрифта и внешних спрайтов.
 *
 * Каждая иконка это строки внутри одного `<svg>`: ноль запросов, ноль
 * заблокированного рендера, цвет наследуется от текста.
 */
class Icons
{
    protected const PATHS = [
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'arrow-right' => '<path d="M4 12h15"/><path d="m13 6 6 6-6 6"/>',
        'arrow-up-right' => '<path d="M7 17 17 7"/><path d="M8 7h9v9"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
        'bolt' => '<path d="M13 2 4 14h6l-1 8 9-12h-6z"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
        'cube' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
        'cubes' => '<path d="m7 3 4 2.5v5L7 13l-4-2.5v-5L7 3Z"/><path d="m17 11 4 2.5v5L17 21l-4-2.5v-5L17 11Z"/>',
        'grid' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
        'cart' => '<circle cx="9" cy="19" r="1.6"/><circle cx="18" cy="19" r="1.6"/><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'calendar-check' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/><path d="m9 15 2 2 4-4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'message' => '<path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.5-5A8 8 0 1 1 21 12Z"/><path d="M8 11h8M8 15h5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'phone' => '<path d="M6 3h3l2 5-2.5 1.5a12 12 0 0 0 6 6L16 13l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2Z"/>',
        'map-pin' => '<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        'book' => '<path d="M4 5a2 2 0 0 1 2-2h6v18H6a2 2 0 0 1-2-2z"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
        'pencil' => '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="m14 6 4 4"/>',
        'speed' => '<path d="M4 18a8 8 0 1 1 16 0"/><path d="m12 14 4-4"/><path d="M4 18h16"/>',
        'shield' => '<path d="M12 3 5 6v5c0 5 3.5 8 7 10 3.5-2 7-5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l2 2M14 9l2 2"/>',
        'code' => '<path d="m9 7-5 5 5 5M15 7l5 5-5 5"/>',
        'database' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'api' => '<path d="M4 9V7a3 3 0 0 1 3-3h2M20 15v2a3 3 0 0 1-3 3h-2"/><path d="M9 12h6"/><path d="M12 9v6"/>',
        'seo' => '<circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/><path d="M8 11h6M11 8v6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m16.5 16.5 4 4"/>',
        'download' => '<path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.4-5.7"/><path d="M20 4v5h-5"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.3.9-4.5 4.4 1 6.1-5.5-2.9L6.5 20l1-6.1L3 9.5l6.3-.9z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 21a6.5 6.5 0 0 1 13 0"/><path d="M16 5.5a3.5 3.5 0 0 1 0 7M17.5 15a6 6 0 0 1 4 6"/>',
        'store' => '<path d="M4 9h16v11H4z"/><path d="M3 9l2-5h14l2 5"/><path d="M9 20v-6h6v6"/>',
        'tag' => '<path d="M4 4h7l9 9-7 7-9-9z"/><circle cx="8.5" cy="8.5" r="1.5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18Z"/>',
        'rocket' => '<path d="M12 3c4 1.5 6.5 5 7 9l-4 4H9l-4-4c.5-4 3-7.5 7-9Z"/><circle cx="12" cy="10" r="2"/><path d="m8 16-2 5 4-2M16 16l2 5-4-2"/>',
        'wrench' => '<path d="M15 4a5 5 0 0 0-4.6 7L4 17.4 6.6 20l6.4-6.4A5 5 0 0 0 20 8l-3 3-3-3 3-3a5 5 0 0 0-2-1Z"/>',
        'puzzle' => '<path d="M10 4a2 2 0 1 1 4 0v2h3a1 1 0 0 1 1 1v3h2a2 2 0 1 1 0 4h-2v3a1 1 0 0 1-1 1h-3v-2a2 2 0 1 0-4 0v2H6a1 1 0 0 1-1-1v-3H3a2 2 0 1 1 0-4h2V7a1 1 0 0 1 1-1h4z"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18"/><circle cx="17" cy="15" r="1.4"/>',
        'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z"/>',
        'quote' => '<path d="M9 6c-3 1-5 3.5-5 7v5h5v-5H6c0-2.5 1-4 3-5zM20 6c-3 1-5 3.5-5 7v5h5v-5h-3c0-2.5 1-4 3-5z"/>',
        'telegram' => '<path d="m21 4-3 16-6-4-3 3v-5l9-7-11 6-4-1z"/>',
        'whatsapp' => '<path d="M20.5 11.6a8.5 8.5 0 0 1-12.7 7.4L4 20l1.1-3.7A8.5 8.5 0 1 1 20.5 11.6Z"/><path d="M8.6 8.2c.2-.5.4-.5.7-.5h.5c.2 0 .4 0 .5.4l.7 1.6c.1.2.1.4 0 .6l-.5.6c-.2.2-.1.4 0 .6.6 1 1.5 1.9 2.6 2.4.2.1.4.1.6-.1l.6-.6c.2-.2.4-.2.6-.1l1.6.7c.3.1.4.3.4.5 0 .5-.1 1.1-.5 1.5-.4.4-1.1.7-1.9.7-1.2 0-2.9-.6-4.4-2s-2.1-3.1-2.1-4.3c0-.8.2-1.5.6-2Z"/>',
        'send' => '<path d="m21 3-9 18-2.5-6.5L3 12 21 3Z"/><path d="m21 3-9 9"/>',
        'expand' => '<path d="M9 4H4v5"/><path d="M15 4h5v5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>',
        'arrow-up' => '<path d="M12 20V4"/><path d="m6 10 6-6 6 6"/>',
        'github' => '<path d="M9 19c-4 1.5-4-2-6-2M15 21v-3.5c0-1 .1-1.6-.5-2.3 2.5-.3 5-1.2 5-5.4a4 4 0 0 0-1.1-2.8 3.7 3.7 0 0 0-.1-2.8s-1.2-.3-3.8 1.4a13 13 0 0 0-6.8 0C5.1 3.9 3.9 4.2 3.9 4.2a3.7 3.7 0 0 0-.1 2.8A4 4 0 0 0 2.7 9.8c0 4.2 2.5 5.1 5 5.4-.4.5-.5 1-.5 1.8V21"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'filter' => '<path d="M4 5h16l-6 7v6l-4 2v-8z"/>',
        'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'wallet-card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
        'qr' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14h1M14 20h3M20 17v4"/>',
    ];

    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    public static function svg(string $name, string $class = ''): string
    {
        $inner = self::PATHS[$name] ?? self::PATHS['info'];
        $classes = trim('elv-icon ' . $class);

        return '<svg class="' . e($classes) . '" viewBox="0 0 24 24" width="24" height="24" fill="none"'
            . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}
