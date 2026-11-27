import { test } from 'node:test';
import assert from 'node:assert/strict';
import { describeConditions } from '../../resources/js/lib/rules.ts';

test('different condition types are joined with a separator', () => {
    assert.equal(
        describeConditions([
            { type: 'subject', value: 'Alerta de segurança' },
            { type: 'sender', value: 'no-reply@accounts.google.com' },
        ]),
        'Remetente: no-reply@accounts.google.com · Assunto: "Alerta de segurança"',
    );
});

test('values of the same type are alternatives', () => {
    assert.equal(describeConditions([{ type: 'subject', value: 'Bem-vindo' }, { type: 'subject', value: 'Welcome' }]), 'Assunto: "Bem-vindo" ou "Welcome"');
    assert.equal(describeConditions([{ type: 'category', value: 'promotions' }, { type: 'category', value: 'updates' }]), 'Categoria: Promoções ou Atualizações');
    assert.equal(describeConditions([{ type: 'domain', value: 'a.com' }, { type: 'domain', value: 'b.com' }]), 'Domínio: a.com ou b.com');
});

test('labels show their name, or the id when the name is unknown', () => {
    assert.equal(describeConditions([{ type: 'label', value: 'Label_1', label: 'Banco' }]), 'Marcador: Banco');
    assert.equal(describeConditions([{ type: 'label', value: 'Label_1', label: null }]), 'Marcador: Label_1');
});
