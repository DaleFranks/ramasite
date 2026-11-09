<?php

namespace App\Http\Controllers;

use App\Models\Form;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FormSubmissionController extends Controller
{
    /**
     * Save what a visitor sent with a form, then return them to the page they
     * sent it from: showing the thank-you message, or their mistakes.
     */
    public function store(Request $request, Form $form): RedirectResponse
    {
        $backToForm = self::backToForm($form);

        // Spam robots are thanked, but nothing is kept.
        if (self::isFromSpamRobot($request)) {
            return $backToForm->with('form_sent', $form->slug);
        }

        $validator = Validator::make(
            $request->all(),
            $form->validationRules(),
            attributes: $form->validationAttributes(),
        );

        if ($validator->fails()) {
            return $backToForm->withErrors($validator, 'form-'.$form->slug)->withInput();
        }

        $form->recordSubmission($validator->validated())->sendNotification();

        return $backToForm->with('form_sent', $form->slug);
    }

    /**
     * Robots fill in the field hidden from people, send the form at once, or
     * post it without running the form's script.
     */
    private static function isFromSpamRobot(Request $request): bool
    {
        return filled($request->input(Form::HONEYPOT_FIELD))
            || $request->input(Form::JAVASCRIPT_CHECK_FIELD) !== Form::JAVASCRIPT_CHECK_VALUE
            || ! Form::wasShownLongEnoughAgo($request->input(Form::SHOWN_AT_FIELD));
    }

    /**
     * A redirect to the page the form was sent from, scrolled to the form.
     */
    public static function backToForm(Form|string $form): RedirectResponse
    {
        $slug = $form instanceof Form ? $form->slug : $form;

        return redirect()->to(Str::before(url()->previous(route('home')), '#').'#form-'.$slug);
    }
}
