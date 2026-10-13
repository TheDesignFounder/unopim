const test = require('node:test');
const assert = require('node:assert/strict');
const {widget} = require('./widget-harness.cjs');

const descriptor = {type: 'example.lookup', id: 'saved-result'};
const result = {presentation: descriptor, result: {status: 'ready'}};
const frame = (type, data) => `event: ${type}\ndata: ${JSON.stringify(data)}\n\n`;

for (const ending of ['complete', 'observer-error', 'connection-error']) {
    test(`completed tool result survives ${ending} and is included in saved history`, async () => {
        const {widget: w} = widget([], {TextDecoder});
        let saved;
        w.activeSessionId = 'history-fixture';
        w.scrollBottom = w.persistSessions = () => {};
        w.$axios = {post: async (url, payload) => { saved = JSON.parse(JSON.stringify(payload)); return {data: {id: 1}}; }};
        let reads = 0;
        const response = {body: {getReader: () => ({read: async () => {
            if (reads++ === 0) return {done: false, value: new TextEncoder().encode(frame('tool_result', result))};
            if (ending === 'connection-error') throw Error('Connection closed after the tool returned');
            if (reads === 2) return {done: false, value: new TextEncoder().encode(ending === 'complete'
                ? frame('complete', {action: 'agent_response'})
                : frame('error', {message: 'A completion observer failed.'}))};
            return {done: true};
        }})}};
        await w.processStream(response, []);
        await w.saveCurrentSession();
        assert.deepEqual(saved.messages[0].presentation, descriptor);
        const reopened = widget().widget.mergeHistory(saved.messages);
        assert.deepEqual(JSON.parse(JSON.stringify(reopened[0].presentation)), descriptor);
        if (ending === 'observer-error') assert.equal(reopened[0].content, 'A completion observer failed.');
    });
}

test('an error before any completed tool does not invent a result or redirect', async () => {
    const {widget: w} = widget([], {TextDecoder});
    w.scrollBottom = () => {};
    w.shouldAutoRefreshAfterAction = () => { throw Error('Failed streams must not refresh the page'); };
    await w.processStream(new Response(frame('error', {message: 'No result was produced.'})), []);
    assert.equal(w.messages[0].presentation, null);
    assert.equal(w.messages[0].content, 'No result was produced.');
});
