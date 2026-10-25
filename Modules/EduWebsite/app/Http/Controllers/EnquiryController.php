<?php

namespace Modules\EduWebsite\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Http\Controllers\Concerns\FindsSite;
use Modules\EduWebsite\Http\Requests\EnquiryRequest;
use Modules\EduWebsite\Http\WebsitePresenter;
use Modules\EduWebsite\Models\Enquiry;
use Modules\EduWebsite\Services\Enquiries;
use Modules\EduWebsite\Services\Place;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admission enquiries from the website, for the people who handle them
 * (edu_website.enquiries): the list by status (names are encrypted, so a
 * search looks through the recent ones), their status and note, and a CSV
 * of them (audited, a few a minute).
 */
class EnquiryController extends Controller
{
    use FindsSite;

    /** Enquiries listed and searched at most (the newest). */
    private const LIST_LIMIT = 500;

    public function __construct(private Enquiries $enquiries, private Place $place, private WebsitePresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.enquiries');
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', Enquiry::STATUSES)], 'q' => ['nullable', 'string', 'max:100']]);
        $rows = $this->rows($company, $filters['status'] ?? null, $filters['q'] ?? null);

        return response()->json([
            'data' => $rows->map(fn (Enquiry $enquiry) => $this->presenter->enquiry($enquiry))->values(),
            // How many of each status (four small counts).
            'meta' => ['counts' => collect(Enquiry::STATUSES)->mapWithKeys(fn (string $status) => [$status => $this->place->query(Enquiry::class, $company)->where('status', $status)->count()])],
        ]);
    }

    public function update(EnquiryRequest $request, string $organization, string $enquiry): JsonResponse
    {
        $company = $this->company($organization, 'edu_website.enquiries');
        $found = $this->place->query(Enquiry::class, $company)->whereKey($enquiry)->first() ?? throw WebsiteException::enquiryNotFound();

        return response()->json(['data' => $this->presenter->enquiry($this->enquiries->update($company, $found, $request->validated()))]);
    }

    /** The enquiries (of a status) as CSV; cells that a spreadsheet would run as formulas are written as text. */
    public function export(Request $request, string $organization, AuditLogger $audit): StreamedResponse
    {
        $company = $this->company($organization, 'edu_website.enquiries');
        $status = $request->validate(['status' => ['nullable', 'in:'.implode(',', Enquiry::STATUSES)]])['status'] ?? null;
        $rows = $this->rows($company, $status, null);
        $audit->record('edu_website.enquiries_exported', null, new: ['count' => $rows->count(), 'status' => $status], actor: $request->user(), organizationId: $company->getKey());
        $cell = fn (?string $value) => preg_match('/^[=+\-@\t\r]/', (string) $value) === 1 ? "'".$value : (string) $value;
        $locale = app()->getLocale();

        return response()->streamDownload(function () use ($rows, $cell, $locale) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn (string $key) => __("edu_website::website.enquiry_columns.{$key}"), ['date', 'student', 'guardian', 'phone', 'class', 'message', 'status', 'note']));
            foreach ($rows as $enquiry) {
                fputcsv($out, array_map($cell, [
                    $enquiry->created_at->toDateString(), $enquiry->student_name, $enquiry->guardian_name, $enquiry->phone,
                    $enquiry->level_name[$locale] ?? $enquiry->level_name['en'] ?? '', $enquiry->message, __("edu_website::website.enquiry_status.{$enquiry->status}"), $enquiry->note,
                ]));
            }
            fclose($out);
        }, 'enquiries.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Enquiry>
     */
    private function rows(Organization $company, ?string $status, ?string $search)
    {
        $rows = $this->place->query(Enquiry::class, $company)->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')->limit(self::LIST_LIMIT)->get();
        if ($search === null || trim($search) === '') {
            return $rows;
        }
        $needle = mb_strtolower(trim($search));
        $digits = preg_replace('/\D/', '', $needle);

        return $rows->filter(fn (Enquiry $enquiry) => str_contains(mb_strtolower($enquiry->student_name.' '.$enquiry->guardian_name), $needle)
            || ($digits !== '' && str_contains(preg_replace('/\D/', '', $enquiry->phone), $digits)))->values();
    }
}
