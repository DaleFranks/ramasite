<?php

namespace App\Models;

use App\FormFieldType;
use App\Models\Concerns\PlacedByShortcode;
use Database\Factories\FormFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A form built on the Forms screen and placed in a page with [form:slug].
 *
 * Its fields are stored in order, each as:
 * {label, key, type, required, help, choices}. The key names the field's
 * answer in submissions. It's made from the label when the field is first
 * saved and then kept, so renaming a field doesn't disconnect earlier answers.
 */
#[Fillable([
    'name',
    'slug',
    'fields',
    'submit_label',
    'success_message',
    'notify_emails',
])]
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory;

    use PlacedByShortcode;

    /**
     * The hidden field that only spam bots fill in.
     */
    public const HONEYPOT_FIELD = 'website_url';

    /**
     * The hidden field holding the signed time the form was shown.
     */
    public const SHOWN_AT_FIELD = '_shown_at';

    /**
     * The hidden field that the form's script fills in just before sending.
     */
    public const JAVASCRIPT_CHECK_FIELD = '_javascript_check';

    public const JAVASCRIPT_CHECK_VALUE = 'sent-with-javascript';

    /**
     * Anything sent sooner than this after the form was shown is taken for a spam robot.
     */
    public const MINIMUM_SECONDS_BEFORE_SENDING = 3;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fields' => '[]',
        'submit_label' => 'Send',
        'success_message' => 'Thank you! Your message has been sent.',
    ];

    protected static function booted(): void
    {
        static::saving(function (Form $form): void {
            $form->fields = self::withKeys($form->fields ?? []);
        });
    }

    /**
     * @return list<string>
     */
    protected static function placingShortcodes(): array
    {
        return ['form'];
    }

    /**
     * A form can be placed in modules and cards as well as pages and posts.
     */
    protected static function isPlacedInModulesAndCards(): bool
    {
        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'notify_emails' => 'array',
        ];
    }

    /**
     * Give every field without a key one made from its label, unique within the form.
     *
     * @param  array<int|string, array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function withKeys(array $fields): array
    {
        $fields = array_values($fields);
        $taken = [self::HONEYPOT_FIELD, ...array_filter(array_column($fields, 'key'))];

        foreach ($fields as $index => $field) {
            if (filled($field['key'] ?? null)) {
                continue;
            }

            $baseKey = Str::slug((string) ($field['label'] ?? ''), '_') ?: 'field';
            $key = $baseKey;

            for ($number = 2; in_array($key, $taken, true); $number++) {
                $key = "{$baseKey}_{$number}";
            }

            $fields[$index]['key'] = $key;
            $taken[] = $key;
        }

        return $fields;
    }

    /**
     * The fields, in order, with their type as a FormFieldType and their choices as a list.
     *
     * @return Collection<int, array{label: string, key: string, type: FormFieldType, required: bool, help: ?string, choices: list<string>}>
     */
    public function formFields(): Collection
    {
        return collect($this->fields)
            ->map(fn (array $field): array => [
                'label' => (string) ($field['label'] ?? ''),
                'key' => (string) ($field['key'] ?? ''),
                'type' => FormFieldType::tryFrom((string) ($field['type'] ?? '')) ?? FormFieldType::Text,
                'required' => (bool) ($field['required'] ?? false),
                'help' => filled($field['help'] ?? null) ? (string) $field['help'] : null,
                'choices' => array_values(array_filter((array) ($field['choices'] ?? []), 'filled')),
            ])
            ->filter(fn (array $field): bool => $field['key'] !== '')
            ->values();
    }

    /**
     * The valid addresses that submissions are emailed to.
     *
     * @return list<string>
     */
    public function notifyAddresses(): array
    {
        return collect($this->notify_emails ?? [])
            ->map(fn (mixed $address): string => trim((string) $address))
            ->filter(fn (string $address): bool => (bool) filter_var($address, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What visitors have sent with the form, newest first.
     *
     * @return HasMany<FormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class)->latest();
    }

    /**
     * The validation rules for a submission, built from the fields' settings.
     *
     * @return array<string, list<mixed>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->formFields() as $field) {
            $presence = $field['required'] ? 'required' : 'nullable';

            $rules[$field['key']] = match ($field['type']) {
                FormFieldType::Text, FormFieldType::Phone => [$presence, 'string', 'max:255'],
                FormFieldType::Email => [$presence, 'string', 'email', 'max:255'],
                FormFieldType::Textarea => [$presence, 'string', 'max:10000'],
                FormFieldType::Number => [$presence, 'numeric'],
                FormFieldType::Date => [$presence, 'date'],
                FormFieldType::Dropdown, FormFieldType::Radio => [$presence, 'string', Rule::in($field['choices'])],
                FormFieldType::Checkboxes => [$presence, 'array'],
            };

            if ($field['type'] === FormFieldType::Checkboxes) {
                $rules[$field['key'].'.*'] = ['string', Rule::in($field['choices'])];
            }
        }

        return $rules;
    }

    /**
     * The fields' labels, so error messages name fields the way the visitor sees them.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return $this->formFields()
            ->flatMap(fn (array $field): array => [
                $field['key'] => $field['label'],
                $field['key'].'.*' => $field['label'],
            ])
            ->all();
    }

    /**
     * Save a visitor's validated answers, each with its field's current label.
     *
     * @param  array<string, mixed>  $values
     */
    public function recordSubmission(array $values): FormSubmission
    {
        $answers = $this->formFields()
            ->mapWithKeys(fn (array $field): array => [
                $field['key'] => ['label' => $field['label'], 'value' => $values[$field['key']] ?? null],
            ])
            ->all();

        return $this->submissions()->create(['answers' => $answers]);
    }

    /**
     * Every submission as a CSV file, oldest first: a column for the date, one
     * for each current field, and one for any field since removed from the form.
     */
    public function downloadSubmissionsCsv(): StreamedResponse
    {
        $submissions = $this->submissions()->reorder()->oldest()->get();
        $columns = $this->formFields()->pluck('label', 'key');

        foreach ($submissions as $submission) {
            foreach ($submission->answers as $key => $answer) {
                $columns->put($key, $columns->get($key, $answer['label'] ?? $key));
            }
        }

        return response()->streamDownload(function () use ($columns, $submissions): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, ['Sent', ...$columns->values()->all()], escape: '');

            foreach ($submissions as $submission) {
                fputcsv($output, [
                    Theme::localTime($submission->created_at)->toDateTimeString(),
                    ...$columns->keys()->map(fn (string $key): string => self::safeForSpreadsheets($submission->answerText($key)))->all(),
                ], escape: '');
            }

            fclose($output);
        }, Str::slug($this->name).'-submissions-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Stop a visitor's answer being run as a formula when the file is opened in a spreadsheet.
     */
    private static function safeForSpreadsheets(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    public function shortcode(): string
    {
        return '[form:'.$this->slug.']';
    }

    /**
     * The current time, signed so that a visitor can't alter it.
     */
    public static function shownAtToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Whether a token from shownAtToken() is genuine and old enough to have been
     * filled in by a person.
     */
    public static function wasShownLongEnoughAgo(mixed $token): bool
    {
        if (! is_string($token)) {
            return false;
        }

        try {
            $shownAt = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return false;
        }

        return now()->getTimestamp() - $shownAt >= self::MINIMUM_SECONDS_BEFORE_SENDING;
    }

    /**
     * The form's HTML, or nothing if it has no fields yet.
     */
    public function html(): ?string
    {
        $fields = $this->formFields();

        if ($fields->isEmpty()) {
            return null;
        }

        return view('components.site.form', ['form' => $this, 'fields' => $fields])->render();
    }
}
