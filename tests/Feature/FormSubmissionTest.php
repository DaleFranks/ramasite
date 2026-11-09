<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->form = Form::factory()->create([
            'slug' => 'contact',
            'success_message' => "Thanks!\nWe'll reply soon.",
            'fields' => [
                ['label' => 'Your name', 'type' => 'text', 'required' => true],
                ['label' => 'Email', 'type' => 'email', 'required' => true],
                ['label' => 'Guests', 'type' => 'number'],
                ['label' => 'Room', 'type' => 'dropdown', 'choices' => ['Single', 'Double']],
                ['label' => 'Extras', 'type' => 'checkboxes', 'choices' => ['Breakfast', 'Parking']],
            ],
        ]);
        Entry::factory()->published()->create(['slug' => 'contact-us', 'body' => '[form:contact]']);
    }

    public function test_a_valid_submission_is_saved_and_the_thank_you_message_replaces_the_form(): void
    {
        $this->from('/contact-us')
            ->post('/forms/contact', $this->sentByVisitor([
                'your_name' => 'Ann Smith',
                'email' => 'ann@example.com',
                'guests' => '2',
                'room' => 'Double',
                'extras' => ['Breakfast', 'Parking'],
            ]))
            ->assertRedirect(url('/contact-us').'#form-contact');

        $submission = FormSubmission::query()->sole();
        $this->assertTrue($submission->form->is($this->form));
        $this->assertSame([
            'your_name' => ['label' => 'Your name', 'value' => 'Ann Smith'],
            'email' => ['label' => 'Email', 'value' => 'ann@example.com'],
            'guests' => ['label' => 'Guests', 'value' => '2'],
            'room' => ['label' => 'Room', 'value' => 'Double'],
            'extras' => ['label' => 'Extras', 'value' => ['Breakfast', 'Parking']],
        ], $submission->answers);

        $this->get('/contact-us')
            ->assertSee('<div class="site-form form-sent" id="form-contact" role="status">', false)
            ->assertSee("Thanks!<br />\nWe&#039;ll reply soon.", false)
            ->assertDontSee('<form class="site-form', false);
    }

    public function test_mistakes_are_shown_under_their_fields_with_the_answers_kept(): void
    {
        $this->from('/contact-us')
            ->post('/forms/contact', $this->sentByVisitor(['_form' => 'contact', 'your_name' => '', 'email' => 'not-an-email', 'guests' => 'lots', 'room' => 'Penthouse', 'extras' => ['Spa']]))
            ->assertRedirect(url('/contact-us').'#form-contact');

        $this->assertDatabaseCount('form_submissions', 0);

        // Checked on the page rather than with assertSessionHasErrors(), which (with
        // JSON sessions) stops the errors reaching the next request in a test.
        $this->get('/contact-us')
            ->assertSee('The Your name field is required.')
            ->assertSee('The Email field must be a valid email address.')
            ->assertSee('The Guests field must be a number.')
            ->assertSee('The selected Room is invalid.')
            ->assertSee('The selected Extras is invalid.')
            ->assertSee('value="not-an-email"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_another_form_on_the_page_keeps_its_own_answers_and_errors_to_itself(): void
    {
        Form::factory()->create(['slug' => 'newsletter', 'fields' => [['label' => 'Email', 'type' => 'email', 'required' => true]]]);
        Entry::factory()->published()->create(['slug' => 'both', 'body' => "[form:contact]\n\n[form:newsletter]"]);

        $this->from('/both')->post('/forms/contact', $this->sentByVisitor(['_form' => 'contact', 'email' => 'typo']));

        $html = $this->get('/both')->getContent();
        $newsletter = substr($html, strpos($html, 'id="form-newsletter"'));

        $this->assertStringNotContainsString('value="typo"', $newsletter);
        $this->assertStringNotContainsString('form-error', $newsletter);
    }

    public function test_optional_fields_left_empty_are_saved_as_empty(): void
    {
        $this->from('/contact-us')->post('/forms/contact', $this->sentByVisitor(['your_name' => 'Ann', 'email' => 'ann@example.com']));

        $answers = FormSubmission::query()->sole()->answers;

        $this->assertNull($answers['guests']['value']);
        $this->assertNull($answers['extras']['value']);
    }

    public function test_bots_that_fill_in_the_hidden_field_are_thanked_but_nothing_is_saved(): void
    {
        $this->from('/contact-us')
            ->post('/forms/contact', ['your_name' => 'Bot', 'email' => 'bot@example.com', Form::HONEYPOT_FIELD => 'https://spam.example'])
            ->assertSessionHas('form_sent', 'contact');

        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_a_form_sent_too_soon_after_it_was_shown_is_thanked_but_nothing_is_saved(): void
    {
        $this->from('/contact-us')
            ->post('/forms/contact', $this->sentByVisitor(['your_name' => 'Bot', 'email' => 'bot@example.com', Form::SHOWN_AT_FIELD => Form::shownAtToken()]))
            ->assertSessionHas('form_sent', 'contact');

        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_a_form_sent_after_the_minimum_wait_is_saved(): void
    {
        $shownAt = $this->travel(-Form::MINIMUM_SECONDS_BEFORE_SENDING)->seconds(fn (): string => Form::shownAtToken());

        $this->from('/contact-us')
            ->post('/forms/contact', $this->sentByVisitor(['your_name' => 'Ann', 'email' => 'ann@example.com', Form::SHOWN_AT_FIELD => $shownAt]));

        $this->assertDatabaseCount('form_submissions', 1);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function failedSpamChecks(): array
    {
        return [
            'no time' => [Form::SHOWN_AT_FIELD, null],
            'made-up time' => [Form::SHOWN_AT_FIELD, '1000000000'],
            'time sent as a list' => [Form::SHOWN_AT_FIELD, ['1000000000']],
            'script did not run' => [Form::JAVASCRIPT_CHECK_FIELD, ''],
            'no script value' => [Form::JAVASCRIPT_CHECK_FIELD, null],
            'wrong script value' => [Form::JAVASCRIPT_CHECK_FIELD, 'something-else'],
        ];
    }

    #[DataProvider('failedSpamChecks')]
    public function test_a_form_failing_a_spam_check_is_thanked_but_nothing_is_saved(string $field, mixed $value): void
    {
        $this->from('/contact-us')
            ->post('/forms/contact', array_filter(
                [...$this->sentByVisitor(['your_name' => 'Bot', 'email' => 'bot@example.com']), $field => $value],
                fn (mixed $sent): bool => $sent !== null,
            ))
            ->assertSessionHas('form_sent', 'contact');

        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_a_form_sent_back_with_mistakes_keeps_the_time_it_was_first_shown(): void
    {
        $answers = $this->sentByVisitor(['_form' => 'contact', 'your_name' => '', 'email' => 'typo']);

        $this->from('/contact-us')->post('/forms/contact', $answers);

        $this->get('/contact-us')->assertSee('value="'.$answers[Form::SHOWN_AT_FIELD].'"', false);
    }

    public function test_answers_to_fields_the_form_does_not_have_are_ignored(): void
    {
        $this->from('/contact-us')->post('/forms/contact', $this->sentByVisitor(['your_name' => 'Ann', 'email' => 'ann@example.com', 'is_admin' => '1']));

        $this->assertArrayNotHasKey('is_admin', FormSubmission::query()->sole()->answers);
    }

    public function test_a_visitor_sending_too_often_is_asked_to_wait(): void
    {
        RateLimiter::clear('form-submissions');

        foreach (range(1, 5) as $attempt) {
            $this->from('/contact-us')->post('/forms/contact', $this->sentByVisitor(['your_name' => 'Ann', 'email' => 'ann@example.com']));
        }

        $this->from('/contact-us')
            ->post('/forms/contact', $this->sentByVisitor(['your_name' => 'Ann', 'email' => 'ann@example.com']))
            ->assertRedirect(url('/contact-us').'#form-contact');

        $this->assertDatabaseCount('form_submissions', 5);
        $this->get('/contact-us')->assertSee('Please wait a minute and try again.');
    }

    public function test_a_form_sent_after_the_session_expired_goes_back_with_the_answers_kept(): void
    {
        $this->checkSecurityTokens();

        $this->from('/contact-us')
            ->post('/forms/contact', ['_form' => 'contact', '_token' => 'expired', 'your_name' => 'Ann', 'email' => 'ann@example.com'])
            ->assertRedirect(url('/contact-us').'#form-contact');

        $this->assertDatabaseCount('form_submissions', 0);
        $this->get('/contact-us')
            ->assertSee('so the form wasn&#039;t sent. Please check your answers and send it again.', false)
            ->assertSee('value="ann@example.com"', false);
    }

    public function test_other_pages_with_an_expired_session_still_say_so(): void
    {
        $this->checkSecurityTokens();

        $this->post('/setup', ['_token' => 'expired'])->assertStatus(419);
    }

    public function test_an_unknown_form_is_not_found(): void
    {
        $this->post('/forms/nope', ['email' => 'ann@example.com'])->assertNotFound();
    }

    /**
     * Check the security token on forms as a real site does; tests normally skip it.
     */
    private function checkSecurityTokens(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }
}
