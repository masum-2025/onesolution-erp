<?php

namespace Modules\Expenses\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Expenses\Exceptions\ExpensesException;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Models\Line;
use Modules\Expenses\Models\Receipt;

/**
 * Receipts of a claim: pictures (JPEG, PNG, WebP) and PDFs, the kind read
 * from the file itself, at most expenses.receipt_max_kb, on the private
 * disk; added and removed while the claim is a draft; shown only through
 * a link signed for a few minutes.
 */
class Receipts
{
    public const DISK = 'local';

    public const LINK_MINUTES = 5;

    private const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private Place $place, private RuleResolver $rules, private RuleContextFactory $contexts) {}

    public function add(Organization $company, Claim $claim, UploadedFile $file, ?string $lineId, User $actor): Receipt
    {
        if ($claim->status !== Claim::DRAFT) {
            throw ExpensesException::notDraft();
        }
        if ($lineId !== null && ! $this->place->query(Line::class, $company)->where('claim_id', $claim->getKey())->whereKey($lineId)->exists()) {
            throw ValidationException::withMessages(['line_id' => __('expenses::expenses.validation.line_not_found')]);
        }
        $maxKb = (int) $this->rules->get('expenses.receipt_max_kb', $this->contexts->forOrganization($company));
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => __('expenses::expenses.validation.receipt_size', ['max' => $maxKb])]);
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::MIME_TYPES, true)) {
            throw ValidationException::withMessages(['file' => __('expenses::expenses.validation.receipt_kind')]);
        }
        $path = $file->storeAs("expenses/{$company->getKey()}/{$claim->getKey()}", Str::ulid().'.'.($file->guessExtension() ?? 'bin'), self::DISK);
        $receipt = new Receipt;
        $receipt->fill([
            'organization_id' => $company->getKey(), 'claim_id' => $claim->getKey(), 'line_id' => $lineId,
            'name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'receipt', 140, ''),
            'mime' => $mime, 'size_bytes' => (int) $file->getSize(), 'path' => $path, 'uploaded_by' => $actor->getKey(),
        ])->save();

        return $receipt;
    }

    public function remove(Organization $company, Claim $claim, Receipt $receipt): void
    {
        if ($claim->status !== Claim::DRAFT) {
            throw ExpensesException::notDraft();
        }
        Storage::disk(self::DISK)->delete($receipt->path);
        $receipt->delete();
    }

    /** A link to see the receipt, for a few minutes. */
    public function url(Organization $company, Receipt $receipt): string
    {
        return URL::temporarySignedRoute('expenses.receipts.show', now()->addMinutes(self::LINK_MINUTES), [
            'organization' => $company->getKey(), 'receipt' => $receipt->getKey(),
        ], absolute: false);
    }
}
