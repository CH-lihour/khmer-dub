@props(['selected'])
<fieldset class="field">
    <legend class="unseen">{{ __('Voice') }}</legend>
    <div class="voices">
        <label><input type="radio" name="gender" value="female" @checked($selected === 'female')>
            <span><b>{{ __('Sreymom') }}</b> <small>{{ __('Female') }}</small></span></label>
        <label><input type="radio" name="gender" value="male" @checked($selected === 'male')>
            <span><b>{{ __('Piseth') }}</b> <small>{{ __('Male') }}</small></span></label>
    </div>
</fieldset>
