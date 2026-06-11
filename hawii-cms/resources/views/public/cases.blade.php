@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; @endphp
@section('title', $ar ? 'دراسات الحالة' : 'Case Studies')

@section('content')
  <section class="pt-16 pb-12 sm:pt-20">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
      <span class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand)">{{ $ar ? 'دراسات الحالة' : 'Case Studies' }}</span>
      <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">{{ $ar ? 'نتائج حقيقية لعملاء حقيقيين' : 'Real outcomes for real clients' }}</h1>
    </div>
  </section>
  <section class="pb-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
      @if($cases->count())
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
          @foreach($cases as $c)
            <a href="{{ route('cases.show', $c->slug) }}" class="group flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-card transition hover:-translate-y-1 hover:shadow-lift dark:border-white/10 dark:bg-slate-900" data-reveal>
              @if($c->t('industry'))<span class="self-start rounded-full px-3 py-1 text-xs font-semibold" style="background:color-mix(in srgb,var(--brand) 10%,transparent);color:var(--brand)">{{ $c->t('industry') }}</span>@endif
              @if($c->t('client'))<div class="mt-3 text-sm font-medium text-ink-faint dark:text-slate-400">{{ $c->t('client') }}</div>@endif
              <h2 class="mt-1 text-lg font-bold transition group-hover:text-[var(--brand)]">{{ $c->t('title') }}</h2>
              <p class="mt-2 text-sm leading-relaxed text-ink-soft dark:text-slate-400">{{ $c->t('summary') }}</p>
            </a>
          @endforeach
        </div>
      @else
        <p class="text-center text-ink-faint">{{ $ar ? 'لا توجد دراسات حالة بعد.' : 'No case studies yet.' }}</p>
      @endif
    </div>
  </section>
@endsection
