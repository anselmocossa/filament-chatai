const { test } = require('node:test')
const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const vm = require('node:vm')

function renderer() {
    let factory
    vm.runInNewContext(readFileSync('resources/dist/chatai.js', 'utf8'), {
        document: { addEventListener: (_, callback) => callback() },
        window: { Alpine: { data: (_, fn) => { factory = fn } } },
        console,
    })
    return factory({}).markdown
}

test('renders heading levels as semantic HTML instead of visible hashes', () => {
    const markdown = renderer()
    for (let level = 1; level <= 6; level++) {
        assert.equal(markdown(`${'#'.repeat(level)} Título`), `<h${level}>Título</h${level}>`)
    }
    assert.equal(markdown('# Manual\n\n## 1. Introdução\nTexto.'), '<h1>Manual</h1><h2>1. Introdução</h2><p>Texto.</p>')
})

test('formats headings in a partial stopped answer and while streaming text grows', () => {
    const markdown = renderer()
    for (const content of ['# Manual\n\n## Int', '# Manual\n\n## Introdução\nConteúdo incompleto']) {
        assert.match(markdown(content), /^<h1>Manual<\/h1><h2>/)
        assert.doesNotMatch(markdown(content), /<p>#{1,6} /)
    }
})

test('keeps inline formatting and escaped model HTML inside headings', () => {
    const html = renderer()('## **Resumo** <img src=x onerror=alert(1)>')
    assert.match(html, /^<h2><strong>Resumo<\/strong> /)
    assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'))
    assert.ok(!html.includes('<img'))
})

test('keeps literal hashes in code and ordinary text and closes a preceding list', () => {
    const markdown = renderer()
    assert.equal(markdown('```md\n# Código\n## Literal\n```'), '<pre><code># Código\n## Literal</code></pre>')
    assert.equal(markdown('C# e #etiqueta\n##sem espaço'), '<p>C# e #etiqueta</p><p>##sem espaço</p>')
    assert.equal(markdown('- Item\n## Resumo ##'), '<ul><li>Item</li></ul><h2>Resumo</h2>')
})
