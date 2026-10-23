<?php

namespace Filament\ChatAi\Ui;

use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QuestionAnswer
{
    public static function rules(): array
    {
        return [
            'question_answer' => ['nullable', 'array:message_id,component,answers', 'required_array_keys:message_id,component,answers'],
            'question_answer.message_id' => ['required_with:question_answer', 'string', 'max:26'],
            'question_answer.component' => ['required_with:question_answer', 'integer', 'min:0', 'max:100'],
            'question_answer.answers' => ['required_with:question_answer', 'array', 'min:1', 'max:3'],
            'question_answer.answers.*' => ['array:option,text'],
            'question_answer.answers.*.option' => ['nullable', 'integer', 'min:0', 'max:5'],
            'question_answer.answers.*.text' => ['nullable', 'string', 'max:800'],
        ];
    }

    /** Validate against the saved question, never against labels supplied by the browser. */
    public static function submit(Conversation $conversation, array $input): string
    {
        return DB::transaction(function () use ($conversation, $input): string {
            $message = $conversation->messages()->whereKey($input['message_id'])->where('role', Message::ROLE_ASSISTANT)->lockForUpdate()->first();
            $components = $message?->components ?? [];
            $index = $input['component'];
            $card = $components[$index] ?? null;

            if (! is_array($card) || ($card['type'] ?? null) !== 'questions' || ($card['status'] ?? 'pending') !== 'pending') {
                throw ValidationException::withMessages(['question_answer' => __('chatai::chatai.questions.unavailable')]);
            }

            $answers = $input['answers'];
            if (! array_is_list($answers) || count($answers) !== count($card['questions'])) {
                throw ValidationException::withMessages(['question_answer' => __('chatai::chatai.questions.required')]);
            }

            $summary = [];
            foreach ($card['questions'] as $i => $question) {
                $option = $answers[$i]['option'] ?? null;
                $text = trim($answers[$i]['text'] ?? '');
                if ($option !== null && ! isset($question['options'][$option])) {
                    throw ValidationException::withMessages(['question_answer' => __('chatai::chatai.questions.required')]);
                }
                $answer = implode('; ', array_filter([$option !== null ? $question['options'][$option]['label'] : '', $text], fn ($v) => $v !== ''));
                if ($answer === '') {
                    throw ValidationException::withMessages(['question_answer' => __('chatai::chatai.questions.required')]);
                }
                $summary[] = ['question' => $question['question'], 'answer' => $answer];
            }

            $components[$index] = [...$card, 'status' => 'answered', 'answers' => $summary];
            $message->update(['components' => $components]);

            return implode("\n\n", array_map(fn ($a) => $a['question']."\n".$a['answer'], $summary));
        });
    }
}
