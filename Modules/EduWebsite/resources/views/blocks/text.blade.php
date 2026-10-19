@include('edu_website::blocks.title', ['eyebrow' => 'about'])
<div class="prose">{{ $text($data['body'] ?? null) }}</div>
