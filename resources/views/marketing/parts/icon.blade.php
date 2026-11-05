{{-- A line icon from a fixed list (BlockTypes::ICONS). --}}
<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
    @case('school')<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/>@break
    @case('university')<path d="M3 21h18M5 21V10M19 21V10M9 21v-6h6v6M12 3l9 5H3z"/>@break
    @case('mosque')<path d="M4 21V11a8 8 0 0116 0v10M4 21h16M12 3v2M9 21v-5a3 3 0 016 0v5"/>@break
    @case('factory')<path d="M3 21V9l6 4V9l6 4V5h6v16z"/>@break
    @case('shop')<path d="M3 9l2-5h14l2 5M4 9h16v11H4zM9 20v-6h6v6"/>@break
    @case('briefcase')<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 012-2h4a2 2 0 012 2v2"/>@break
    @case('buildings')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="8.5" y="14" width="7" height="7" rx="1.5"/>@break
    @case('person')<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>@break
    @case('bolt')<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>@break
    @case('phone')<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>@break
    @case('lock')<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>@break
    @case('landmark')<path d="M3 21h18M4 10h16M12 3l9 5H3zM6 10v8M10 10v8M14 10v8M18 10v8"/>@break
    @case('globe')<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/>@break
    @case('chart')<path d="M3 21h18M6 17V10M11 17V6M16 17v-4M21 17V8"/>@break
    @case('users')<circle cx="9" cy="8" r="3.5"/><path d="M2 20a7 7 0 0114 0M16 4a3.5 3.5 0 010 7M18 14a6 6 0 014 6"/>@break
    @case('box')<path d="M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8"/>@break
    @case('heart')<path d="M12 20s-8-5-8-11a4.5 4.5 0 018-3 4.5 4.5 0 018 3c0 6-8 11-8 11z"/>@break
    @case('star')<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>@break
    @case('chat')<path d="M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12z"/>@break
    @default<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-2.9 1.2V21a2 2 0 01-4 0v-.1a1.7 1.7 0 00-2.9-1.2l-.1.1a2 2 0 11-2.8-2.8l.1-.1A1.7 1.7 0 003 15H3a2 2 0 010-4h.1A1.7 1.7 0 004.6 9l-.1-.1a2 2 0 112.8-2.8l.1.1A1.7 1.7 0 0010 5V3a2 2 0 014 0v.1a1.7 1.7 0 002.9 1.2l.1-.1a2 2 0 112.8 2.8l-.1.1A1.7 1.7 0 0021 9h.1a2 2 0 010 4H21a1.7 1.7 0 00-1.6 2z"/>
@endswitch
</svg>
