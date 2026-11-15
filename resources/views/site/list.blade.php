{{--
    The list of a type's public records at its base address (`/posts`), newest first.
    Receives $type (the ContentType) and $records (a simple paginator).
--}}
@extends('layouts.site', [
    'pageTitle' => $type->pluralLabel().' - '.config('app.name'),
])

@section('content')
    <h1 class="text-3xl font-semibold leading-tight tracking-tight text-zinc-900 dark:text-white sm:text-4xl">{{ $type->pluralLabel() }}</h1>

    @include('partials.site.record-cards', ['records' => $records])
@endsection
