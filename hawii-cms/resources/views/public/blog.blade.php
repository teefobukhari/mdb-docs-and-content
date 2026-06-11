@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; @endphp
@section('title', $ar ? 'المدونة' : 'Blog')

@section('content')
  <section class="pt-16 pb-12 sm:pt-20">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
      <span class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand)">{{ $ar ? 'المدونة' : 'Insights' }}</span>
      <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">{{ $ar ? 'أفكار حول التقنية والذكاء الاصطناعي' : 'Ideas on IT & AI' }}</h1>
    </div>
  </section>
  <section class="pb-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
      @if($posts->count())
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
          @foreach($posts as $post)
            <a href="{{ route('blog.show', $post->slug) }}" class="group flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-card transition hover:-translate-y-1 hover:shadow-lift dark:border-white/10 dark:bg-slate-900" data-reveal>
              @if($post->published_at)<time class="text-xs font-medium text-ink-faint dark:text-slate-400">{{ $post->published_at->translatedFormat('d M Y') }}</time>@endif
              <h2 class="mt-2 text-lg font-bold transition group-hover:text-[var(--brand)]">{{ $post->t('title') }}</h2>
              <p class="mt-2 flex-1 text-sm leading-relaxed text-ink-soft dark:text-slate-400">{{ $post->t('excerpt') }}</p>
              <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold" style="color:var(--brand)">{{ $ar ? 'اقرأ المزيد' : 'Read more' }}<span class="inline-block rtl:rotate-180">@include('partials.icon', ['name' => 'arrow', 'class' => 'h-4 w-4'])</span></span>
            </a>
          @endforeach
        </div>
        <div class="mt-10">{{ $posts->links() }}</div>
      @else
        <p class="text-center text-ink-faint">{{ $ar ? 'لا توجد مقالات بعد.' : 'No posts yet.' }}</p>
      @endif
    </div>
  </section>
@endsection
