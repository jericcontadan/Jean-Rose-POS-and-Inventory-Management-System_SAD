{{-- Temporary page so the header tabs work. Each later build step replaces its route with the real screen. --}}
@extends('layouts.app')

@section('title', $title)

@section('content')
    <h1 class="jr-page-title mb-4">{{ $title }}</h1>

    <div class="card p-4">
        <p class="mb-0">This screen is built in Step {{ $step }} of the build plan.</p>
    </div>
@endsection
