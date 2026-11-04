{{-- The language switch: the current language is highlighted, the other one changes to it. --}}
<form class="langs" method="POST" action="{{ route('language') }}">
    @csrf
    <button type="submit" name="lang" value="en" lang="en" aria-pressed="{{ app()->isLocale('en') ? 'true' : 'false' }}">English</button>
    <button type="submit" name="lang" value="km" lang="km" aria-pressed="{{ app()->isLocale('km') ? 'true' : 'false' }}">ខ្មែរ</button>
</form>
