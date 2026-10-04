<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\TaxCodeRequest;
use Modules\Accounting\Models\TaxCode;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\TaxCodes;

/**
 * The company's tax codes: everyone who reads Accounting sees them (forms
 * offer them); accounting.tax adds and changes them.
 */
class TaxCodeController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private TaxCodes $codes) {}

    public function index(string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        return response()->json(['data' => $this->books->query(TaxCode::class, $company)->orderByDesc('rate_bp')->orderBy('code')->get()
            ->map(fn (TaxCode $code) => self::present($code))->values()]);
    }

    public function store(TaxCodeRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.tax', $company);
        $this->books->assertSetUp($company);

        return response()->json(['data' => self::present($this->codes->create($company, $request->validated(), $request->user()))], 201);
    }

    public function update(TaxCodeRequest $request, string $organization, string $code): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.tax', $company);
        $found = $this->books->query(TaxCode::class, $company)->whereKey($code)->first() ?? throw AccountingException::taxCodeNotFound();

        $data = $request->validated();
        $updated = $this->codes->update($company, $found, (int) $data['base_version'], array_diff_key($data, ['base_version' => true]), $request->user());

        return response()->json(['data' => self::present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(TaxCode $code): array
    {
        return [
            'id' => $code->getKey(),
            'code' => $code->code,
            'name' => $code->name,
            'names' => $code->texts('name'),
            'rate_bp' => $code->rate_bp,
            'kind' => $code->kind,
            'applies_to' => $code->applies_to,
            'is_active' => $code->is_active,
            'version' => $code->version,
        ];
    }
}
