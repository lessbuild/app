@props(['name', 'class' => 'h-5 w-5'])

<svg {{ $attributes->merge(['class' => $class, 'fill' => 'none', 'viewBox' => '0 0 24 24', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round']) }} aria-hidden="true">
    @switch($name)
        @case('command')
            <path d="M18 9a3 3 0 1 0-3-3v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6a3 3 0 1 0-3 3h12Z" />
            @break
        @case('arrow-right')
            <path d="M5 12h14" /><path d="m13 6 6 6-6 6" />
            @break
        @case('grid')
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
            @break
        @case('activity')
            <path d="M3 12h4l2.2-7L13 19l2.3-7H21" />
            @break
        @case('bug')
            <path d="M9 9V7a3 3 0 0 1 6 0v2M7 12h10M8 16h8M5 10l2 2-2 2M19 10l-2 2 2 2M12 9v11M8 20h8" />
            @break
        @case('list')
            <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
            @break
        @case('settings')
            <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" /><path d="m19.4 15 .1.1a2 2 0 1 1-2.8 2.8l-.1-.1a2 2 0 0 0-3.4 1.4V19a2 2 0 1 1-4 0v-.2A2 2 0 0 0 5.8 17l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A2 2 0 0 0 1.6 11H2a2 2 0 1 1 0-4h.2A2 2 0 0 0 4 3.6l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A2 2 0 0 0 10.2 0H10a2 2 0 1 1 4 0h-.2a2 2 0 0 0 3.4 1.4l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A2 2 0 0 0 21.4 8h-.2a2 2 0 1 1 0 4h.2a2 2 0 0 0-2 3Z" transform="translate(1 2) scale(.83)" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('chevron-down')
            <path d="m6 9 6 6 6-6" />
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6" />
            @break
        @case('search')
            <circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" />
            @break
        @case('sun')
            <circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42" />
            @break
        @case('moon')
            <path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5Z" />
            @break
        @case('arrow-up-right')
            <path d="M7 17 17 7M7 7h10v10" />
            @break
        @case('arrow-down')
            <path d="M12 5v14M19 12l-7 7-7-7" />
            @break
        @case('code')
            <path d="m8 9-4 3 4 3M16 9l4 3-4 3M14 5l-4 14" />
            @break
        @case('more')
            <circle cx="5" cy="12" r="1" fill="currentColor" /><circle cx="12" cy="12" r="1" fill="currentColor" /><circle cx="19" cy="12" r="1" fill="currentColor" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
            @break
        @case('database')
            <ellipse cx="12" cy="5" rx="8" ry="3" /><path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7" />
            @break
        @case('inbox')
            <path d="M4 4h16l2 10v5H2v-5L4 4Z" /><path d="M2 14h5l2 3h6l2-3h5" />
            @break
        @case('check')
            <path d="m5 12 4 4L19 6" />
            @break
        @case('alert')
            <path d="m10.3 3.5-8 14A2 2 0 0 0 4 20.5h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" />
            @break
        @case('terminal')
            <path d="m4 5 6 7-6 7M13 19h7" />
            @break
        @case('shield')
            <path d="M12 3 20 6v5c0 5-3.4 8.6-8 10-4.6-1.4-8-5-8-10V6l8-3Z" /><path d="m9 12 2 2 4-4" />
            @break
        @case('external')
            <path d="M14 4h6v6M20 4l-9 9M18 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5" />
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16" />
            @break
        @case('x')
            <path d="m6 6 12 12M18 6 6 18" />
            @break
        @case('filter')
            <path d="M4 5h16M7 12h10M10 19h4" />
            @break
        @case('server')
            <rect x="3" y="4" width="18" height="6" rx="1" /><rect x="3" y="14" width="18" height="6" rx="1" /><path d="M7 7h.01M7 17h.01" />
            @break
        @case('globe')
            <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.2 2.4 3.3 5.4 3.3 9s-1.1 6.6-3.3 9c-2.2-2.4-3.3-5.4-3.3-9S9.8 5.4 12 3Z" />
            @break
        @case('credit-card')
            <rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h3" />
            @break
        @case('users')
            <path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM16 4.5a3.5 3.5 0 0 1 0 6.8M17 15h1.5a3.5 3.5 0 0 1 3.5 3.5V20" />
            @break
    @endswitch
</svg>
