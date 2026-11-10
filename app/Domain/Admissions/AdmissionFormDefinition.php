<?php

namespace App\Domain\Admissions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdmissionFormDefinition
{
    private const FIELD_TYPES = [
        'short_text', 'long_text', 'email', 'phone', 'number', 'date', 'date_range',
        'single_select', 'multi_select', 'radio', 'checkbox', 'consent',
        'address_group', 'previous_education', 'guardian_group', 'file_upload',
    ];

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function normalize(array $definition): array
    {
        $validator = Validator::make($definition, [
            'title' => ['required', 'string', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'sections' => ['required', 'array', 'min:1', 'max:12'],
            'sections.*' => ['required', 'array:key,title,fields'],
            'sections.*.key' => ['required', 'string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'],
            'sections.*.title' => ['required', 'string', 'max:120'],
            'sections.*.fields' => ['required', 'array', 'min:1', 'max:40'],
            'sections.*.fields.*' => ['required', 'array:key,type,label,help,required,min_length,max_length,options,visible_when,children,document_requirement_code'],
            'sections.*.fields.*.key' => ['required', 'string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'],
            'sections.*.fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'sections.*.fields.*.label' => ['required', 'string', 'max:160'],
            'sections.*.fields.*.help' => ['nullable', 'string', 'max:600'],
            'sections.*.fields.*.required' => ['sometimes', 'boolean'],
            'sections.*.fields.*.min_length' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'sections.*.fields.*.max_length' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'sections.*.fields.*.options' => ['sometimes', 'array', 'max:100'],
            'sections.*.fields.*.options.*' => ['required', 'array:value,label'],
            'sections.*.fields.*.options.*.value' => ['required_with:sections.*.fields.*.options', 'string', 'max:120'],
            'sections.*.fields.*.options.*.label' => ['required_with:sections.*.fields.*.options', 'string', 'max:160'],
            'sections.*.fields.*.visible_when' => ['sometimes', 'array:field,operator,value'],
            'sections.*.fields.*.visible_when.field' => ['required_with:sections.*.fields.*.visible_when', 'string', 'max:48'],
            'sections.*.fields.*.visible_when.operator' => ['required_with:sections.*.fields.*.visible_when', Rule::in(['equals', 'not_equals', 'contains'])],
            'sections.*.fields.*.visible_when.value' => ['present_with:sections.*.fields.*.visible_when', 'nullable', 'string', 'max:160'],
            'sections.*.fields.*.children' => ['sometimes', 'array', 'max:12'],
            'sections.*.fields.*.children.*' => ['required', 'array:key,label,type,required'],
            'sections.*.fields.*.children.*.key' => ['required_with:sections.*.fields.*.children', 'string', 'max:48', 'regex:/^[a-z][a-z0-9_]*$/'],
            'sections.*.fields.*.children.*.label' => ['required_with:sections.*.fields.*.children', 'string', 'max:160'],
            'sections.*.fields.*.children.*.type' => ['required_with:sections.*.fields.*.children', Rule::in(['short_text', 'long_text', 'email', 'phone', 'number', 'date', 'single_select'])],
            'sections.*.fields.*.children.*.required' => ['sometimes', 'boolean'],
            'sections.*.fields.*.document_requirement_code' => ['sometimes', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);
        $data = $validator->validate();
        if (array_diff(array_keys($definition), ['title', 'instructions', 'sections']) !== []) {
            throw ValidationException::withMessages(['definition' => 'The form contains unsupported top-level properties.']);
        }
        $fieldKeys = [];
        $sectionKeys = [];
        $fieldDefinitions = [];
        foreach ($data['sections'] as $section) {
            if (isset($sectionKeys[$section['key']])) {
                throw ValidationException::withMessages(['sections' => 'Section keys must be unique.']);
            }
            $sectionKeys[$section['key']] = true;
            foreach ($section['fields'] as $field) {
                if (isset($fieldKeys[$field['key']])) {
                    throw ValidationException::withMessages(['sections' => 'Field keys must be unique across the complete form.']);
                }
                $fieldKeys[$field['key']] = true;
                $fieldDefinitions[$field['key']] = $field;
                if (in_array($field['type'], ['single_select', 'multi_select', 'radio'], true) && empty($field['options'])) {
                    throw ValidationException::withMessages(['sections' => "The {$field['label']} field needs at least one option."]);
                }
                if (isset($field['min_length'], $field['max_length']) && $field['min_length'] > $field['max_length']) {
                    throw ValidationException::withMessages(['sections' => "The {$field['label']} minimum length exceeds its maximum."]);
                }
                if ($field['type'] === 'file_upload' && empty($field['document_requirement_code'])) {
                    throw ValidationException::withMessages(['sections' => "The {$field['label']} upload field must reference a document requirement."]);
                }
                if ($field['type'] !== 'file_upload' && isset($field['document_requirement_code'])) {
                    throw ValidationException::withMessages(['sections' => 'Only upload fields can reference document requirements.']);
                }
                if (isset($field['options'])) {
                    $optionValues = array_column($field['options'], 'value');
                    if (count($optionValues) !== count(array_unique($optionValues))) {
                        throw ValidationException::withMessages(['sections' => "The {$field['label']} field has duplicate option values."]);
                    }
                }
            }
        }
        $availableBefore = [];
        foreach ($data['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if (isset($field['visible_when'])) {
                    $condition = $field['visible_when'];
                    $sourceKey = $condition['field'];
                    if (! isset($availableBefore[$sourceKey]) || isset($fieldDefinitions[$sourceKey]['visible_when'])) {
                        throw ValidationException::withMessages(['sections' => 'A conditional rule must reference an earlier, unconditional field.']);
                    }
                    if ($condition['operator'] === 'contains' && $fieldDefinitions[$sourceKey]['type'] !== 'multi_select') {
                        throw ValidationException::withMessages(['sections' => 'The contains operator can only reference a multi-select field.']);
                    }
                    if (
                        in_array($fieldDefinitions[$sourceKey]['type'], ['single_select', 'radio', 'multi_select'], true)
                        && ! in_array($condition['value'] ?? null, array_column($fieldDefinitions[$sourceKey]['options'] ?? [], 'value'), true)
                    ) {
                        throw ValidationException::withMessages(['sections' => 'The conditional value must be one of the referenced field options.']);
                    }
                }
                $availableBefore[$field['key']] = true;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function validateAnswers(array $answers, array $definition, bool $complete = true): array
    {
        $fieldMap = [];
        foreach (($definition['sections'] ?? []) as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                if (($field['type'] ?? null) === 'file_upload') {
                    continue;
                }
                if (! $this->visible($field, $answers)) {
                    continue;
                }
                $fieldMap[$field['key']] = $field;
            }
        }
        $unknown = array_diff(array_keys($answers), array_keys($fieldMap));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['answers' => 'Answers contain unknown or conditionally hidden fields.']);
        }

        $rules = [];
        foreach ($fieldMap as $key => $field) {
            $required = $complete && (bool) ($field['required'] ?? false);
            $rules[$key] = [$required ? 'required' : 'nullable'];
            $rules[$key] = [...$rules[$key], ...$this->fieldRules($field)];
            if ($field['type'] === 'multi_select') {
                $rules[$key.'.*'] = [Rule::in(array_column($field['options'] ?? [], 'value'))];
            } elseif ($field['type'] === 'date_range') {
                $rules[$key.'.from'] = [$required ? 'required' : 'nullable', 'date'];
                $rules[$key.'.to'] = [$required ? 'required' : 'nullable', 'date'];
            } elseif ($field['type'] === 'address_group') {
                foreach (['street' => '180', 'city' => '120', 'region' => '120', 'postal_code' => '32'] as $child => $limit) {
                    $rules[$key.'.'.$child] = ['nullable', 'string', 'max:'.$limit];
                }
                $rules[$key.'.country'] = ['nullable', 'string', 'size:2'];
            } elseif ($field['type'] === 'guardian_group') {
                $rules[$key.'.name'] = [$required ? 'required' : 'nullable', 'string', 'max:180'];
                $rules[$key.'.email'] = [$required ? 'required' : 'nullable', 'email:rfc', 'max:254'];
                $rules[$key.'.relationship'] = ['nullable', 'string', 'max:48'];
            } elseif ($field['type'] === 'previous_education') {
                $rules[$key] = [...$rules[$key], 'max:10'];
                foreach (['institution' => '180', 'level' => '120', 'completed_on' => '24', 'result' => '80'] as $child => $limit) {
                    $rules[$key.'.*.'.$child] = [$required ? 'required' : 'nullable', 'string', 'max:'.$limit];
                }
            }
        }
        $validator = Validator::make($answers, $rules);
        $validator->after(function ($validator) use ($fieldMap, $answers): void {
            foreach ($fieldMap as $key => $field) {
                if ($field['type'] !== 'date_range') {
                    continue;
                }
                $range = $answers[$key] ?? null;
                if (is_array($range) && filled($range['from'] ?? null) && filled($range['to'] ?? null) && strtotime((string) $range['to']) < strtotime((string) $range['from'])) {
                    $validator->errors()->add($key.'.to', 'The end date must be on or after the start date.');
                }
            }
        });
        $validated = $validator->validate();

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function documentRequirementCodes(array $definition): array
    {
        $codes = [];
        foreach ($definition['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) === 'file_upload' && is_string($field['document_requirement_code'] ?? null)) {
                    $codes[] = $field['document_requirement_code'];
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public function requiredDocumentRequirementCodes(array $definition, array $answers): array
    {
        $codes = [];
        foreach ($definition['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (($field['type'] ?? null) === 'file_upload' && ($field['required'] ?? false) && $this->visible($field, $answers) && is_string($field['document_requirement_code'] ?? null)) {
                    $codes[] = $field['document_requirement_code'];
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<mixed>
     */
    private function fieldRules(array $field): array
    {
        $type = $field['type'];
        $min = $field['min_length'] ?? 0;
        $max = $field['max_length'] ?? 1000;
        $rules = match ($type) {
            'short_text' => ['string', 'min:'.$min, 'max:'.min($max, 500)],
            'long_text' => ['string', 'min:'.$min, 'max:'.$max],
            'email' => ['email:rfc', 'max:254'],
            'phone' => ['string', 'max:40', 'regex:/^[+0-9(). -]{4,40}$/'],
            'number' => ['numeric', 'min:0', 'max:1000000000'],
            'date' => ['date_format:Y-m-d'],
            'date_range' => ['array:from,to'],
            'single_select', 'radio' => ['string', Rule::in(array_column($field['options'] ?? [], 'value'))],
            'multi_select' => ['array', 'max:100'],
            'checkbox', 'consent' => [$type === 'consent' ? 'accepted' : 'boolean'],
            'address_group' => ['array:street,city,region,postal_code,country'],
            'previous_education' => ['array', 'max:10'],
            'guardian_group' => ['array:name,email,relationship'],
            'file_upload' => ['string', 'max:100'],
            default => throw ValidationException::withMessages(['answers' => 'The published form contains an unsupported field type.']),
        };

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $answers
     */
    private function visible(array $field, array $answers): bool
    {
        $condition = $field['visible_when'] ?? null;
        if (! is_array($condition)) {
            return true;
        }
        $actual = $answers[$condition['field']] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? '') {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'contains' => is_array($actual) && in_array($expected, $actual, true),
            default => false,
        };
    }
}
