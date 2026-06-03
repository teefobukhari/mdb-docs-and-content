@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; @endphp
@section('title', $ar ? 'القطاعات' : 'Industries')

@section('content')
  <section class="pt-16 pb-12 sm:pt-20">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
      <span class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand)">{{ $ar ? 'القطاعات' : 'Industries' }}</span>
      <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">{{ $ar ? 'خبرة عبر القطاعات' : 'Expertise across sectors' }}</h1>
    </div>
  </section>
  <section class="pb-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
      <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($industries as $ind)
          <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-card transition hover:-translate-y-1 hover:border-[var(--brand)] dark:border-white/10 dark:bg-slate-900" data-reveal>
            <span class="grid h-12 w-12 place-items-center rounded-xl" style="background:color-mix(in srgb,var(--brand) 10%,transparent);color:var(--brand)">@include('partials.icon', ['name' => $ind->icon ?? 'bolt', 'class' => 'h-6 w-6'])</span>
            <h2 class="mt-4 text-lg font-semibold">{{ $ind->t('name') }}</h2>
            @if($ind->t('description'))<p class="mt-2 text-sm leading-relaxed text-ink-soft dark:text-slate-400">{{ $ind->t('description') }}</p>@endif
          </div>
        @endforeach
      </div>
    </div>
  </section>
@endsection
