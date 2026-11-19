<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnsubscribeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isRule = $this->input('method') === 'rule';

        return [
            'method' => ['required', Rule::in(['one_click', 'mailto', 'page', 'rule'])],
            'rule_action' => [Rule::requiredIf($isRule), 'nullable', Rule::in(['archive', 'trash'])],
            'cleanup' => ['required', Rule::in($isRule ? ['keep', $this->input('rule_action')] : ['keep', 'archive', 'trash'])],
        ];
    }
}
