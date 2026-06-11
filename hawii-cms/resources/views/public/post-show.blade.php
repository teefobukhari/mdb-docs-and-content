@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; @endphp
@section('title', $post->t('title'))
@section('meta', $post->t('excerpt'))

@section('content')
  <article class="py-16 sm:py-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
      <a href="{{ route('blog.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-faint transition hover:text-[var(--brand)]">
        <span class="inline-block rtl:rotate-180">@include('partials.icon', ['name' => 'arrow', 'class' => 'h-4 w-4 rotate-180'])</span>
        {{ $ar ? 'كل المقالات' : 'All posts' }}
      </a>
      <h1 class="mt-6 text-4xl font-bold tracking-tight sm:text-5xl">{{ $post->t('title') }}</h1>
      <div class="mt-4 flex items-center gap-3 text-sm text-ink-faint dark:text-slate-400">
        @if($post->author){{ $post->author->name }} ·@endif
        @if($post->published_at)<time>{{ $post->published_at->translatedFormat('d M Y') }}</time>@endif
      </div>
      <div class="prose prose-slate mt-8 max-w-none dark:prose-invert">{!! $post->t('body') !!}</div>
    </div>
  </article>
@endsection
