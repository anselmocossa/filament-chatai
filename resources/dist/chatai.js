/**
 * filament-chatai — chat client.
 *
 * Plain ES (no build step): registers the `chataiChat` Alpine component that
 * talks to the plugin's JSON + SSE endpoints and renders the component
 * catalog (kpi, table, chart, suggestions) through Blade/Alpine templates.
 */
document.addEventListener('alpine:init', () => {
    const escapeHtml = (text) =>
        String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')

    // Deliberately small Markdown: bold, italics, inline code, code blocks,
    // lists and http(s) links. Everything is escaped first, so model output
    // can never inject HTML.
    const renderMarkdown = (source) => {
        const blocks = []
        let text = escapeHtml(source).replace(/```[a-z]*\n?([\s\S]*?)```/g, (_, code) => {
            blocks.push(`<pre><code>${code.replace(/\n$/, '')}</code></pre>`)
            return `\u0000${blocks.length - 1}\u0000`
        })

        const inline = (line) =>
            line
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
                .replace(
                    /\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g,
                    '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
                )

        const html = []
        let list = null

        for (const raw of text.split('\n')) {
            const line = raw.trimEnd()
            const bullet = line.match(/^\s*[-*•]\s+(.*)$/)
            const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/)
            const kind = bullet ? 'ul' : numbered ? 'ol' : null

            if (kind) {
                if (list !== kind) {
                    if (list) html.push(`</${list}>`)
                    html.push(`<${kind}>`)
                    list = kind
                }
                html.push(`<li>${inline((bullet || numbered)[1])}</li>`)
                continue
            }

            if (list) {
                html.push(`</${list}>`)
                list = null
            }

            if (line.trim() === '') continue

            html.push(/^\u0000\d+\u0000$/.test(line.trim()) ? line.trim() : `<p>${inline(line)}</p>`)
        }

        if (list) html.push(`</${list}>`)

        return html.join('').replace(/\u0000(\d+)\u0000/g, (_, i) => blocks[Number(i)])
    }

    const storage = {
        get(key) {
            try {
                return window.localStorage.getItem(key)
            } catch {
                return null
            }
        },
        set(key, value) {
            try {
                value === null ? window.localStorage.removeItem(key) : window.localStorage.setItem(key, value)
            } catch {
                // Private mode or blocked storage: the chat still works, it just won't remember.
            }
        },
    }

    const SIDEBAR_KEY = 'chatai.sidebar'

    // Groups the user folded in the history.
    const COLLAPSED_KEY = 'chatai.collapsed-groups'

    const readCollapsed = () => {
        try { return JSON.parse(storage.get(COLLAPSED_KEY) || '[]') } catch { return [] }
    }

    const BROWSER_VOICE_KEY = 'chatai.voice'

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition

    // Markdown → what a voice should say: no asterisks, backticks or link syntax.
    const plainText = (text) => String(text || '')
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/[*_`#>]+/g, '')
        .replace(/^\s*[-•]\s+/gm, '')
        .replace(/\s+/g, ' ')
        .trim()

    const MAX_RECORDING_MS = 55_000

    // Any recording the browser makes (webm, mp4, ogg) → 16 kHz mono 16-bit WAV,
    // the one format every speech service accepts.
    const toWav = async (blob) => {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext
        const decoder = new AudioContextClass()
        const decoded = await decoder.decodeAudioData(await blob.arrayBuffer())
        decoder.close?.()

        const rate = 16000
        const offline = new OfflineAudioContext(1, Math.ceil(decoded.duration * rate), rate)
        const source = offline.createBufferSource()
        source.buffer = decoded
        source.connect(offline.destination)
        source.start()

        const samples = (await offline.startRendering()).getChannelData(0)
        const view = new DataView(new ArrayBuffer(44 + samples.length * 2))
        const text = (offset, value) => [...value].forEach((char, i) => view.setUint8(offset + i, char.charCodeAt(0)))

        text(0, 'RIFF'); view.setUint32(4, 36 + samples.length * 2, true); text(8, 'WAVE')
        text(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true)
        view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true)
        text(36, 'data'); view.setUint32(40, samples.length * 2, true)
        samples.forEach((sample, i) => {
            const clamped = Math.max(-1, Math.min(1, sample))
            view.setInt16(44 + i * 2, clamped < 0 ? clamped * 0x8000 : clamped * 0x7fff, true)
        })

        return new Blob([view], { type: 'audio/wav' })
    }

    const mdCell = (value) => String(value ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ')

    const mdTable = (headers, rows) => [
        `| ${headers.map(mdCell).join(' | ')} |`,
        `| ${headers.map(() => '---').join(' | ')} |`,
        ...rows.map((row) => `| ${row.map(mdCell).join(' | ')} |`),
    ].join('\n')

    const isNarrow = () => window.matchMedia('(max-width: 767px)').matches

    // ---- the screen: what the user is looking at, and filling in its form ----

    const CHAT = '.fi-chatai-panel, .fi-chatai-page, .fi-chatai-floating'
    // textContent, not innerText: fields in hidden wizard steps or tabs have no innerText.
    const text = (el) => (el?.textContent || '').replace(/\s+/g, ' ').trim()

    // The Livewire state path of a Filament field: wire:model="data.qty" or $entangle('data.qty').
    const statePath = (field) => {
        for (const el of [field, ...field.querySelectorAll('*')]) {
            for (const attr of el.attributes || []) {
                if (attr.name.startsWith('wire:model')) return { el, path: attr.value }
                const entangled = attr.value?.match?.(/\$entangle\(['"]([^'"]+)['"]/)
                if (entangled) return { el, path: entangled[1] }
            }
        }

        return null
    }

    const fieldsOnScreen = () => {
        const fields = []

        for (const wrapper of document.querySelectorAll('.fi-main .fi-fo-field')) {
            if (wrapper.closest(CHAT) || wrapper.closest('.fi-modal') && !wrapper.closest('.fi-modal-open')) continue

            const found = statePath(wrapper)
            if (!found || fields.some((f) => f.path === found.path)) continue

            const wire = window.Livewire?.find(found.el.closest('[wire\\:id]')?.getAttribute('wire:id'))
            let value = wire ? wire.get(found.path) : (found.el.value ?? '')
            if (Array.isArray(value)) value = value.join(', ')
            if (value === null || value === undefined) value = ''
            if (value && typeof value === 'object') value = JSON.stringify(value).slice(0, 300)

            const input = wrapper.querySelector('input, textarea, select')
            const choices = wrapper.querySelectorAll('input[type=radio], input[type=checkbox]')

            // Choices as "value = name", so the model can pick by what the user reads.
            const named = (value, label) => (label && label !== value ? `${value} = ${label}` : value)
            const options = [
                ...[...wrapper.querySelectorAll('select option')].filter((o) => o.value !== '').map((o) => named(o.value, text(o))),
                ...(choices.length > 1 ? [...choices].map((c) => named(c.value, text(c.labels?.[0] || c.closest('label')))) : []),
            ].filter(Boolean).slice(0, 40)

            // Which step of a wizard (or which tab / section) the field is in.
            const step = wrapper.closest('.fi-sc-wizard-step')
            const steps = step ? [...step.closest('.fi-sc-wizard')?.querySelectorAll('.fi-sc-wizard-header-step') || []] : []
            const stepIndex = step ? [...step.parentElement.querySelectorAll(':scope > .fi-sc-wizard-step')].indexOf(step) : -1
            const section = step
                ? `Step ${stepIndex + 1}${steps[stepIndex] ? ': ' + text(steps[stepIndex]).replace(/^\d+\s*/, '') : ''}`
                : text(wrapper.closest('.fi-section')?.querySelector('.fi-section-header-heading'))
            const many = choices.length > 1 && [...choices].every((c) => c.type === 'checkbox')

            // What the user reads for a choice (a name, not its id).
            const shown = text(wrapper.querySelector('.fi-select-input-value-ctn, .fi-select-input-value-label'))

            fields.push({
                path: found.path,
                display: shown && shown !== String(value) ? shown.slice(0, 300) : undefined,
                label: text(wrapper.querySelector('.fi-fo-field-label-content, label')).replace(/\*$/, ''),
                section: section || undefined,
                // The format the field expects, so the model writes "#2563eb" and not "blue".
                type: wrapper.querySelector('.fi-fo-color-picker') ? 'color (hex, e.g. #2563eb)'
                    : wrapper.querySelector('.fi-fo-date-time-picker') ? 'date (YYYY-MM-DD)'
                    : many ? 'checkbox_list' : choices.length > 1 ? 'radio' : wrapper.querySelector('select') ? (wrapper.querySelector('select').multiple ? 'multi_select' : 'select') : input?.type || (wrapper.querySelector('[role=switch], .fi-toggle') ? 'toggle' : 'text'),
                value: value ?? '',
                options: options.length ? options : undefined,
            })
        }

        return fields.slice(0, 60)
    }

    const tablesOnScreen = () => [...document.querySelectorAll('.fi-main .fi-ta-table')]
        .filter((t) => !t.closest(CHAT))
        .slice(0, 2)
        .map((table) => ({
            headers: [...table.querySelectorAll('thead th')].map(text).filter(Boolean).slice(0, 12),
            rows: [...table.querySelectorAll('tbody tr')].slice(0, 15).map((tr) => [...tr.querySelectorAll('td')].map(text).filter(Boolean).slice(0, 12)),
        }))

    const readScreen = (config) => {
        if (!config.screen?.read || document.querySelector('.fi-chatai-page')) return undefined

        try {
            return {
                heading: text(document.querySelector('.fi-main .fi-header-heading')),
                breadcrumbs: text(document.querySelector('.fi-main .fi-breadcrumbs')),
                fields: fieldsOnScreen(),
                tables: tablesOnScreen(),
            }
        } catch {
            return undefined
        }
    }

    const agentEvent = (detail) => window.dispatchEvent(new CustomEvent('chatai-agent', { detail }))
    const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

    // Writes the assistant's values into the open form one field at a time, so the
    // user can follow it (the chat shrinks to a bar meanwhile). The user saves.
    const fillScreen = async (fields, labels = {}) => {
        let filled = 0
        let stopped = false
        const stop = () => { stopped = true }
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

        window.addEventListener('chatai-agent-stop', stop)
        agentEvent({ state: 'start', total: fields.length })
        await pause(reduced ? 0 : 350)

        for (const { path, value } of fields) {
            if (stopped) break

            for (const wrapper of document.querySelectorAll('.fi-main .fi-fo-field')) {
                const found = !wrapper.closest(CHAT) && statePath(wrapper)
                if (!found || found.path !== path) continue

                const wire = window.Livewire?.find(found.el.closest('[wire\\:id]')?.getAttribute('wire:id'))
                if (!wire) continue

                if (wrapper.offsetParent) wrapper.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' })
                wire.set(path, value, false)
                wrapper.classList.add('fi-chatai-filled')
                setTimeout(() => wrapper.classList.remove('fi-chatai-filled'), 8000)
                filled++
                break
            }

            agentEvent({ state: 'progress', done: filled, label: labels[path] || '' })
            await pause(reduced ? 0 : 220)
        }

        window.removeEventListener('chatai-agent-stop', stop)
        agentEvent({ state: 'done', done: filled, stopped })

        return filled
    }

    const readContext = (config = {}) => {
        let server = {}
        try {
            server = JSON.parse(document.getElementById('chatai-context')?.textContent || '{}')
        } catch {
            server = {}
        }

        return {
            ...server,
            url: window.location.pathname + window.location.search,
            page_title: document.title,
            screen: readScreen(config),
        }
    }

    window.Alpine.data('chataiChat', (config) => ({
        config, // read by the templates (voice menu)
        conversationId: null,
        title: null,
        messages: [],
        input: '',
        busy: false,
        loading: false,
        status: null,
        notice: null,
        conversations: [],
        pendingNavigation: null,
        groups: [],
        collapsedGroups: readCollapsed(),
        editingId: null,
        editingGroupId: null,
        editingText: '',
        search: '',
        sidebarOpen: false,
        stickToEnd: true,
        lastQuestion: null,
        listening: false,
        listeningSince: null,
        clock: 0,
        speakingId: null,
        transcribing: false,
        voicePanel: false,
        picker: null,
        attachments: [],
        dragging: false,
        userVoice: config.userVoice || null,
        browserVoices: [],
        browserVoice: storage.get(BROWSER_VOICE_KEY),
        canDictate: Boolean(config.voice && ((config.serverVoice && navigator.mediaDevices?.getUserMedia && window.MediaRecorder) || SpeechRecognition)),
        canSpeak: Boolean(config.voice && (config.serverVoice || window.speechSynthesis)),

        init() {
            const fromUrl = new URLSearchParams(window.location.search).get('conversation')
            const id = config.conversationId || fromUrl || (config.remember ? storage.get(config.storageKey) : null)

            this.$nextTick(() => this.followEnd())

            if (id) this.open(id)
            if (config.withSidebar) {
                this.sidebarOpen = !isNarrow() && storage.get(SIDEBAR_KEY) === '1'
                this.loadConversations()
            }

            window.addEventListener('chatai-open-conversation', (event) => {
                if (event.detail?.id && event.detail.id !== this.conversationId) this.open(event.detail.id)
            })
        },

        headers(json = true) {
            return {
                Accept: json ? 'application/json' : 'text/event-stream',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            }
        },

        url(id = null, suffix = '') {
            return config.endpoint + (id ? `/${encodeURIComponent(id)}` : '') + suffix
        },

        async open(id) {
            this.loading = true
            this.notice = null

            try {
                const response = await fetch(this.url(id), { headers: this.headers(), credentials: 'same-origin' })

                if (!response.ok) {
                    // Gone or not ours: start fresh instead of showing an error.
                    this.reset()
                    return
                }

                const data = await response.json()
                this.conversationId = data.id
                this.title = data.title
                this.messages = data.messages.map((m) => ({ ...m, tool: null }))
                this.remember()
                this.scrollToEnd()
            } finally {
                this.loading = false
            }
        },

        reset() {
            this.stopSpeaking()
            this.conversationId = null
            this.title = null
            this.messages = []
            this.notice = null
            this.status = null
            this.remember()
        },

        newConversation() {
            if (this.busy) return
            this.reset()
            this.$nextTick(() => this.$refs.input?.focus())
        },

        remember() {
            if (config.remember) storage.set(config.storageKey, this.conversationId)

            if (config.withSidebar) {
                const url = new URL(window.location.href)
                this.conversationId
                    ? url.searchParams.set('conversation', this.conversationId)
                    : url.searchParams.delete('conversation')
                window.history.replaceState(window.history.state, '', url)
            }
        },

        async ensureConversation() {
            if (this.conversationId) return this.conversationId

            const response = await fetch(this.url(), {
                method: 'POST',
                headers: this.headers(),
                credentials: 'same-origin',
            })

            if (!response.ok) throw new Error(config.i18n.unavailable)

            this.conversationId = (await response.json()).id
            this.remember()

            return this.conversationId
        },

        onKeydown(event) {
            // While the @ / list is open, the keyboard drives it.
            if (this.picker && this.pickerItems().length) {
                const items = this.pickerItems()

                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault()
                    const step = event.key === 'ArrowDown' ? 1 : -1
                    this.picker.index = (this.picker.index + step + items.length) % items.length
                    return
                }

                if ((event.key === 'Enter' || event.key === 'Tab') && !event.isComposing) {
                    event.preventDefault()
                    this.choose(items[this.picker.index])
                    return
                }

                if (event.key === 'Escape') {
                    event.preventDefault()
                    event.stopPropagation()
                    this.picker = null
                    return
                }
            }

            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault()
                this.send()
            }
        },

        // ---- changes that wait for confirmation ----------------------------

        async answerAction(component, confirmed) {
            if (component.busy || component.status !== 'pending') return

            component.busy = true
            const url = config.actionUrl.replace('__action__', encodeURIComponent(component.action_id)).replace(/confirm$/, confirmed ? 'confirm' : 'cancel')

            try {
                const values = confirmed ? Object.fromEntries((component.fields || []).filter((f) => f.key).map((f) => [f.key, f.value])) : undefined
                const response = await fetch(url, { method: 'POST', headers: this.headers(), credentials: 'same-origin', body: JSON.stringify(values ? { values } : {}) })
                const data = await response.json().catch(() => ({}))

                if (response.status === 422 && data.errors) {
                    component.errors = data.errors
                    return
                }

                if (!response.ok) throw new Error(data.message || config.i18n.unavailable)

                component.errors = null

                component.status = data.status
                component.result = data.result ?? []
                if (data.message) this.messages.push(data.message)
                this.$nextTick(() => this.scrollToEnd?.())
            } catch (error) {
                this.notice = error.message
            } finally {
                component.busy = false
            }
        },

        // ---- taking the user somewhere ----------------------------------

        // The answer is read first; then the page changes and the chat reopens there, same conversation.
        goTo(url) {
            this.pendingNavigation = null
            if (this.conversationId) storage.set(config.storageKey, this.conversationId)
            try { sessionStorage.setItem('chatai-open', '1') } catch {}

            setTimeout(() => {
                window.Livewire?.navigate ? window.Livewire.navigate(url) : (window.location.href = url)
            }, 900)
        },

        // ---- one approval for a list of changes ----------------------------

        batchPage(component) {
            component.page ??= 0
            const size = 10
            const start = component.page * size

            return { rows: component.rows.slice(start, start + size), from: component.rows.length ? start + 1 : 0, to: Math.min(start + size, component.rows.length), total: component.rows.length, last: start + size >= component.rows.length }
        },

        batchSelected(component) {
            return component.rows.filter((r) => r.status === 'pending' && !r.excluded)
        },

        toggleAllRows(component, on) {
            component.rows.forEach((r) => { if (r.status === 'pending') r.excluded = !on })
        },

        async answerBatch(component, confirmed) {
            if (component.busy || component.status !== 'pending') return

            component.busy = true

            try {
                const pending = component.rows.filter((r) => r.status === 'pending')
                const response = await fetch(config.batchUrl, {
                    method: 'POST',
                    headers: this.headers(),
                    credentials: 'same-origin',
                    body: JSON.stringify({ ids: pending.map((r) => r.action_id), skip: pending.filter((r) => r.excluded).map((r) => r.action_id), confirmed }),
                })
                const data = await response.json().catch(() => ({}))

                if (!response.ok) throw new Error(data.message || config.i18n.unavailable)

                for (const update of data.rows || []) {
                    const row = component.rows.find((r) => r.action_id === update.action_id)
                    if (row) Object.assign(row, { status: update.status, error: update.error || null })
                }

                component.status = data.status
                if (data.message) this.messages.push(data.message)
                this.$nextTick(() => this.scrollToEnd?.())
            } catch (error) {
                this.notice = error.message
            } finally {
                component.busy = false
            }
        },

        // ---- attachments ---------------------------------------------------

        // Files upload as soon as they are picked (button, drop or paste) and ride with the next message.
        async attach(fileList) {
            const files = [...(fileList ?? [])]
            const limits = config.files ?? { max: 5, maxKb: 10240 }

            if (!files.length) return

            if (this.attachments.length + files.length > limits.max) {
                this.notice = config.i18n.tooManyFiles.replace(':max', limits.max)
                return
            }

            let conversation

            try {
                conversation = await this.ensureConversation()
            } catch (error) {
                this.notice = error.message
                return
            }

            for (const file of files) {
                if (file.size > limits.maxKb * 1024) {
                    this.notice = config.i18n.fileTooBig.replace(':name', file.name)
                    continue
                }

                const item = { key: `${Date.now()}-${file.name}`, id: null, name: file.name, size: file.size, uploading: true }
                this.attachments.push(item)

                const body = new FormData()
                body.append('file', file)

                try {
                    const { 'Content-Type': _json, ...headers } = this.headers()
                    const response = await fetch(config.filesUrl.replace('__conversation__', encodeURIComponent(conversation)), { method: 'POST', headers, body, credentials: 'same-origin' })
                    const data = await response.json().catch(() => ({}))

                    if (!response.ok) throw new Error(data.message || config.i18n.unavailable)

                    const uploaded = this.attachments.find((a) => a.key === item.key)
                    if (uploaded) Object.assign(uploaded, { id: data.id, uploading: false })
                } catch (error) {
                    this.attachments = this.attachments.filter((a) => a.key !== item.key)
                    this.notice = error.message
                }
            }
        },

        removeAttachment(key) {
            this.attachments = this.attachments.filter((a) => a.key !== key)
        },

        // Images pasted into the box are attached too.
        pasteFiles(event) {
            const files = [...(event.clipboardData?.files ?? [])]

            if (files.length) {
                event.preventDefault()
                this.attach(files)
            }
        },

        formatSize(bytes) {
            return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
        },

        // ---- @ tools and / skills in the composer --------------------------

        // "@ven" or "/res" right before the caret opens the matching list.
        detectPicker(el) {
            const before = el.value.slice(0, el.selectionStart)
            const match = before.match(/(^|\s)([@/])([\w-]*)$/)
            const type = match?.[2]
            const source = type === '@' ? config.mentions : type === '/' ? config.skills : null

            if (!match || !source?.length) {
                this.picker = null
                return
            }

            const query = match[3]
            const sameList = this.picker?.type === type && this.picker?.query === query
            this.picker = { type, query, start: el.selectionStart - query.length - 1, index: sameList ? this.picker.index : 0 }
        },

        pickerItems() {
            if (!this.picker) return []

            const query = this.picker.query.toLowerCase()
            const source = (this.picker.type === '@' ? config.mentions : config.skills) ?? []

            return source
                .filter((item) => !query || item.name.toLowerCase().includes(query) || item.label.toLowerCase().includes(query))
                .slice(0, 8)
        },

        choose(item) {
            if (!item || !this.picker) return

            const el = this.$refs.input
            const token = `${this.picker.type}${item.name} `
            this.input = this.input.slice(0, this.picker.start) + token + this.input.slice(el.selectionStart)

            const caret = this.picker.start + token.length
            this.picker = null
            this.$nextTick(() => {
                el.focus()
                el.setSelectionRange(caret, caret)
            })
        },

        // What the message picked: known @tools and the first known /skill.
        picks(text) {
            const mentions = (config.mentions ?? []).filter((t) => new RegExp(`(^|\\s)@${t.name}(?![\\w-])`).test(text)).map((t) => t.name)
            const skill = (config.skills ?? []).find((s) => new RegExp(`(^|\\s)/${s.name}(?![\\w-])`).test(text))?.name ?? null

            return { mentions, skill }
        },

        // User bubbles show picked tools and skills as chips (text escaped first).
        highlightPicks(text) {
            const escape = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
            let html = escape(text)

            for (const tool of config.mentions ?? []) {
                html = html.replace(new RegExp(`(^|\\s)@${tool.name}(?![\\w-])`, 'g'), `$1<span class="fi-chatai-pick">@${escape(tool.label)}</span>`)
            }

            for (const skill of config.skills ?? []) {
                html = html.replace(new RegExp(`(^|\\s)/${skill.name}(?![\\w-])`, 'g'), `$1<span class="fi-chatai-pick fi-chatai-pick-skill">/${escape(skill.label)}</span>`)
            }

            return html
        },

        ask(prompt) {
            this.input = prompt
            this.send()
        },

        // Tool editor test chat: send the form as it is now, saved or not, plus the test options.
        playgroundOptions: { model_id: '', effort: '', max_tokens: '', temperature: '' },
        playgroundSettings: false,

        playgroundModel() {
            return config.playground?.models.find((m) => String(m.id) === String(this.playgroundOptions.model_id)) ?? null
        },

        // Picking a model starts from its own effort (as set on the Models page).
        pickPlaygroundModel() {
            this.playgroundOptions.effort = this.playgroundModel()?.effort ?? ''
        },

        playgroundDraft() {
            if (!config.playground) return {}

            const { models, ...playground } = config.playground
            const options = Object.fromEntries(Object.entries(this.playgroundOptions).filter(([, value]) => value !== '' && value !== null))

            return { draft: { ...playground, data: this.$wire?.get('data') ?? {}, options } }
        },

        retry() {
            if (this.lastQuestion) this.ask(this.lastQuestion)
        },

        async send() {
            const question = this.input.trim()

            if (!question || this.busy || this.attachments.some((a) => a.uploading)) return

            const files = this.attachments.filter((a) => a.id)
            this.attachments = []
            this.input = ''
            this.busy = true
            this.notice = null
            this.lastQuestion = question
            this.messages.push({ id: `local-${Date.now()}`, role: 'user', content: question, components: [], attachments: files })

            const answer = { id: `answer-${Date.now()}`, role: 'assistant', content: '', components: [], status: 'streaming', tool: null }
            this.messages.push(answer)
            const target = this.messages[this.messages.length - 1]
            this.scrollToEnd()

            try {
                const id = await this.ensureConversation()
                const response = await fetch(this.url(id, '/messages'), {
                    method: 'POST',
                    headers: this.headers(false),
                    credentials: 'same-origin',
                    body: JSON.stringify({ message: question, context: readContext(config), attachments: files.map((f) => f.id), ...this.picks(question), ...this.playgroundDraft() }),
                })

                if (!response.ok || !response.body) {
                    let message = config.i18n.unavailable
                    try {
                        message = (await response.json()).message || message
                    } catch {}
                    throw new Error(message)
                }

                await this.consume(response.body, target)

                if (target.status === 'streaming') target.status = 'interrupted'
            } catch (error) {
                target.status = 'error'
                target.tool = null
                this.notice = error?.message || config.i18n.unavailable
            } finally {
                this.busy = false
                this.status = null
                if (target.status === 'error' && !target.content && target.components.length === 0) {
                    this.messages.splice(this.messages.indexOf(target), 1)
                }
                this.scrollToEnd()
                this.$nextTick(() => this.$refs.input?.focus())
            }
        },

        async consume(body, target) {
            const reader = body.getReader()
            const decoder = new TextDecoder()
            let buffer = ''

            while (true) {
                const { value, done } = await reader.read()
                if (done) break

                buffer += decoder.decode(value, { stream: true })

                let boundary
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, boundary)
                    buffer = buffer.slice(boundary + 2)

                    let type = 'message'
                    let data = ''
                    for (const line of raw.split('\n')) {
                        if (line.startsWith('event:')) type = line.slice(6).trim()
                        else if (line.startsWith('data:')) data += line.slice(5).trim()
                    }

                    if (data) this.apply(type, JSON.parse(data), target)
                }
            }
        },

        apply(type, data, target) {
            switch (type) {
                case 'fill':
                    if (config.screen?.fill) fillScreen(data.fields || [], Object.fromEntries((data.fields || []).map((f) => [f.path, f.label])))
                    break
                case 'step':
                    // After tools the model writes the real answer; what it said before was narration.
                    target.restart = true
                    break
                case 'delta':
                    target.tool = null
                    if (target.restart) target.content = ''
                    target.restart = false
                    target.content += data.text
                    this.scrollToEnd()
                    break
                case 'tool_start':
                    target.tool = data.label
                    this.status = data.label
                    break
                case 'tool_end':
                    // The model now writes the answer and its visuals, which can take a few seconds.
                    target.tool = config.i18n.preparing
                    this.status = null
                    break
                case 'component':
                    target.tool = null
                    target.components.push(data.component)
                    if (data.component.type === 'navigate' && config.screen?.navigate) this.pendingNavigation = data.component.url
                    this.scrollToEnd()
                    break
                case 'done':
                    target.tool = null
                    target.status = 'complete'
                    target.id = data.message_id
                    if (data.title) this.title = data.title
                    if (config.withSidebar) this.loadConversations()
                    if (this.pendingNavigation) this.goTo(this.pendingNavigation)
                    break
                case 'error':
                    target.tool = null
                    target.status = 'error'
                    this.notice = data.message
                    break
            }
        },

        scrollToEnd() {
            this.stickToEnd = true
            this.$nextTick(() => {
                const el = this.$refs.scroller
                if (el) el.scrollTop = el.scrollHeight
            })
        },

        // Charts and tables finish drawing after the message is in, and opening
        // the history resizes the chat: stay at the bottom unless the user scrolled up.
        followEnd() {
            const el = this.$refs.scroller
            if (!el || typeof ResizeObserver === 'undefined') return

            this.stickToEnd = true
            el.addEventListener('scroll', () => {
                this.stickToEnd = el.scrollHeight - el.scrollTop - el.clientHeight < 80
            }, { passive: true })

            const resized = new ResizeObserver(() => {
                if (this.stickToEnd) el.scrollTop = el.scrollHeight
            })
            const observeChildren = () => [el, ...el.children].forEach((child) => resized.observe(child))

            observeChildren()
            new MutationObserver(observeChildren).observe(el, { childList: true })
        },

        async loadConversations() {
            const query = this.search.trim() ? `?search=${encodeURIComponent(this.search.trim())}` : ''
            const response = await fetch(this.url() + query, { headers: this.headers(), credentials: 'same-origin' })

            if (response.ok) {
                const data = await response.json()
                this.conversations = data.data
                this.groups = data.groups ?? []
            }
        },

        // ---- history: pinned, groups, then by date ------------------------

        historySections() {
            const t = config.i18n
            const day = 86400000
            const startOfToday = new Date(new Date().toDateString()).getTime()
            const buckets = [
                { key: 'today', label: t.today, from: startOfToday },
                { key: 'yesterday', label: t.yesterday, from: startOfToday - day },
                { key: 'week', label: t.week, from: startOfToday - 7 * day },
                { key: 'month', label: t.month, from: startOfToday - 30 * day },
                { key: 'older', label: t.older, from: -Infinity },
            ].map((b) => ({ ...b, items: [] }))

            const pinned = this.conversations.filter((c) => c.pinned)
            const groups = this.groups.map((g) => ({ key: `group-${g.id}`, label: g.name, group: g, items: this.conversations.filter((c) => !c.pinned && c.group_id === g.id) }))

            for (const c of this.conversations.filter((c) => !c.pinned && !this.groups.some((g) => g.id === c.group_id))) {
                const when = new Date(c.last_message_at).getTime()
                buckets.find((b) => when >= b.from).items.push(c)
            }

            return [{ key: 'pinned', label: t.pinned, items: pinned }, ...groups, ...buckets]
        },

        async patchConversation(item, body) {
            const response = await fetch(this.url(item.id), { method: 'PATCH', headers: this.headers(), credentials: 'same-origin', body: JSON.stringify(body) })

            if (response.ok) Object.assign(item, await response.json())
            if (item.id === this.conversationId && 'title' in body) this.title = item.title

            return response.ok
        },

        togglePin(item) {
            this.patchConversation(item, { pinned: !item.pinned })
        },

        startRename(item) {
            this.editingGroupId = null
            this.editingText = item.title || ''
            this.editingId = item.id
        },

        saveRename(item) {
            if (this.editingId !== item.id) return
            this.editingId = null
            if ((this.editingText.trim() || null) !== (item.title || null)) this.patchConversation(item, { title: this.editingText.trim() || null })
        },

        moveTo(item, groupId) {
            if (groupId) this.collapsedGroups = this.collapsedGroups.filter((id) => id !== groupId)
            this.patchConversation(item, { group_id: groupId })
        },

        // A new group starts with a default name, ready to be renamed in place.
        async createGroup(item = null) {
            const response = await fetch(config.groupsUrl, { method: 'POST', headers: this.headers(), credentials: 'same-origin', body: JSON.stringify({ name: config.i18n.newGroupName }) })

            if (!response.ok) return

            const group = await response.json()
            this.groups.push(group)
            if (item) await this.moveTo(item, group.id)
            this.startGroupRename(group)
        },

        startGroupRename(group) {
            this.editingId = null
            this.editingText = group.name
            this.editingGroupId = group.id
        },

        async saveGroup(group) {
            if (this.editingGroupId !== group.id) return
            this.editingGroupId = null
            const name = this.editingText.trim()

            if (!name || name === group.name) return

            const response = await fetch(`${config.groupsUrl}/${group.id}`, { method: 'PATCH', headers: this.headers(), credentials: 'same-origin', body: JSON.stringify({ name }) })
            if (response.ok) group.name = name
        },

        async deleteGroup(group) {
            if (!window.confirm(config.i18n.confirmDeleteGroup)) return

            const response = await fetch(`${config.groupsUrl}/${group.id}`, { method: 'DELETE', headers: this.headers(), credentials: 'same-origin' })

            if (response.ok) {
                this.groups = this.groups.filter((g) => g.id !== group.id)
                this.conversations.forEach((c) => { if (c.group_id === group.id) c.group_id = null })
            }
        },

        isCollapsed(id) {
            return this.collapsedGroups.includes(id)
        },

        toggleGroup(id) {
            this.collapsedGroups = this.isCollapsed(id) ? this.collapsedGroups.filter((g) => g !== id) : [...this.collapsedGroups, id]
            storage.set(COLLAPSED_KEY, JSON.stringify(this.collapsedGroups))
        },

        async remove(id) {
            if (!window.confirm(config.i18n.confirmDelete)) return

            const response = await fetch(this.url(id), {
                method: 'DELETE',
                headers: this.headers(),
                credentials: 'same-origin',
            })

            if (response.ok) {
                this.conversations = this.conversations.filter((c) => c.id !== id)
                if (id === this.conversationId) this.reset()
            }
        },

        expandUrl() {
            return config.pageUrl + (this.conversationId ? `?conversation=${encodeURIComponent(this.conversationId)}` : '')
        },

        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen
            if (!isNarrow()) storage.set(SIDEBAR_KEY, this.sidebarOpen ? '1' : null)
        },

        // On phones the history is a drawer over the chat: close it after a choice.
        closeSidebarIfNarrow() {
            if (isNarrow()) this.sidebarOpen = false
        },

        // "0:07" since dictation started; the clock ticks only while listening.
        recordingTime() {
            this.clock // re-render every tick
            const seconds = this.listeningSince ? Math.floor((Date.now() - this.listeningSince) / 1000) : 0
            return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
        },

        startClock() {
            this.listeningSince = Date.now()
            clearInterval(this.clockTimer)
            this.clockTimer = setInterval(() => {
                if (!this.listening) {
                    clearInterval(this.clockTimer)
                    this.listeningSince = null
                    return
                }
                this.clock++
            }, 500)
        },

        speechLang() {
            return config.speechLang || navigator.language || config.locale || 'en'
        },

        dictate() {
            if (this.transcribing) return

            if (this.serverDictation()) return this.recorder ? this.recorder.stop() : this.record()

            if (this.listening) return this.recognition?.stop()

            const recognition = new SpeechRecognition()
            const before = this.input.trim() ? this.input.trim() + ' ' : ''

            recognition.lang = this.speechLang()
            recognition.interimResults = true
            recognition.continuous = true
            recognition.onresult = (event) => {
                this.input = before + [...event.results].map((result) => result[0].transcript).join('').trim()
            }
            recognition.onerror = (event) => {
                if (['not-allowed', 'service-not-allowed'].includes(event.error)) this.notice = config.i18n.micDenied
            }
            recognition.onend = () => {
                this.listening = false
                this.$refs.input?.focus()
            }

            this.recognition = recognition
            this.listening = true
            this.startClock()
            this.notice = null
            recognition.start()
        },

        serverDictation() {
            return Boolean(config.serverVoice && navigator.mediaDevices?.getUserMedia && window.MediaRecorder)
        },

        // Server dictation: record, convert to 16 kHz WAV, send once the user stops.
        async record() {
            let stream

            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true })
            } catch {
                this.notice = config.i18n.micDenied
                return
            }

            const chunks = []
            const recorder = new MediaRecorder(stream)
            const limit = setTimeout(() => recorder.state === 'recording' && recorder.stop(), MAX_RECORDING_MS)

            recorder.ondataavailable = (event) => event.data.size && chunks.push(event.data)
            recorder.onstop = async () => {
                clearTimeout(limit)
                stream.getTracks().forEach((track) => track.stop())
                this.recorder = null
                this.listening = false
                this.transcribing = true

                try {
                    const body = new FormData()
                    body.append('audio', await toWav(new Blob(chunks, { type: recorder.mimeType })), 'question.wav')

                    const { 'Content-Type': _json, ...headers } = this.headers()
                    const response = await fetch(config.transcribeUrl, { method: 'POST', headers, body, credentials: 'same-origin' })
                    const data = await response.json().catch(() => ({}))

                    if (!response.ok) throw new Error(data.message || config.i18n.speechUnavailable)
                    if (data.text) this.input = (this.input.trim() ? this.input.trim() + ' ' : '') + data.text
                } catch (error) {
                    this.notice = error?.message || config.i18n.speechUnavailable
                } finally {
                    this.transcribing = false
                    this.$nextTick(() => this.$refs.input?.focus())
                }
            }

            this.recorder = recorder
            this.listening = true
            this.startClock()
            this.notice = null
            recorder.start()
        },

        stopSpeaking() {
            this.audio?.pause()
            this.audio = null
            if (this.canSpeak) window.speechSynthesis.cancel()
            this.speakingId = null
        },

        async speak(message) {
            const again = this.speakingId === message.id

            this.stopSpeaking()
            if (again) return

            this.speakingId = message.id

            // Unsaved answers (interrupted streams) still carry a local id: browser voice only.
            if (config.serverVoice && !/^(answer|local)-/.test(String(message.id))) {
                try {
                    const { 'Content-Type': _json, ...headers } = this.headers()
                    const response = await fetch(config.speechUrl.replace('__message__', encodeURIComponent(message.id)), {
                        method: 'POST', headers: { ...headers, Accept: 'audio/mpeg' }, credentials: 'same-origin',
                    })

                    if (!response.ok) throw new Error('speech')
                    if (this.speakingId !== message.id) return // stopped while loading

                    const audio = new Audio(URL.createObjectURL(await response.blob()))
                    audio.onended = audio.onerror = () => {
                        URL.revokeObjectURL(audio.src)
                        if (this.speakingId === message.id) this.speakingId = null
                    }

                    this.audio = audio
                    await audio.play()
                    return
                } catch {
                    if (this.speakingId !== message.id) return
                    // Server voice failed: the browser reads it instead.
                }
            }

            this.speakWithBrowser(message)
        },

        speakWithBrowser(message) {
            const synth = window.speechSynthesis
            const utterance = new SpeechSynthesisUtterance(plainText(message.content))
            const lang = this.speechLang()
            const voice = synth.getVoices().find((v) => v.name === this.browserVoice)
                || synth.getVoices().find((v) => v.lang === lang)
                || synth.getVoices().find((v) => v.lang.startsWith(lang.slice(0, 2)))

            utterance.lang = voice?.lang || lang
            if (voice) utterance.voice = voice
            utterance.onend = utterance.onerror = () => {
                if (this.speakingId === message.id) this.speakingId = null
            }

            this.speakingId = message.id
            synth.speak(utterance)
        },

        // ---- personal voice -------------------------------------------------

        openVoices() {
            this.voicePanel = true

            if (config.serverVoice || !window.speechSynthesis) return

            const load = () => {
                const lang = this.speechLang().slice(0, 2)
                const exact = this.speechLang().toLowerCase()
                this.browserVoices = window.speechSynthesis.getVoices()
                    .filter((v) => v.lang.toLowerCase().startsWith(lang))
                    .sort((a, b) => (b.lang.toLowerCase() === exact) - (a.lang.toLowerCase() === exact) || a.name.localeCompare(b.name))
                    .map((v) => ({ id: v.name, label: `${v.name} (${v.lang})` }))
            }

            load()
            window.speechSynthesis.onvoiceschanged = load
        },

        voiceOptions() {
            return config.serverVoice ? config.voices : this.browserVoices
        },

        currentVoice() {
            return config.serverVoice ? this.userVoice : this.browserVoice
        },

        // Server voices follow the user everywhere; browser voices exist only on this device.
        async chooseVoice(id) {
            if (!config.serverVoice) {
                this.browserVoice = id
                storage.set(BROWSER_VOICE_KEY, id)
                return
            }

            const previous = this.userVoice
            this.userVoice = id

            const response = await fetch(config.preferencesUrl, {
                method: 'POST', headers: this.headers(), credentials: 'same-origin', body: JSON.stringify({ voice: id }),
            }).catch(() => null)

            if (!response?.ok) {
                this.userVoice = previous
                this.notice = config.i18n.speechUnavailable
            }
        },

        async playSample(id) {
            this.stopSpeaking()

            if (!config.serverVoice) {
                const voice = window.speechSynthesis.getVoices().find((v) => v.name === id)
                const utterance = new SpeechSynthesisUtterance(config.i18n.voiceSample)
                if (voice) { utterance.voice = voice; utterance.lang = voice.lang }
                window.speechSynthesis.speak(utterance)
                return
            }

            const response = await fetch(config.sampleUrl, {
                method: 'POST', headers: { ...this.headers(), Accept: 'audio/mpeg' }, credentials: 'same-origin', body: JSON.stringify({ voice: id }),
            }).catch(() => null)

            if (!response?.ok) {
                this.notice = config.i18n.speechUnavailable
                return
            }

            this.audio = new Audio(URL.createObjectURL(await response.blob()))
            this.audio.play()
        },

        // The conversation as Markdown, visuals included, so it reads anywhere.
        exportConversation() {
            if (this.messages.length === 0) return

            const title = this.title || config.label || 'ChatAI'
            const parts = [`# ${title}`, `_${config.i18n.exportedOn} ${new Date().toLocaleString(config.locale || undefined)}_`]

            for (const message of this.messages) {
                if (message.role === 'user') {
                    parts.push(`**${config.i18n.you}:** ${message.content}`)
                    continue
                }

                const answer = [`**${config.label || 'ChatAI'}:** ${message.content || ''}`.trim()]

                for (const c of message.components || []) {
                    if (c.title) answer.push(`**${c.title}**`)

                    if (c.type === 'kpi') {
                        answer.push(c.items.map((item) => `- ${item.label}: ${this.formatKpi(item.value)}${item.unit ? ' ' + item.unit : ''}`
                            + (typeof item.change === 'number' ? ` (${item.change > 0 ? '+' : ''}${this.formatKpi(item.change)}%)` : '')
                            + (item.description ? ` — ${item.description}` : '')).join('\n'))
                    } else if (c.type === 'table') {
                        answer.push(mdTable(c.columns.map((col) => col.label), c.rows.map((row) => c.columns.map((col) => this.formatCell(row[col.key], col.format)))))
                    } else if (c.type === 'chart') {
                        answer.push(mdTable(['', ...c.series.map((serie) => serie.name || '')], c.labels.map((label, i) => [label, ...c.series.map((serie) => this.formatKpi(serie.data[i]))])))
                    }
                }

                parts.push(answer.join('\n\n'))
            }

            const slug = title.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60) || 'chatai'
            const link = document.createElement('a')

            link.href = URL.createObjectURL(new Blob([parts.join('\n\n') + '\n'], { type: 'text/markdown;charset=utf-8' }))
            link.download = `${slug}.md`
            link.click()
            setTimeout(() => URL.revokeObjectURL(link.href), 1000)
        },

        markdown(text) {
            return renderMarkdown(text)
        },

        formatCell(value, format) {
            if (value === null || value === undefined || value === '') return '—'

            const locale = config.locale || undefined

            if ((format === 'number' || format === 'money') && !isNaN(Number(value))) {
                return new Intl.NumberFormat(locale, {
                    minimumFractionDigits: format === 'money' ? 2 : 0,
                    maximumFractionDigits: 2,
                }).format(Number(value))
            }

            if (format === 'date') {
                const date = new Date(value)
                return isNaN(date) ? String(value) : date.toLocaleDateString(locale)
            }

            return String(value)
        },

        formatKpi(value) {
            // Models send KPI values as plain numbers ("28563.87"); keep anything else as written.
            if (typeof value === 'string' && !/^-?\d+(\.\d+)?$/.test(value.trim())) return value

            const number = Number(value)

            return isNaN(number)
                ? String(value)
                : new Intl.NumberFormat(config.locale || undefined, { maximumFractionDigits: 2 }).format(number)
        },

        formatWhen(iso) {
            if (!iso) return ''
            const date = new Date(iso)
            const today = new Date()
            return date.toDateString() === today.toDateString()
                ? date.toLocaleTimeString(config.locale || undefined, { hour: '2-digit', minute: '2-digit' })
                : date.toLocaleDateString(config.locale || undefined, { day: '2-digit', month: 'short' })
        },

        chartData(component) {
            const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16']
            const circular = ['pie', 'doughnut'].includes(component.chart_type)

            return {
                labels: component.labels,
                datasets: component.series.map((series, index) => ({
                    label: series.name,
                    data: series.data,
                    ...(circular
                        ? { backgroundColor: component.labels.map((_, i) => palette[i % palette.length]) }
                        : component.series.length > 1
                          ? {
                                borderColor: palette[index % palette.length],
                                backgroundColor: palette[index % palette.length] + '33',
                            }
                          : {}),
                })),
            }
        },

        chartOptions(component) {
            const circular = ['pie', 'doughnut'].includes(component.chart_type)
            return {
                plugins: { legend: { display: circular || component.series.length > 1 } },
                ...(circular ? { scales: { x: { display: false }, y: { display: false } } } : {}),
            }
        },
    }))
})
