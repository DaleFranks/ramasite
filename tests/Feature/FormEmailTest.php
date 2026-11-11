<?php

namespace Tests\Feature;

use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Mail\FormSubmissionReceived;
use App\Models\Entry;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class FormEmailTest extends TestCase
{
    use RefreshDatabase;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->form = Form::factory()->create([
            'name' => 'Contact',
            'slug' => 'contact',
            'notify_emails' => ['owner@example.com', 'not an address', 'helper@example.com'],
            'fields' => [
                ['label' => 'Name', 'type' => 'text', 'required' => true],
                ['label' => 'Email', 'type' => 'email', 'required' => true],
                ['label' => 'Topics', 'type' => 'checkboxes', 'choices' => ['Sales', 'Support']],
                ['label' => 'Message', 'type' => 'textarea'],
            ],
        ]);
        Entry::factory()->published()->create(['slug' => 'contact-us', 'body' => '[form:contact]']);
    }

    private function send(): void
    {
        $this->from('/contact-us')->post('/forms/contact', $this->sentByVisitor([
            'name' => 'Ann & Co',
            'email' => 'ann@example.com',
            'topics' => ['Sales', 'Support'],
            'message' => "Line one\nLine two <b>bold</b>",
        ]));
    }

    public function test_each_submission_is_emailed_to_the_forms_addresses_with_replies_going_to_the_visitor(): void
    {
        Mail::fake();

        $this->send();

        Mail::assertSent(FormSubmissionReceived::class, function (FormSubmissionReceived $mail): bool {
            return $mail->hasTo('owner@example.com')
                && $mail->hasTo('helper@example.com')
                && ! $mail->hasTo('not an address')
                && $mail->hasReplyTo('ann@example.com')
                && $mail->hasSubject('New submission: Contact');
        });
        $this->assertSame('sent', FormSubmission::query()->sole()->email_status);
    }

    public function test_the_email_lists_every_answer_as_typed(): void
    {
        $this->send();

        $text = (new FormSubmissionReceived(FormSubmission::query()->sole()))->render();

        $this->assertStringContainsString("Name:\nAnn & Co", $text);
        $this->assertStringContainsString("Topics:\nSales, Support", $text);
        $this->assertStringContainsString("Message:\nLine one\nLine two <b>bold</b>", $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    public function test_nothing_is_emailed_when_the_form_has_no_addresses(): void
    {
        Mail::fake();
        $this->form->update(['notify_emails' => []]);

        $this->send();

        Mail::assertNothingSent();
        $this->assertNull(FormSubmission::query()->sole()->email_status);
    }

    public function test_a_failed_email_still_keeps_the_submission_and_thanks_the_visitor(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Mail server unreachable'));

        $this->send();

        $submission = FormSubmission::query()->sole();
        $this->assertSame('failed', $submission->email_status);
        $this->get('/contact-us')->assertSee('form-sent', false);
    }

    public function test_addresses_on_the_form_must_be_valid(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeRepeaters();

        Livewire::test(CreateForm::class)
            ->fillForm([
                'name' => 'Survey',
                'notify_emails' => ['owner@example.com', 'nope'],
                'fields' => [['label' => 'Name', 'type' => 'text']],
            ])
            ->call('create')
            ->assertHasFormErrors(['notify_emails.1']);

    }
}
