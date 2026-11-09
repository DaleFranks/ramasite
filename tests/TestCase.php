<?php

namespace Tests;

use App\Models\Form;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A form's answers, along with what a person's browser sends with them to
     * pass the spam checks.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    protected function sentByVisitor(array $answers): array
    {
        return [
            Form::SHOWN_AT_FIELD => $this->travel(-1)->minutes(fn (): string => Form::shownAtToken()),
            Form::JAVASCRIPT_CHECK_FIELD => Form::JAVASCRIPT_CHECK_VALUE,
            ...$answers,
        ];
    }
}
