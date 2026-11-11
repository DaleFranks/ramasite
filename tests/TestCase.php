<?php

namespace Tests;

use App\Models\Form;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Crypt;

abstract class TestCase extends BaseTestCase
{
    /**
     * A form's answers, along with what a person's browser sends with them to
     * pass the spam checks. The form counts as shown a minute ago, built the
     * way Form::shownAtToken() builds it so that the test's clock is left alone.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    protected function sentByVisitor(array $answers): array
    {
        return [
            Form::SHOWN_AT_FIELD => Crypt::encryptString((string) now()->subMinute()->getTimestamp()),
            Form::JAVASCRIPT_CHECK_FIELD => Form::JAVASCRIPT_CHECK_VALUE,
            ...$answers,
        ];
    }
}
