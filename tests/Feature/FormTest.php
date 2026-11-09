<?php

namespace Tests\Feature;

use App\FormFieldType;
use App\Markdown;
use App\Models\Entry;
use App\Models\Form;
use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    public function test_fields_get_keys_from_their_labels_that_stay_put_when_relabelled(): void
    {
        $form = Form::factory()->create(['fields' => [
            ['label' => 'Your Name', 'type' => 'text'],
            ['label' => 'Email', 'type' => 'email'],
            ['label' => 'Email', 'type' => 'email'],
            ['label' => 'Website URL', 'type' => 'text'],
            ['label' => '???', 'type' => 'text'],
        ]]);

        $this->assertSame(['your_name', 'email', 'email_2', 'website_url_2', 'field'], array_column($form->fields, 'key'));

        $fields = $form->fields;
        $fields[0]['label'] = 'Full name';
        $form->update(['fields' => $fields]);

        $this->assertSame('your_name', $form->refresh()->fields[0]['key']);
    }

    public function test_every_field_type_is_shown_with_its_label_and_settings(): void
    {
        $form = Form::factory()->create([
            'slug' => 'everything',
            'submit_label' => 'Send it',
            'fields' => [
                ['label' => 'Name', 'type' => 'text', 'required' => true, 'help' => 'As on your passport'],
                ['label' => 'Email', 'type' => 'email'],
                ['label' => 'Phone', 'type' => 'phone'],
                ['label' => 'Message', 'type' => 'textarea', 'required' => true],
                ['label' => 'Guests', 'type' => 'number'],
                ['label' => 'Arrival', 'type' => 'date'],
                ['label' => 'Room', 'type' => 'dropdown', 'choices' => ['Single', 'Double']],
                ['label' => 'Extras', 'type' => 'checkboxes', 'choices' => ['Breakfast', 'Parking']],
                ['label' => 'Contact me by', 'type' => 'radio', 'choices' => ['Email', 'Phone'], 'required' => true],
            ],
        ]);

        $html = $form->html();

        $this->assertStringContainsString('<form class="site-form form-vertical" method="POST" action="'.route('forms.submit', 'everything').'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="'.Form::HONEYPOT_FIELD.'"', $html);
        $this->assertStringContainsString('<input type="hidden" name="'.Form::SHOWN_AT_FIELD.'" value="ey', $html);
        $this->assertStringContainsString('<input type="hidden" name="'.Form::JAVASCRIPT_CHECK_FIELD.'" value="">', $html);
        $this->assertStringContainsString("elements['".Form::JAVASCRIPT_CHECK_FIELD."'].value = '".Form::JAVASCRIPT_CHECK_VALUE."'", $html);
        $this->assertStringContainsString('<noscript><p class="form-help">This form needs JavaScript to send.', $html);
        $this->assertMatchesRegularExpression('#<input\s+type="text"\s+id="form-everything-name"\s+name="name"[^>]*required[^>]*aria-describedby="form-everything-name-help"#', $html);
        $this->assertStringContainsString('<p class="form-help" id="form-everything-name-help">As on your passport</p>', $html);
        $this->assertMatchesRegularExpression('#type="email"\s+id="form-everything-email"#', $html);
        $this->assertMatchesRegularExpression('#type="tel"\s+id="form-everything-phone"#', $html);
        $this->assertStringContainsString('<textarea id="form-everything-message" name="message" rows="6" required', $html);
        $this->assertMatchesRegularExpression('#type="number"\s+id="form-everything-guests"#', $html);
        $this->assertMatchesRegularExpression('#type="date"\s+id="form-everything-arrival"#', $html);
        $this->assertMatchesRegularExpression('#<option value="Double"\s*>Double</option>#', $html);
        $this->assertStringContainsString('<input type="checkbox" name="extras[]" value="Parking"', $html);
        $this->assertMatchesRegularExpression('#<input type="radio" name="contact_me_by" value="Phone"\s+required>#', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">Send it</button>', $html);
    }

    public function test_a_form_is_shown_where_its_shortcode_is_written_including_in_modules(): void
    {
        Form::factory()->create(['slug' => 'contact']);
        Form::factory()->create(['slug' => 'newsletter', 'fields' => [['label' => 'Email', 'type' => 'email', 'required' => true]]]);
        Module::factory()->create(['slug' => 'signup', 'body' => "## Join us\n\n[form:newsletter]"]);
        Entry::factory()->published()->create(['slug' => 'home', 'body' => "Write to us:\n\n[form:contact]\n\n[module:signup]"]);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="form-contact"', false)
            ->assertSee('id="form-newsletter"', false);
    }

    public function test_shortcodes_for_missing_or_empty_forms_are_left_as_written(): void
    {
        Form::factory()->create(['slug' => 'empty', 'fields' => []]);

        $html = Markdown::toHtml("[form:empty]\n\n[form:nope]");

        $this->assertStringContainsString('<p>[form:empty]</p>', $html);
        $this->assertStringContainsString('<p>[form:nope]</p>', $html);
    }

    public function test_an_unknown_field_type_falls_back_to_short_text(): void
    {
        $form = Form::factory()->create(['fields' => [['label' => 'Odd', 'type' => 'no-such-type']]]);

        $this->assertSame(FormFieldType::Text, $form->formFields()->first()['type']);
    }
}
