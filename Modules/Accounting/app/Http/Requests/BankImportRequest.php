<?php

namespace Modules\Accounting\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;
use Modules\Accounting\Models\BankFormat;

/**
 * A statement file (CSV) and which of its columns hold what: the date and
 * either one signed amount or money in and money out; description and
 * reference when the file has them. Size and rows are the company's rules.
 */
class BankImportRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $column = ['nullable', 'string', 'max:100'];

        return [
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'columns' => ['required', 'array:date,description,reference,amount,money_in,money_out'],
            'columns.date' => ['required', 'string', 'max:100'],
            'columns.description' => $column,
            'columns.reference' => $column,
            'columns.amount' => [...$column, 'required_without_all:columns.money_in,columns.money_out', 'prohibits:columns.money_in,columns.money_out'],
            'columns.money_in' => $column,
            'columns.money_out' => $column,
            'date_format' => ['required', 'string', 'in:'.implode(',', BankFormat::DATE_FORMATS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => __('accounting::accounting.bank.file_type'),
            'file.mimetypes' => __('accounting::accounting.bank.file_type'),
            'columns.amount.required_without_all' => __('accounting::accounting.bank.amount_columns'),
        ];
    }
}
