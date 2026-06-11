@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; @endphp
@section('title', $case->t('title'))
@section('meta', $case->t('summary'))

@section('content')
  <article class="py-16 sm:py-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
      <a href="{{ route('cases.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-faint transition hover:text-[var(--brand)]">
        <span class="inline-block rtl:rotate-180">@include('partials.icon', ['name' => 'arrow', 'class' => 'h-4 w-4 rotate-180'])</span>
        {{ $ar ? 'كل دراسات الحالة' : 'All case studies' }}
      </a>
      @if($case->t('industry'))<span class="mt-6 inline-block rounded-full px-3 py-1 text-xs font-semibold" style="background:color-mix(in srgb,var(--brand) 10%,transparent);color:var(--brand)">{{ $case->t('industry') }}</span>@endif
      @if($case->t('client'))<div class="mt-3 text-sm font-medium text-ink-faint dark:text-slate-400">{{ $case->t('client') }}</div>@endif
      <h1 class="mt-1 text-4xl font-bold tracking-tight sm:text-5xl">{{ $case->t('title') }}</h1>
      <p class="mt-4 text-xl text-ink-soft dark:text-slate-300">{{ $case->t('summary') }}</p>
      <div class="prose prose-slate mt-8 max-w-none dark:prose-invert">{!! $case->t('body') !!}</div>
    </div>
  </article>
@endsection
