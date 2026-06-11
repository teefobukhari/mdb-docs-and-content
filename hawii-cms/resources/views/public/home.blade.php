@extends('layouts.public')
@php
    $ar = app()->getLocale() === 'ar';
    $S = fn($k, $d = '') => \App\Models\Setting::trans($k, null, $d);
@endphp

@section('content')
  {{-- HERO --}}
  <section class="relative overflow-hidden pt-16 pb-20 sm:pt-24 sm:pb-28">
    <div class="grid-bg pointer-events-none absolute inset-0"></div>
    <div class="pointer-events-none absolute -top-24 start-1/2 h-72 w-72 -translate-x-1/2 rounded-full blur-3xl" style="background:color-mix(in srgb,var(--brand) 22%,transparent)"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="mx-auto max-w-3xl text-center">
        <span class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-medium" style="border-color:color-mix(in srgb,var(--brand) 25%,transparent);background:color-mix(in srgb,var(--brand) 6%,transparent);color:var(--brand)">
          <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75" style="background:var(--accent)"></span><span class="relative inline-flex h-2 w-2 rounded-full" style="background:var(--accent)"></span></span>
          {{ $S('tagline') }}
        </span>
        <h1 class="mt-6 text-4xl font-bold leading-[1.1] tracking-tight sm:text-5xl lg:text-6xl">{{ $S('hero_title') }}</h1>
        <p class="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-ink-soft dark:text-slate-300">{{ $S('hero_subtitle') }}</p>
        <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
          <a href="{{ route('contact') }}" class="group inline-flex w-full items-center justify-center gap-2 rounded-xl px-7 py-3.5 text-base font-semibold text-white shadow-lift transition hover:-translate-y-0.5 hover:brightness-110 sm:w-auto" style="background:var(--brand)">
            {{ $ar ? 'احجز استشارة مجانية' : 'Book a free consultation' }}
            <span class="rtl:rotate-180">@include('partials.icon', ['name' => 'arrow', 'class' => 'h-4 w-4'])</span>
          </a>
          <a href="{{ route('services.index') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-7 py-3.5 text-base font-semibold text-ink transition hover:border-[var(--brand)] hover:text-[var(--brand)] dark:border-white/15 dark:bg-white/5 dark:text-white sm:w-auto">{{ $ar ? 'استكشف الحلول' : 'Explore solutions' }}</a>
        </div>
        <p class="mt-5 text-sm text-ink-faint dark:text-slate-400">{{ $S('hero_badge') }}</p>
      </div>

      {{-- STATS --}}
      @if($stats->count())
      <div class="mx-auto mt-16 grid max-w-4xl grid-cols-2 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 shadow-card md:grid-cols-4 dark:border-white/10 dark:bg-white/10" data-reveal>
        @foreach($stats as $stat)
          <div class="bg-white p-6 text-center dark:bg-slate-900">
            <div class="text-3xl font-bold" style="color:var(--brand)">{{ $stat->value }}</div>
            <div class="mt-1 text-sm text-ink-faint dark:text-slate-400">{{ $stat->t('label') }}</div>
          </div>
        @endforeach
      </div>
      @endif
    </div>
  </section>

  {{-- SOLUTIONS --}}
  <section id="solutions" class="py-20 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="mx-auto max-w-2xl text-center" data-reveal>
        <span class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand)">{{ $ar ? 'حلولنا' : 'Our Solutions' }}</span>
        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">{{ $ar ? 'ركيزتان. شريك واحد.' : 'Two pillars. One partner.' }}</h2>
      </div>
      <div class="mt-14 grid gap-8 lg:grid-cols-2">
        @foreach([['it', $itServices, $ar ? 'حلول تقنية المعلومات' : 'IT Solutions', 'var(--brand)'], ['ai', $aiServices, $ar ? 'حلول الذكاء الاصطناعي' : 'AI Solutions', 'var(--accent)']] as [$pillar, $items, $heading, $color])
          <div data-reveal>
            <div class="mb-6 flex items-center gap-3">
              <span class="grid h-11 w-11 place-items-center rounded-xl text-white" style="background:{{ $color }}">@include('partials.icon', ['name' => $pillar === 'it' ? 'workflow' : 'bot', 'class' => 'h-6 w-6'])</span>
              <h3 class="text-2xl font-bold">{{ $heading }}</h3>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              @foreach($items as $s)
                <a href="{{ route('services.show', $s->slug) }}" class="group block rounded-2xl border border-slate-200 bg-white p-5 shadow-card transition hover:-translate-y-1 hover:shadow-lift dark:border-white/10 dark:bg-slate-900" style="--hover:{{ $color }}">
                  <span class="grid h-10 w-10 place-items-center rounded-lg bg-slate-100 transition group-hover:text-white dark:bg-white/5" style="color:{{ $color }}" onmouseover="this.style.background='{{ $color }}'" onmouseout="this.style.background=''">@include('partials.icon', ['name' => $s->icon ?? 'bolt', 'class' => 'h-5 w-5'])</span>
                  <h4 class="mt-3 font-semibold">{{ $s->t('title') }}</h4>
                  <p class="mt-1.5 text-sm leading-relaxed text-ink-soft dark:text-slate-400">{{ $s->t('excerpt') }}</p>
                </a>
              @endforeach
            </div>
          </div>
        @endforeach
      </div>
    </div>
  </section>

  {{-- INDUSTRIES --}}
  @if($industries->count())
  <section class="bg-white py-20 dark:bg-slate-900/40 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="mx-auto max-w-2xl text-center" data-reveal>
        <span class="text-sm font-semibold uppercase tracking-wider" style="color:var(--brand)">{{ $ar ? 'القطاعات' : 'Industries' }}</span>
        <h2 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">{{ $ar ? 'حلول مصمّمة لقطاعك' : 'Solutions tuned to your sector' }}</h2>
      </div>
      <div class="mt-12 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6" data-reveal>
        @foreach($industries as $ind)
          <div class="flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-card transition hover:-translate-y-1 hover:border-[var(--brand)] dark:border-white/10 dark:bg-slate-900">
            <span style="color:var(--brand)">@include('partials.icon', ['name' => $ind->icon ?? 'bolt', 'class' => 'h-7 w-7'])</span>
            <span class="text-sm font-medium">{{ $ind->t('name') }}</span>
          </div>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  {{-- TESTIMONIAL --}}
  @if($testimonials->count())
  <section class="py-20 sm:py-28">
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
      @foreach($testimonials as $t)
      <figure class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-card dark:border-white/10 dark:bg-slate-900 sm:p-12" data-reveal>
        <span class="mx-auto inline-block" style="color:color-mix(in srgb,var(--brand) 30%,transparent)">@include('partials.icon', ['name' => 'quote', 'class' => 'h-9 w-9'])</span>
        <blockquote class="mt-6 text-xl font-medium leading-relaxed sm:text-2xl">{{ $t->t('quote') }}</blockquote>
        <figcaption class="mt-6 flex items-center justify-center gap-3">
          <span class="grid h-11 w-11 place-items-center rounded-full font-semibold" style="background:color-mix(in srgb,var(--brand) 10%,transparent);color:var(--brand)">{{ $t->initials }}</span>
          <div class="text-start">
            <div class="font-semibold">{{ $t->t('author') }}</div>
            <div class="text-sm text-ink-faint dark:text-slate-400">{{ $t->t('role') }}</div>
          </div>
        </figcaption>
      </figure>
      @endforeach
    </div>
  </section>
  @endif

  {{-- CTA --}}
  <section class="pb-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="overflow-hidden rounded-3xl p-10 text-center shadow-lift sm:p-16" style="background:linear-gradient(135deg,var(--brand),color-mix(in srgb,var(--brand) 65%,black))">
        <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">{{ $ar ? 'لنبنِ ما هو قادم' : "Let's build what's next" }}</h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-blue-100">{{ $ar ? 'أخبرنا عن مشروعك وسنعاود التواصل خلال يوم عمل واحد.' : "Tell us about your project and we'll get back within one business day." }}</p>
        <a href="{{ route('contact') }}" class="mt-8 inline-flex items-center justify-center gap-2 rounded-xl bg-white px-8 py-3.5 text-base font-semibold shadow-lift transition hover:-translate-y-0.5" style="color:var(--brand)">{{ $ar ? 'تواصل معنا' : 'Contact Sales' }}</a>
      </div>
    </div>
  </section>
@endsection
