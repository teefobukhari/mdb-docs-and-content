@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; @endphp
@section('title', $ar ? 'الحلول' : 'Solutions')

@section('content')
  <section class="pt-16 pb-12 sm:pt-20">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
      <span class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand)">{{ $ar ? 'حلولنا' : 'Our Solutions' }}</span>
      <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">{{ $ar ? 'حلول تقنية وذكاء اصطناعي شاملة' : 'End-to-end IT & AI solutions' }}</h1>
      <p class="mt-4 text-lg text-ink-soft dark:text-slate-300">{{ $ar ? 'من البنية التحتية إلى الذكاء الاصطناعي الجاهز للإنتاج.' : 'From the infrastructure that runs your business to the AI that reinvents it.' }}</p>
    </div>
  </section>

  <section class="pb-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="grid gap-8 lg:grid-cols-2">
        @foreach([['it', $itServices, $ar ? 'حلول تقنية المعلومات' : 'IT Solutions', 'var(--brand)'], ['ai', $aiServices, $ar ? 'حلول الذكاء الاصطناعي' : 'AI Solutions', 'var(--accent)']] as [$pillar, $items, $heading, $color])
          <div data-reveal>
            <div class="mb-6 flex items-center gap-3">
              <span class="grid h-11 w-11 place-items-center rounded-xl text-white" style="background:{{ $color }}">@include('partials.icon', ['name' => $pillar === 'it' ? 'workflow' : 'bot', 'class' => 'h-6 w-6'])</span>
              <h2 class="text-2xl font-bold">{{ $heading }}</h2>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              @foreach($items as $s)
                <a href="{{ route('services.show', $s->slug) }}" class="group block rounded-2xl border border-slate-200 bg-white p-5 shadow-card transition hover:-translate-y-1 hover:shadow-lift dark:border-white/10 dark:bg-slate-900">
                  <span class="grid h-10 w-10 place-items-center rounded-lg bg-slate-100 dark:bg-white/5" style="color:{{ $color }}">@include('partials.icon', ['name' => $s->icon ?? 'bolt', 'class' => 'h-5 w-5'])</span>
                  <h3 class="mt-3 font-semibold">{{ $s->t('title') }}</h3>
                  <p class="mt-1.5 text-sm leading-relaxed text-ink-soft dark:text-slate-400">{{ $s->t('excerpt') }}</p>
                </a>
              @endforeach
            </div>
          </div>
        @endforeach
      </div>
    </div>
  </section>
@endsection
