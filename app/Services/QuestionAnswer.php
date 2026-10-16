<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * r4: one set of answer rules for the API PATCH and the gate-session answer route.
 * - yesno: answer_choice yes|no|skip|other required; 'other' requires answer_text.
 * - text:  answer_text required; answer_choice not allowed.
 * Answering sets state=answered and answered_at=now; reopening clears every answer field.
 */
final class QuestionAnswer
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'answer_choice' => ['sometimes', 'nullable', Rule::in(Question::CHOICES)],
            'answer_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** Throws a 422 ValidationException when the answer doesn't fit the question type. */
    public static function check(string $type, ?string $choice, ?string $text): void
    {
        $text = self::clean($text);
        $errors = [];
        if ($type === 'yesno') {
            if ($choice === null) {
                $errors['answer_choice'] = 'Choose yes, no, skip or other.';
            } elseif ($choice === 'other' && $text === null) {
                $errors['answer_text'] = 'Other needs an answer text.';
            }
        } else {
            if ($choice !== null) {
                $errors['answer_choice'] = 'answer_choice is only valid for yesno questions.';
            }
            if ($text === null) {
                $errors['answer_text'] = 'Text questions need an answer text.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function answer(Question $q, ?string $choice, ?string $text): Question
    {
        self::check($q->type, $choice, $text);
        $q->forceFill([
            'state' => 'answered',
            'answer_choice' => $choice,
            'answer_text' => self::clean($text),
            'answered_at' => Carbon::now(),
        ]);

        return $q;
    }

    public static function reopen(Question $q): Question
    {
        return $q->forceFill(['state' => 'open', 'answer_choice' => null, 'answer_text' => null, 'answered_at' => null]);
    }

    private static function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }
}
