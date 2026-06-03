@extends('layouts.public')
@php $ar = app()->getLocale() === 'ar'; @endphp
@section('title', $page->t('title'))
@section('meta', $page->t('meta_description') ?: $page->t('title'))

@section('content')
  <article class="py-16 sm:py-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
      <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">{{ $page->t('title') }}</h1>
      <div class="prose prose-slate mt-8 max-w-none dark:prose-invert">{!! $page->t('body') !!}</div>
    </div>
  </article>
@endsection
