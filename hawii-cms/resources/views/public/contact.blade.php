@extends('layouts.public')
@php
    $ar = app()->getLocale() === 'ar';
    $contactEmail = \App\Models\Setting::get('contact_email');
    $contactPhone = \App\Models\Setting::get('contact_phone');
@endphp
@section('title', $ar ? 'تواصل معنا' : 'Contact')

@section('content')
  <section class="py-16 sm:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="overflow-hidden rounded-3xl border border-slate-200 shadow-lift dark:border-white/10">
        <div class="grid gap-10 p-8 sm:p-12 lg:grid-cols-2" style="background:linear-gradient(135deg,var(--brand),color-mix(in srgb,var(--brand) 65%,black))">
          <div class="text-white">
            <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ $ar ? 'لنبنِ ما هو قادم' : "Let's build what's next" }}</h1>
            <p class="mt-4 max-w-md text-lg text-blue-100">{{ $ar ? 'أخبرنا عن مشروعك وسنعاود التواصل خلال يوم عمل واحد بخطة مخصّصة.' : "Tell us about your project and we'll get back within one business day with a tailored plan." }}</p>
            <ul class="mt-8 space-y-4 text-blue-50">
              @if($contactEmail)<li class="flex items-center gap-3">@include('partials.icon', ['name' => 'mail', 'class' => 'h-5 w-5 shrink-0'])<span dir="ltr">{{ $contactEmail }}</span></li>@endif
              @if($contactPhone)<li class="flex items-center gap-3">@include('partials.icon', ['name' => 'phone', 'class' => 'h-5 w-5 shrink-0'])<span dir="ltr">{{ $contactPhone }}</span></li>@endif
              <li class="flex items-center gap-3">@include('partials.icon', ['name' => 'pin', 'class' => 'h-5 w-5 shrink-0'])<span>{{ \App\Models\Setting::trans('contact_location', null, '') }}</span></li>
            </ul>
          </div>

          <div class="rounded-2xl bg-white p-6 shadow-card dark:bg-slate-900 sm:p-8">
            @if(session('sent'))
              <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                {{ $ar ? 'شكراً! استلمنا رسالتك وسنعاود التواصل قريباً.' : "Thanks! We've received your message and will be in touch soon." }}
              </div>
            @endif
            @if($errors->any())
              <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">
                {{ $ar ? 'يرجى تصحيح الحقول المميّزة.' : 'Please correct the highlighted fields.' }}
              </div>
            @endif
            <form method="POST" action="{{ route('contact.store') }}" class="space-y-4">
              @csrf
              <div>
                <label for="name" class="mb-1.5 block text-sm font-medium">{{ $ar ? 'الاسم الكامل' : 'Full name' }}</label>
                <input id="name" name="name" type="text" required value="{{ old('name') }}" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-ink outline-none transition focus:border-[var(--brand)] focus:ring-2 dark:border-white/15 dark:bg-slate-800 dark:text-white" style="--tw-ring-color:color-mix(in srgb,var(--brand) 30%,transparent)" />
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
              </div>
              <div>
                <label for="email" class="mb-1.5 block text-sm font-medium">{{ $ar ? 'البريد الإلكتروني' : 'Work email' }}</label>
                <input id="email" name="email" type="email" required dir="ltr" value="{{ old('email') }}" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-ink outline-none transition focus:border-[var(--brand)] focus:ring-2 dark:border-white/15 dark:bg-slate-800 dark:text-white" />
                @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
              </div>
              <div>
                <label for="interest" class="mb-1.5 block text-sm font-medium">{{ $ar ? 'أنا مهتم بـ' : "I'm interested in" }}</label>
                <select id="interest" name="interest" class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-ink outline-none transition focus:border-[var(--brand)] focus:ring-2 dark:border-white/15 dark:bg-slate-800 dark:text-white">
                  <option value="IT Solutions">{{ $ar ? 'حلول تقنية المعلومات' : 'IT Solutions' }}</option>
                  <option value="AI Solutions">{{ $ar ? 'حلول الذكاء الاصطناعي' : 'AI Solutions' }}</option>
                  <option value="Both">{{ $ar ? 'كلاهما' : 'Both' }}</option>
                </select>
              </div>
              <div>
                <label for="message" class="mb-1.5 block text-sm font-medium">{{ $ar ? 'تفاصيل المشروع' : 'Project details' }}</label>
                <textarea id="message" name="message" rows="3" class="w-full resize-none rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-ink outline-none transition focus:border-[var(--brand)] focus:ring-2 dark:border-white/15 dark:bg-slate-800 dark:text-white">{{ old('message') }}</textarea>
              </div>
              <button type="submit" class="w-full rounded-xl px-6 py-3 font-semibold text-white shadow-lift transition hover:-translate-y-0.5 hover:brightness-110" style="background:var(--accent)">{{ $ar ? 'إرسال الطلب' : 'Send request' }}</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection
