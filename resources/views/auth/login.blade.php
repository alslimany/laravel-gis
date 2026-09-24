@extends('layouts.app')

@section('content')
<form method="POST" action="{{ route('login') }}" class="sign-in">
    @csrf

    <h2>Sign in</h2>

    <div class="sign-field">
        <label for="email">{{ __('Email') }}</label>
        <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
        @error('email')
            <span class="invalid-feedback" role="alert">{{ $message }}</span>
        @enderror
    </div>

    <div class="sign-field">
        <label for="password">{{ __('Password') }}</label>
        <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">
        @error('password')
            <span class="invalid-feedback" role="alert">{{ $message }}</span>
        @enderror
    </div>

    <label class="sign-remember" for="remember">
        <input type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
        {{ __('Remember me') }}
    </label>

    <div class="sign-actions">
        <button type="submit" class="btn btn-primary">{{ __('Sign in') }}</button>
        @if (Route::has('password.request'))
            <a class="sign-forgot" href="{{ route('password.request') }}">{{ __('Forgot password') }}</a>
        @endif
    </div>
</form>
@endsection
