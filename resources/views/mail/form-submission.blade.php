{{-- Plain text, so answers are output as typed rather than HTML-escaped --}}
Someone sent the "{!! $submission->form->name !!}" form on {!! \App\Models\Theme::localTime($submission->created_at)->format('F j, Y \a\t g:i a') !!}.

@foreach ($submission->answers as $key => $answer)
{!! $answer['label'] ?? $key !!}:
{!! $submission->answerText($key) !== '' ? $submission->answerText($key) : '(no answer)' !!}

@endforeach
You can also see this submission in the admin panel, under Forms.
