{{-- Shared question form and answered summary; expects message, component and index. --}}
<div class="fi-chatai-questions" :class="{ 'fi-chatai-questions-pending': component.status === 'pending' }" x-data="{ step: 0, choices: component.questions.map(() => null), texts: component.questions.map(() => '') }" x-id="['chatai-question']">
    <template x-if="component.status === 'pending'">
        <form x-on:submit.prevent="step < component.questions.length - 1 ? step = nextQuestionStep(component, step, choices, texts) : answerQuestions(message, index, choices, texts)">
            <div class="fi-chatai-question-heading">
                <span class="fi-chatai-question-heading-label"><x-filament::icon icon="heroicon-o-question-mark-circle" />{{ __('chatai::chatai.questions.heading') }}</span>
                <span class="fi-chatai-question-progress" role="status" aria-live="polite" x-show="component.questions.length > 1" x-text="(step + 1) + ' / ' + component.questions.length"></span>
            </div>
            <template x-for="(question, q) in component.questions" :key="q">
                <fieldset class="fi-chatai-question" x-show="q === step" x-cloak :disabled="busy || !!component.busy || q !== step">
                    <legend x-text="question.question"></legend>
                    <div class="fi-chatai-question-options">
                        <template x-for="(option, o) in question.options" :key="o">
                            <label class="fi-chatai-question-option" :class="{ 'fi-selected': choices[q] === o }">
                                <input type="radio" class="fi-chatai-question-radio" :name="$id('chatai-question') + '-' + q" :value="o" x-model.number="choices[q]">
                                <span class="fi-chatai-question-number" aria-hidden="true" x-text="o + 1"></span>
                                <span class="fi-chatai-question-option-text"><strong x-text="option.label"></strong><small x-show="option.description" x-text="option.description"></small></span>
                            </label>
                        </template>
                    </div>
                </fieldset>
            </template>
            <p class="fi-chatai-form-error" role="alert" x-show="component.error" x-text="component.error"></p>
            <div class="fi-chatai-question-footer">
                <label class="fi-chatai-question-free-inline" :class="{ 'fi-selected': choices[step] === -1 }">
                    <x-filament::icon icon="heroicon-o-pencil" />
                    <input type="text" maxlength="800" :aria-label="component.questions[step].question" placeholder="{{ __('chatai::chatai.questions.inline_free') }}" x-model="texts[step]" x-on:focus="choices[step] = -1" :disabled="busy || !!component.busy">
                </label>
                <div class="fi-chatai-question-footer-buttons">
                    <button type="button" class="fi-chatai-question-button" x-show="step > 0" x-on:click="step = Math.max(0, step - 1)" :disabled="busy || !!component.busy">{{ __('chatai::chatai.questions.previous') }}</button>
                    <button type="submit" class="fi-chatai-question-button fi-primary" :disabled="busy || !!component.busy || !questionStepComplete(component, choices, texts, step)" x-text="step < component.questions.length - 1 ? @js(__('chatai::chatai.questions.next')) : @js(__('chatai::chatai.send'))"></button>
                </div>
            </div>
        </form>
    </template>
    <template x-if="component.status === 'answered'">
        <div>
            <p class="fi-chatai-component-title" x-show="component.title" x-text="component.title"></p>
            <p class="fi-chatai-question-badge">{{ __('chatai::chatai.questions.answered') }}</p>
            <dl class="fi-chatai-question-summary">
                <template x-for="(answer, a) in component.answers || []" :key="a">
                    <div><dt x-text="answer.question"></dt><dd x-text="answer.answer"></dd></div>
                </template>
            </dl>
        </div>
    </template>
</div>
