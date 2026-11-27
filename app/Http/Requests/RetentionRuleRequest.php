<?php

namespace App\Http\Requests;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\RetentionRule;
use App\Services\Providers\GmailSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RetentionRuleRequest extends FormRequest
{
    private const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    /** Gmail can't escape a quote inside a quoted phrase, so these could end the subject term early. */
    private const SUBJECT_PATTERN = '/^[^"(){}]+$/u';

    private const MAX_CONDITIONS = 20;

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);

        if (! is_array($this->input('conditions'))) {
            return;
        }

        $this->merge(['conditions' => array_map(function (mixed $condition) {
            if (! is_array($condition) || ! is_string($condition['value'] ?? null)) {
                return $condition;
            }

            $value = trim($condition['value']);

            return [...$condition, 'value' => in_array($condition['type'] ?? null, ['domain', 'sender'], true) ? Str::lower($value) : $value];
        }, $this->input('conditions'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email_account_id' => $this->forAllAccounts()
                ? ['required', function (string $attribute, mixed $value, \Closure $fail) {
                    if ($this->usesLabels()) {
                        $fail('Marcadores são de cada conta: escolha uma conta para usar marcadores.');
                    }
                }]
                : ['required', 'integer', Rule::exists('email_accounts', 'id')
                    ->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'conditions' => ['required', 'array', 'min:1', 'max:'.self::MAX_CONDITIONS],
            'conditions.*' => ['required', 'array:type,value'],
            'conditions.*.type' => ['required', Rule::in(RetentionRule::CONDITION_TYPES)],
            ...$this->conditionValueRules(),
            'retention_days' => ['required', 'integer', 'between:0,3650'],
            'action' => ['required', Rule::in(RetentionRule::ACTIONS)],
            'target_label' => [Rule::requiredIf($this->input('action') === 'add_label'), 'nullable', 'string', 'max:255'],
            'archive_after_label' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['conditions.*.value.regex' => 'O assunto não pode ter aspas, parênteses ou chaves.'];
    }

    /**
     * "All accounts" is only offered when creating: it makes one copy per account.
     */
    public function forAllAccounts(): bool
    {
        return $this->input('email_account_id') === 'all' && $this->isMethod('post');
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkConditionList($validator);
            $this->checkManagedRule($validator);

            if ($validator->errors()->isEmpty()) {
                $this->checkLabelsBelongToAccount($validator);
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        return [
            'email_account_id' => $this->integer('email_account_id'),
            'name' => $this->string('name')->toString(),
            'retention_days' => $this->integer('retention_days'),
            'action' => $this->string('action')->toString(),
            'target_label' => $this->input('action') === 'add_label' ? $this->string('target_label')->toString() : null,
            'archive_after_label' => $this->input('action') === 'add_label' && $this->boolean('archive_after_label'),
        ];
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    public function ruleConditions(): array
    {
        return array_map(fn (array $condition) => ['type' => $condition['type'], 'value' => $condition['value']], array_values($this->validated('conditions')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function conditionValueRules(): array
    {
        $rules = [];

        foreach ($this->conditionInput() as $index => $condition) {
            $rules["conditions.{$index}.value"] = ['required', 'string', ...match ($condition['type'] ?? null) {
                'category' => [Rule::in(array_keys(RetentionRule::CATEGORIES))],
                'domain' => ['regex:'.self::DOMAIN_PATTERN, 'max:253'],
                'sender' => ['email', 'max:255', 'not_regex:'.GmailSearch::UNSAFE_ADDRESS_PATTERN],
                'subject' => ['max:100', 'regex:'.self::SUBJECT_PATTERN],
                default => ['max:255'],
            }];
        }

        return $rules;
    }

    private function checkConditionList(Validator $validator): void
    {
        $pairs = array_map(fn (array $condition) => $condition['type'].'|'.$condition['value'], $this->conditionInput());

        if (count($pairs) !== count(array_unique($pairs))) {
            $validator->errors()->add('conditions', 'Há condições repetidas.');
        }

        if (count(array_filter($this->conditionInput(), fn (array $condition) => $condition['type'] === 'label')) > 1) {
            $validator->errors()->add('conditions', 'Use no máximo um marcador por regra.');
        }
    }

    /**
     * Rules created by the Senders page or the Hub are found there by their single
     * sender condition, so the Rules page may only change their name, age and action.
     */
    private function checkManagedRule(Validator $validator): void
    {
        $rule = $this->isMethod('put') ? $this->existingRule() : null;

        if ($rule === null || ! $rule->isManaged()) {
            return;
        }

        $sorted = fn (array $conditions) => collect($conditions)->map(fn (array $c) => $c['type'].'|'.$c['value'])->sort()->values()->all();

        if ($sorted($this->conditionInput()) !== $sorted($rule->conditions->map->only(['type', 'value'])->all())) {
            $validator->errors()->add('conditions', $rule->kind === 'hub_block'
                ? 'As condições desta regra são gerenciadas pelo Hub.'
                : 'As condições desta regra são gerenciadas pela página Remetentes.');
        }

        if ($this->integer('email_account_id') !== $rule->email_account_id) {
            $validator->errors()->add('email_account_id', 'A conta desta regra não pode mudar.');
        }

        if ($rule->kind === 'sender_category' && $this->input('action') !== 'add_label') {
            $validator->errors()->add('action', 'Uma regra de categoria só pode aplicar marcador.');
        } elseif ($rule->kind !== 'sender_category' && $this->input('action') === 'add_label') {
            $validator->errors()->add('action', 'Esta regra só pode arquivar ou mandar para a lixeira.');
        }
    }

    /**
     * Label ids must exist among the selected account's own Gmail labels.
     */
    private function checkLabelsBelongToAccount(Validator $validator): void
    {
        $fields = [];

        foreach ($this->conditionInput() as $index => $condition) {
            if ($condition['type'] === 'label') {
                $fields["conditions.{$index}.value"] = $condition['value'];
            }
        }

        if ($this->input('action') === 'add_label') {
            $fields['target_label'] = $this->input('target_label');
        }

        if ($fields === []) {
            return;
        }

        $account = $this->user()->emailAccounts()->findOrFail($this->integer('email_account_id'));

        try {
            $labelIds = array_column(app(EmailProviderInterface::class)->listLabels($account), 'id');
        } catch (ProviderUnavailable|ReauthorizationRequired) {
            $validator->errors()->add(array_key_first($fields), 'Não foi possível carregar os marcadores do Gmail. Tente de novo.');

            return;
        }

        foreach ($fields as $field => $labelId) {
            if (! in_array($labelId, $labelIds, true)) {
                $validator->errors()->add($field, 'Escolha um marcador desta conta.');
            }
        }
    }

    private function usesLabels(): bool
    {
        return $this->input('action') === 'add_label'
            || in_array('label', array_column($this->conditionInput(), 'type'), true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conditionInput(): array
    {
        $conditions = $this->input('conditions');

        return is_array($conditions) ? array_filter($conditions, 'is_array') : [];
    }

    private function existingRule(): ?RetentionRule
    {
        return RetentionRule::whereIn('email_account_id', $this->user()->emailAccounts()->select('email_accounts.id'))
            ->with('conditions')
            ->find($this->route('rule'));
    }
}
