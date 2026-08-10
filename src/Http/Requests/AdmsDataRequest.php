<?php

namespace TanemRahman\ZktecoAdms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdmsDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function tableName(): string
    {
        return strtoupper((string) $this->query('table', ''));
    }

    public function stamp(): ?string
    {
        return $this->query('Stamp') !== null
            ? (string) $this->query('Stamp')
            : ($this->query('stamp') !== null ? (string) $this->query('stamp') : null);
    }
}
