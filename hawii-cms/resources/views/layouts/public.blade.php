<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title', $siteName) — @yield('subtitle', 'IT & AI Solutions')</title>
  <meta name="description" content="@yield('meta', 'Enterprise IT & AI solutions: cloud, cybersecurity, custom software and production-grade AI.')" />
  <meta name="theme-color" content="{{ $brandColor }}" />

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('css/app.css') }}" />

  <style>
    :root { --brand: {{ $brandColor }}; --accent: {{ $accentColor }}; }
    [data-reveal] { opacity: 0; transform: translateY(20px); transition: opacity .6s ease-out, transform .6s ease-out; }
    [data-reveal].in { opacity: 1; transform: none; }
    .grid-bg { background-image: linear-gradient(rgba(37,99,235,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(37,99,235,.06) 1px, transparent 1px); background-size: 44px 44px; -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 0%, #000 40%, transparent 100%); mask-image: radial-gradient(ellipse 70% 60% at 50% 0%, #000 40%, transparent 100%); }
    @media (prefers-reduced-motion: reduce) { *,*::before,*::after { animation: none !important; transition: none !important; } [data-reveal]{opacity:1;transform:none;} html{scroll-behavior:auto;} }
  </style>
  <script>
    // Apply theme before paint to avoid flash
    (function(){ var t = localStorage.getItem('hawii-theme'); if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); })();
  </script>
</head>
<body class="font-sans bg-slate-50 text-ink dark:bg-slate-950 dark:text-slate-100 antialiased transition-colors duration-300">

  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:start-3 focus:z-[100] focus:rounded-lg focus:bg-[var(--brand)] focus:px-4 focus:py-2 focus:text-white">
    {{ $locale === 'ar' ? 'تخطّ إلى المحتوى' : 'Skip to content' }}
  </a>

  @include('partials.nav')

  <main id="main">
    @yield('content')
  </main>

  @include('partials.footer')

  <script>
    (function () {
      var root = document.documentElement;
      var themeToggle = document.getElementById('themeToggle');
      if (themeToggle) themeToggle.addEventListener('click', function () {
        root.classList.toggle('dark');
        localStorage.setItem('hawii-theme', root.classList.contains('dark') ? 'dark' : 'light');
      });
      var menuToggle = document.getElementById('menuToggle');
      var mobileMenu = document.getElementById('mobileMenu');
      if (menuToggle && mobileMenu) menuToggle.addEventListener('click', function () {
        var open = mobileMenu.classList.toggle('hidden') === false;
        menuToggle.setAttribute('aria-expanded', String(open));
      });
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
      }, { threshold: 0.12 });
      document.querySelectorAll('[data-reveal]').forEach(function (el) { io.observe(el); });
    })();
  </script>
</body>
</html>
