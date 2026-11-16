<x-app-layout>
    <x-slot name="title">{{ __('Glossary') }} · Khmer Dub</x-slot>
    <div class="wrap page">
        <h1 class="page-title">{{ __('Glossary') }}</h1>
        @if (session('notice'))
            <p class="notice">{{ session('notice') }}</p>
        @endif

        <section class="panel">
            <h2>{{ __('Names and terms') }}</h2>
            <p class="hint">{{ __('One term a line. Write term = Khmer to always translate it that way, or the term alone to keep it as written, like a brand name. Upper and lower case do not matter.') }}</p>
            <form method="POST" action="{{ route('glossary.update') }}">
                @csrf
                @method('put')
                <label for="glossary" class="unseen">{{ __('Glossary') }}</label>
                <textarea id="glossary" name="glossary" rows="12" spellcheck="false"
                          placeholder="Phnom Penh = ភ្នំពេញ&#10;Sokha = សុខា&#10;NASA">{{ old('glossary', $text) }}</textarea>
                @error('glossary')<p class="field-error">{{ $message }}</p>@enderror
                <button type="submit">{{ __('Save') }}</button>
            </form>
        </section>

        <section class="panel">
            <h2>{{ __('Your edits') }}</h2>
            <p>{{ trans_choice('{0} No edits are remembered yet.|{1} 1 line you corrected is remembered.|[2,*] :count lines you corrected are remembered.', $remembered, ['count' => $remembered]) }}</p>
            <p class="hint">{{ __('When you correct the Khmer of a line on the review page, the same line in a later video gets your Khmer instead of a new translation.') }}</p>
            @if ($remembered)
                <form method="POST" action="{{ route('glossary.forget') }}">
                    @csrf
                    @method('delete')
                    <button class="danger" type="submit">{{ __('Forget my edits') }}</button>
                </form>
            @endif
        </section>
    </div>
</x-app-layout>
