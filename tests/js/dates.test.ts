import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDate, timeAgo } from '../../resources/js/lib/dates.ts';

const now = new Date('2026-11-22T12:00:00Z');
const ago = (ms: number) => new Date(now.getTime() - ms).toISOString();
const MINUTE = 60_000;

test('formatDate turns ISO dates and datetimes into dd/mm/yyyy', () => {
    assert.equal(formatDate('2024-03-12'), '12/03/2024');
    assert.equal(formatDate('2026-11-22T23:59:00+00:00'), '22/11/2026');
});

test('timeAgo boundaries', () => {
    assert.equal(timeAgo(ago(30_000), now), 'agora');
    assert.equal(timeAgo(ago(MINUTE), now), 'há 1 min');
    assert.equal(timeAgo(ago(59 * MINUTE), now), 'há 59 min');
    assert.equal(timeAgo(ago(60 * MINUTE), now), 'há 1 h');
    assert.equal(timeAgo(ago(23 * 60 * MINUTE + 59 * MINUTE), now), 'há 23 h');
    assert.equal(timeAgo(ago(24 * 60 * MINUTE), now), 'há 1 dia');
    assert.equal(timeAgo(ago(47 * 60 * MINUTE), now), 'há 1 dia');
    assert.equal(timeAgo(ago(48 * 60 * MINUTE), now), 'há 2 dias');
});

test('timeAgo ignores the offset of the given timestamp', () => {
    assert.equal(timeAgo('2026-11-22T08:00:00-03:00', now), 'há 1 h');
});

test('future timestamps read as now', () => {
    assert.equal(timeAgo(new Date(now.getTime() + 5 * MINUTE).toISOString(), now), 'agora');
});
