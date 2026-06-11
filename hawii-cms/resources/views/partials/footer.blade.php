@php
    $ar = $locale === 'ar';
    $flinks = [
        ['route' => 'services.index', 'en' => 'Solutions', 'ar' => 'الحلول'],
        ['route' => 'about', 'en' => 'About', 'ar' => 'من نحن'],
        ['route' => 'industries.index', 'en' => 'Industries', 'ar' => 'القطاعات'],
        ['route' => 'blog.index', 'en' => 'Blog', 'ar' => 'المدونة'],
        ['route' => 'contact', 'en' => 'Contact', 'ar' => 'تواصل'],
    ];
@endphp
<footer class="border-t border-slate-200 bg-white py-12 dark:border-white/10 dark:bg-slate-900/60">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex flex-col items-center justify-between gap-6 sm:flex-row">
      <a href="{{ route('home') }}" class="flex items-center gap-2.5">
        <span class="grid h-8 w-8 place-items-center rounded-lg text-white" style="background:linear-gradient(135deg,var(--brand),color-mix(in srgb,var(--brand) 70%,white))">
          @include('partials.icon', ['name' => 'logo', 'class' => 'h-4 w-4'])
        </span>
        <span class="font-bold">{{ $siteName }}</span>
      </a>
      <nav class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-ink-soft dark:text-slate-400">
        @foreach($flinks as $l)
          <a href="{{ route($l['route']) }}" class="transition hover:text-[var(--brand)]">{{ $ar ? $l['ar'] : $l['en'] }}</a>
        @endforeach
        <a href="{{ route('page.show', 'privacy') }}" class="transition hover:text-[var(--brand)]">{{ $ar ? 'الخصوصية' : 'Privacy' }}</a>
      </nav>
      <div class="flex items-center gap-3">
        @foreach(['linkedin' => $social['linkedin'], 'x-social' => $social['x'], 'github' => $social['github']] as $icon => $url)
          @if($url)
            <a href="{{ $url }}" aria-label="{{ $icon }}" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-200 text-ink-soft transition hover:border-[var(--brand)] hover:text-[var(--brand)] dark:border-white/10 dark:text-slate-400">
              @include('partials.icon', ['name' => $icon, 'class' => 'h-4 w-4'])
            </a>
          @endif
        @endforeach
      </div>
    </div>
    <div class="mt-8 border-t border-slate-100 pt-6 text-center text-sm text-ink-faint dark:border-white/10 dark:text-slate-500">
      {{ $footerNote }}
    </div>
  </div>
</footer>
