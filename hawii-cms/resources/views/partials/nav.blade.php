@php
    $ar = $locale === 'ar';
    $links = [
        ['route' => 'services.index', 'en' => 'Solutions', 'ar' => 'الحلول'],
        ['route' => 'about', 'en' => 'About', 'ar' => 'من نحن'],
        ['route' => 'industries.index', 'en' => 'Industries', 'ar' => 'القطاعات'],
        ['route' => 'cases.index', 'en' => 'Case Studies', 'ar' => 'دراسات الحالة'],
        ['route' => 'blog.index', 'en' => 'Blog', 'ar' => 'المدونة'],
    ];
    $langUrl = request()->fullUrlWithQuery(['lang' => $altLocale]);
@endphp
<header class="sticky top-0 z-50">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <nav class="mt-3 flex items-center justify-between rounded-2xl border border-slate-200/70 bg-white/80 px-4 py-2.5 shadow-card backdrop-blur-md dark:border-white/10 dark:bg-slate-900/70">
      <a href="{{ route('home') }}" class="flex items-center gap-2.5 group" aria-label="{{ $siteName }}">
        <span class="grid h-9 w-9 place-items-center rounded-xl text-white shadow-lift transition-transform group-hover:scale-105" style="background:linear-gradient(135deg,var(--brand),color-mix(in srgb,var(--brand) 70%,white))">
          @include('partials.icon', ['name' => 'logo', 'class' => 'h-5 w-5'])
        </span>
        <span class="text-lg font-bold tracking-tight">{{ $siteName }}</span>
      </a>

      <ul class="hidden items-center gap-1 lg:flex">
        @foreach($links as $l)
          <li><a href="{{ route($l['route']) }}" class="rounded-lg px-3 py-2 text-sm font-medium text-ink-soft transition hover:bg-slate-100 hover:text-ink dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white">{{ $ar ? $l['ar'] : $l['en'] }}</a></li>
        @endforeach
      </ul>

      <div class="flex items-center gap-1.5">
        <a href="{{ $langUrl }}" class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-2 text-sm font-semibold text-ink-soft transition hover:border-[var(--brand)] hover:text-[var(--brand)] dark:border-white/10 dark:text-slate-300">
          @include('partials.icon', ['name' => 'globe', 'class' => 'h-4 w-4'])
          <span>{{ $altLabel }}</span>
        </a>
        <button id="themeToggle" type="button" aria-label="Toggle dark mode" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-ink-soft transition hover:border-[var(--brand)] hover:text-[var(--brand)] cursor-pointer dark:border-white/10 dark:text-slate-300">
          <span class="dark:hidden">@include('partials.icon', ['name' => 'sun', 'class' => 'h-4 w-4'])</span>
          <span class="hidden dark:block">@include('partials.icon', ['name' => 'moon', 'class' => 'h-4 w-4'])</span>
        </button>
        <a href="{{ route('contact') }}" class="hidden rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-lift transition hover:-translate-y-0.5 hover:brightness-110 sm:inline-block" style="background:var(--brand)">{{ $ar ? 'تواصل معنا' : 'Contact Sales' }}</a>
        <button id="menuToggle" type="button" aria-label="Open menu" aria-expanded="false" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-ink-soft lg:hidden cursor-pointer dark:border-white/10 dark:text-slate-300">
          @include('partials.icon', ['name' => 'menu', 'class' => 'h-5 w-5'])
        </button>
      </div>
    </nav>

    <div id="mobileMenu" class="hidden lg:hidden">
      <ul class="mt-2 space-y-1 rounded-2xl border border-slate-200/70 bg-white/95 p-3 shadow-card backdrop-blur dark:border-white/10 dark:bg-slate-900/95">
        @foreach($links as $l)
          <li><a href="{{ route($l['route']) }}" class="block rounded-lg px-3 py-2.5 font-medium hover:bg-slate-100 dark:hover:bg-white/5">{{ $ar ? $l['ar'] : $l['en'] }}</a></li>
        @endforeach
        <li><a href="{{ route('contact') }}" class="mt-1 block rounded-lg px-3 py-2.5 text-center font-semibold text-white" style="background:var(--brand)">{{ $ar ? 'تواصل معنا' : 'Contact Sales' }}</a></li>
      </ul>
    </div>
  </div>
</header>
