@props([
    'form',
    'fields',
])

@php
    $errorBag = ($errors ?? new \Illuminate\Support\ViewErrorBag)->getBag('form-'.$form->slug);
    $isOldInputForThisForm = old('_form') === $form->slug;
    $oldValue = fn (string $key, mixed $default = null): mixed => $isOldInputForThisForm ? old($key, $default) : $default;
@endphp

@if (session('form_sent') === $form->slug)
    <div class="site-form form-sent" id="form-{{ $form->slug }}" role="status">
        <p>{!! nl2br(e($form->success_message)) !!}</p>
    </div>
@else
<form class="site-form form-vertical" method="POST" action="{{ route('forms.submit', $form) }}" id="form-{{ $form->slug }}">
    @csrf
    <input type="hidden" name="_form" value="{{ $form->slug }}">

    {{-- Hidden from people; spam bots fill it in and are turned away --}}
    <div class="form-honeypot" aria-hidden="true">
        <label>Leave this empty <input type="text" name="{{ \App\Models\Form::HONEYPOT_FIELD }}" tabindex="-1" autocomplete="off"></label>
    </div>

    {{-- Spam checks. A form sent back with mistakes keeps its first time, so correcting it quickly still counts --}}
    <input type="hidden" name="{{ \App\Models\Form::SHOWN_AT_FIELD }}" value="{{ $oldValue(\App\Models\Form::SHOWN_AT_FIELD, \App\Models\Form::shownAtToken()) }}">
    <input type="hidden" name="{{ \App\Models\Form::JAVASCRIPT_CHECK_FIELD }}" value="">
    <script>
        document.currentScript.closest('form').addEventListener('submit', function (event) {
            event.currentTarget.elements['{{ \App\Models\Form::JAVASCRIPT_CHECK_FIELD }}'].value = '{{ \App\Models\Form::JAVASCRIPT_CHECK_VALUE }}';
        });
    </script>

    @foreach ($fields as $field)
        @php
            $id = 'form-'.$form->slug.'-'.$field['key'];
            $type = $field['type'];
            $error = $errorBag->first($field['key']) ?: $errorBag->first($field['key'].'.*');
            $describedBy = collect([$field['help'] ? $id.'-help' : null, $error ? $id.'-error' : null])->filter()->implode(' ');
        @endphp

        <div @class(['form-group', 'has-error' => $error])>
            @if ($type->hasChoices() && $type !== \App\FormFieldType::Dropdown)
                <fieldset @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
                    <legend>
                        {{ $field['label'] }}
                        @if ($field['required']) <span class="form-required" aria-hidden="true">*</span> @endif
                    </legend>
                    @foreach ($field['choices'] as $choice)
                        <label class="form-choice">
                            @if ($type === \App\FormFieldType::Checkboxes)
                                <input type="checkbox" name="{{ $field['key'] }}[]" value="{{ $choice }}" @checked(in_array($choice, (array) $oldValue($field['key'], []), true))>
                            @else
                                <input type="radio" name="{{ $field['key'] }}" value="{{ $choice }}" @checked($oldValue($field['key']) === $choice) @required($field['required'])>
                            @endif
                            {{ $choice }}
                        </label>
                    @endforeach
                </fieldset>
            @else
                <label for="{{ $id }}">
                    {{ $field['label'] }}
                    @if ($field['required']) <span class="form-required" aria-hidden="true">*</span> @endif
                </label>

                @if ($type === \App\FormFieldType::Textarea)
                    <textarea id="{{ $id }}" name="{{ $field['key'] }}" rows="6" @required($field['required']) @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif>{{ $oldValue($field['key']) }}</textarea>
                @elseif ($type === \App\FormFieldType::Dropdown)
                    <select id="{{ $id }}" name="{{ $field['key'] }}" @required($field['required']) @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif>
                        <option value="">Choose…</option>
                        @foreach ($field['choices'] as $choice)
                            <option value="{{ $choice }}" @selected($oldValue($field['key']) === $choice)>{{ $choice }}</option>
                        @endforeach
                    </select>
                @else
                    <input
                        type="{{ $type->inputType() }}"
                        id="{{ $id }}"
                        name="{{ $field['key'] }}"
                        value="{{ $oldValue($field['key']) }}"
                        @if ($type === \App\FormFieldType::Email) autocomplete="email" @endif
                        @if ($type === \App\FormFieldType::Phone) autocomplete="tel" @endif
                        @if ($type === \App\FormFieldType::Number) step="any" @endif
                        @required($field['required'])
                        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                        @if ($error) aria-invalid="true" @endif
                    >
                @endif
            @endif

            @if ($field['help'])
                <p class="form-help" id="{{ $id }}-help">{{ $field['help'] }}</p>
            @endif
            @if ($error)
                <p class="form-error" id="{{ $id }}-error">{{ $error }}</p>
            @endif
        </div>
    @endforeach

    @if ($errorBag->has('form'))
        <p class="form-error" role="alert">{{ $errorBag->first('form') }}</p>
    @endif

    <noscript><p class="form-help">This form needs JavaScript to send. Please turn it on in your browser.</p></noscript>

    <div>
        <button type="submit" class="btn btn-primary">{{ $form->submit_label }}</button>
    </div>
</form>
@endif
