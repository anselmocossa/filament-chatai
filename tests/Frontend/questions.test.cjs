const { test } = require('node:test')
const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const vm = require('node:vm')

function client() {
    let factory
    const context = { document: { addEventListener: (_, callback) => callback() }, window: { Alpine: { data: (_, fn) => { factory = fn } } }, localStorage: { getItem: () => null }, console }
    vm.runInNewContext(readFileSync('resources/dist/chatai.js', 'utf8'), context)
    return factory({ i18n: { questionsRequired: 'Answer all' } })
}
function fixture() {
    const state = client()
    const card = { type: 'questions', status: 'pending', questions: [{ question: 'Objectivo?', options: [{ label: 'Comparar' }] }, { question: 'Área?', options: [] }] }
    return { state, card, message: { id: 'saved-message', components: [card] } }
}
test('requires an explicit selection or nonblank text for every question', () => {
    const { state, card } = fixture()
    assert.equal(state.questionsComplete(card, [null, null], ['', '']), false)
    assert.equal(state.questionsComplete(card, [0, null], ['', '  ']), false)
    assert.equal(state.questionsComplete(card, [0, null], ['', 'SAP']), true)
    assert.equal(state.questionsComplete(card, [-1, null], ['Outro', 'SAP']), true)
    assert.equal(state.questionsComplete(card, [9, null], ['', 'SAP']), false)
})
test('submits answers together without overwriting a composer draft and prevents duplicates', async () => {
    const { state, card, message } = fixture()
    state.input = 'rascunho'
    let requests = 0
    state.send = async (payload) => {
        requests++
        assert.equal(payload.message_id, 'saved-message')
        assert.equal(payload.answers[0].option, 0)
        assert.equal(payload.answers[1].text, 'SAP')
        assert.equal(state.input, 'Objectivo?\nComparar\n\nÁrea?\nSAP')
        return true
    }
    await state.answerQuestions(message, 0, [0, null], ['', 'SAP'])
    assert.equal(card.status, 'answered')
    assert.equal(state.input, 'rascunho')
    await state.answerQuestions(message, 0, [0, null], ['', 'SAP'])
    assert.equal(requests, 1)
})
test('keeps the form editable after rejection and never submits an empty answer', async () => {
    const { state, card, message } = fixture()
    let requests = 0
    state.send = async () => { requests++; return false }
    await state.answerQuestions(message, 0, [null, null], ['', ''])
    assert.equal(requests, 0)
    assert.equal(card.error, 'Answer all')
    await state.answerQuestions(message, 0, [0, null], ['', 'SAP'])
    assert.equal(card.status, 'pending')
    assert.equal(card.busy, false)
})

test('does not overwrite text typed in the composer while the answer is streaming', async () => {
    const { state, message } = fixture()
    state.input = 'existing draft'
    let finish
    state.send = () => new Promise((resolve) => { finish = resolve })
    const sending = state.answerQuestions(message, 0, [0, null], ['', 'SAP'])
    assert.equal(state.input, 'existing draft')
    state.input = 'new draft typed during streaming'
    finish(true)
    await sending
    assert.equal(state.input, 'new draft typed during streaming')
})

test('advances one step only after the current question has an answer', () => {
    const { state, card } = fixture()
    const choices = [null, null]
    const texts = ['', '']
    assert.equal(state.nextQuestionStep(card, 0, choices, texts), 0)
    choices[0] = 0
    assert.equal(state.nextQuestionStep(card, 0, choices, texts), 1)
    assert.equal(state.nextQuestionStep(card, 1, choices, texts), 1)
    texts[1] = 'SAP'
    assert.equal(state.nextQuestionStep(card, 1, choices, texts), 1)
    assert.equal(choices[0], 0)
    assert.equal(texts[1], 'SAP')
})

test('validates only the visible step while keeping later answers for back navigation', () => {
    const { state, card } = fixture()
    assert.equal(state.questionStepComplete(card, [0, null], ['', ''], 0), true)
    assert.equal(state.questionStepComplete(card, [0, null], ['', ''], 1), false)
    assert.equal(state.questionStepComplete(card, [0, null], ['', 'SAP'], 1), true)
    assert.equal(state.questionStepComplete(card, [0, null], ['', 'SAP'], 2), false)
})

test('docks the latest pending question message using its original component indices', () => {
    const { state, card, message } = fixture()
    state.messages = [{ id: 'old', role: 'assistant', components: [{ ...card }] }, { ...message, role: 'assistant', components: [{ type: 'kpi' }, card] }]
    const pending = state.pendingQuestionMessages()
    assert.equal(pending.length, 1)
    assert.equal(pending[0], state.messages[1])
    assert.equal(pending[0].components[1], card)
    state.messages[1].components[1].status = 'answered'
    assert.equal(state.pendingQuestionMessages().length, 0)
})

test('hides the dock while switching conversations and when no questions are pending', () => {
    const { state, message } = fixture()
    state.messages = [{ ...message, role: 'assistant' }]
    state.loading = true
    assert.equal(state.pendingQuestionMessages().length, 0)
    state.loading = false
    assert.equal(state.pendingQuestionMessages().length, 1)
    state.messages = [{ role: 'assistant', components: [] }]
    assert.equal(state.pendingQuestionMessages().length, 0)
})

test('does not send an inactive custom draft when a predefined option is selected', async () => {
    const { state, message } = fixture()
    state.send = async (payload) => {
        assert.equal(payload.answers[0].option, 0)
        assert.equal(payload.answers[0].text, '')
        assert.equal(payload.answers[1].text, 'SAP')
        return true
    }
    await state.answerQuestions(message, 0, [0, -1], ['previous custom draft', 'SAP'])
})
