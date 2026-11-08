<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.head', ['title' => $title ?? null])
</head>
<body>
<header class="site">
    <div class="krama"></div>
    <nav class="topbar wrap" aria-label="{{ __('Account') }}">
        <a class="brand" href="{{ route('dub.index') }}" aria-label="Khmer Dub"><x-logo /> <span class="brand-name">Khmer Dub</span></a>
        <div class="account">
            <x-language />
            <a class="person" href="{{ route('profile.edit') }}" title="{{ Auth::user()->name }}" aria-label="{{ __('Account') }}">
                <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="quiet" type="submit">{{ __('Log out') }}</button>
            </form>
        </div>
    </nav>
</header>
<main>
    {{ $slot }}
</main>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script>
/** toast(text, kind): a short notice at the foot of the page that goes by itself. kind is 'ok' or 'bad'. */
window.toast = (() => {
    const box = document.getElementById('toasts');
    const CLOSE = @json(__('Close'));
    const hide = (item) => {
        if (item.classList.contains('closing')) return;
        item.classList.add('closing');
        setTimeout(() => item.remove(), 200);
    };
    const toast = (text, kind = 'ok') => {
        const item = Object.assign(document.createElement('p'), { className: `toast ${kind}` });
        if (kind === 'bad') item.setAttribute('role', 'alert');
        const close = Object.assign(document.createElement('button'), { type: 'button', className: 'toast-close', textContent: '×' });
        close.setAttribute('aria-label', CLOSE);
        close.addEventListener('click', () => hide(item));
        item.append(Object.assign(document.createElement('span'), { textContent: text }), close);
        box.append(item);
        while (box.children.length > 3) box.firstElementChild.remove();
        setTimeout(() => hide(item), kind === 'bad' ? 10000 : 6000);
    };
    const notice = @json(session('notice'));
    if (notice) toast(notice);
    return toast;
})();
</script>
{{ $scripts ?? '' }}
</body>
</html>
