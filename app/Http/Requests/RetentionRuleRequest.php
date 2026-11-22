<?php

namespace App\Http\Requests;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\RetentionRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RetentionRuleRequest extends FormRequest
{
    private const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('filter_type'), ['domain', 'sender'], true)) {
            $this->merge(['filter_value' => Str::lower(trim((string) $this->input('filter_value')))]);
        }

        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $filterValue = match ($this->input('filter_type')) {
            'category' => [Rule::in(array_keys(RetentionRule::CATEGORIES))],
            'domain' => ['regex:'.self::DOMAIN_PATTERN, 'max:253'],
            'sender' => ['email', 'max:255'],
            default => ['max:255'],
        };

        return [
            'email_account_id' => ['required', 'integer', Rule::exists('email_accounts', 'id')
                ->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'filter_type' => ['required', Rule::in(RetentionRule::FILTER_TYPES)],
            'filter_value' => ['required', 'string', ...$filterValue],
            'retention_days' => ['required', 'integer', 'between:0,3650'],
            'action' => ['required', Rule::in(RetentionRule::ACTIONS)],
            'target_label' => [Rule::requiredIf($this->input('action') === 'add_label'), 'nullable', 'string', 'max:255'],
            'archive_after_label' => ['boolean'],
        ];
    }

    /**
     * Label ids must exist among the selected account's own Gmail labels.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fields = array_filter([
                'filter_value' => $this->input('filter_type') === 'label' ? $this->input('filter_value') : null,
                'target_label' => $this->input('action') === 'add_label' ? $this->input('target_label') : null,
            ]);

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
            'filter_type' => $this->string('filter_type')->toString(),
            'filter_value' => $this->string('filter_value')->toString(),
            'retention_days' => $this->integer('retention_days'),
            'action' => $this->string('action')->toString(),
            'target_label' => $this->input('action') === 'add_label' ? $this->string('target_label')->toString() : null,
            'archive_after_label' => $this->input('action') === 'add_label' && $this->boolean('archive_after_label'),
        ];
    }
}
