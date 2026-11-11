<?php

namespace Tests\Feature;

use App\Filament\Resources\Forms\Pages\CreateForm;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Resources\Forms\Pages\ListForms;
use App\FormFieldType;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FormResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        auth()->logout();

        $this->get('/admin/forms')->assertRedirect('/admin/login');
    }

    public function test_the_list_shows_forms(): void
    {
        $forms = Form::factory()->count(2)->create();

        Livewire::test(ListForms::class)->assertCanSeeTableRecords($forms);
    }

    public function test_a_form_is_built_with_its_fields_in_order(): void
    {
        $this->fakeRepeaters();

        Livewire::test(CreateForm::class)
            ->fillForm([
                'name' => 'Book a Room',
                'submit_label' => 'Book',
                'success_message' => 'We\'ll be in touch.',
                'fields' => [
                    ['label' => 'Your name', 'type' => FormFieldType::Text, 'required' => true],
                    ['label' => 'Room', 'type' => FormFieldType::Dropdown, 'choices' => ['Single', 'Double']],
                ],
            ])
            ->assertSchemaStateSet(['slug' => 'book-a-room'])
            ->call('create')
            ->assertHasNoFormErrors();

        $form = Form::query()->where('slug', 'book-a-room')->sole();
        $this->assertSame('Book', $form->submit_label);
        $this->assertSame(['your_name', 'room'], $form->formFields()->pluck('key')->all());
        $this->assertSame(['Single', 'Double'], $form->formFields()->last()['choices']);
        $this->assertTrue($form->formFields()->first()['required']);
    }

    public function test_choice_fields_need_choices(): void
    {
        $this->fakeRepeaters();

        Livewire::test(CreateForm::class)
            ->fillForm([
                'name' => 'Survey',
                'fields' => [['label' => 'Colour', 'type' => FormFieldType::Radio, 'choices' => []]],
            ])
            ->call('create')
            ->assertHasFormErrors(['fields.0.choices' => 'required']);

    }

    public function test_a_form_needs_at_least_one_field(): void
    {
        Livewire::test(CreateForm::class)
            ->fillForm(['name' => 'Empty', 'fields' => []])
            ->call('create')
            ->assertHasFormErrors(['fields']);
    }

    public function test_editing_a_field_keeps_its_key(): void
    {
        $this->fakeRepeaters();
        $form = Form::factory()->create(['fields' => [['label' => 'Name', 'type' => 'text']]]);

        Livewire::test(EditForm::class, ['record' => $form->getRouteKey()])
            ->fillForm(['fields' => [['key' => 'name', 'label' => 'Full name', 'type' => FormFieldType::Text]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([['key' => 'name', 'label' => 'Full name', 'type' => 'text']], array_map(
            fn (array $field): array => array_intersect_key($field, array_flip(['key', 'label', 'type'])),
            $form->refresh()->fields,
        ));
    }
}
