@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; $color = $service->pillar === 'ai' ? 'var(--accent)' : 'var(--brand)'; @endphp
@section('title', $service->t('title'))
@section('meta', $service->t('excerpt'))

@section('content')
  <article class="py-16 sm:py-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
      <a href="{{ route('services.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-faint transition hover:text-[var(--brand)]">
        <span class="rtl:rotate-180 inline-block">@include('partials.icon', ['name' => 'arrow', 'class' => 'h-4 w-4 rotate-180'])</span>
        {{ $ar ? 'كل الحلول' : 'All solutions' }}
      </a>
      <div class="mt-6 flex items-center gap-4">
        <span class="grid h-14 w-14 place-items-center rounded-2xl text-white shadow-lift" style="background:{{ $color }}">@include('partials.icon', ['name' => $service->icon ?? 'bolt', 'class' => 'h-7 w-7'])</span>
        <span class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide" style="background:color-mix(in srgb,{{ $color }} 12%,transparent);color:{{ $color }}">{{ strtoupper($service->pillar) }}</span>
      </div>
      <h1 class="mt-6 text-4xl font-bold tracking-tight sm:text-5xl">{{ $service->t('title') }}</h1>
      <p class="mt-4 text-xl text-ink-soft dark:text-slate-300">{{ $service->t('excerpt') }}</p>
      <div class="prose prose-slate mt-8 max-w-none dark:prose-invert">{!! $service->t('body') ?: '<p>'.e($service->t('excerpt')).'</p>' !!}</div>
      <div class="mt-10">
        <a href="{{ route('contact') }}" class="inline-flex items-center justify-center gap-2 rounded-xl px-7 py-3.5 font-semibold text-white shadow-lift transition hover:-translate-y-0.5 hover:brightness-110" style="background:var(--brand)">{{ $ar ? 'تحدّث إلى خبير' : 'Talk to an expert' }}</a>
      </div>
    </div>
  </article>
@endsection
