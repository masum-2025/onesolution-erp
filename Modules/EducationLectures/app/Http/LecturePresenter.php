<?php

namespace Modules\EducationLectures\Http;

use Illuminate\Support\Collection;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Models\LectureFile;
use Modules\EducationLectures\Services\Lectures;

/**
 * Lectures as the screens read them. Files carry a link that opens them
 * for a few minutes; a withdrawn lecture's files are not offered.
 */
class LecturePresenter
{
    public function __construct(private Lectures $lectures) {}

    /**
     * @param  Collection<int, LectureFile>  $files
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function lecture(Lecture $lecture, Collection $files, array $extra = []): array
    {
        return [
            'id' => $lecture->getKey(),
            ...$lecture->only(['unit_id', 'session_id', 'section_id', 'subject_id', 'teacher_id', 'posted_by', 'title', 'body', 'video_url', 'status', 'withdrawn_reason', 'version']),
            'lesson_on' => $lecture->lesson_on->toDateString(),
            'published_at' => $lecture->published_at?->toIso8601String(),
            'files' => $lecture->status === 'withdrawn' ? [] : $files->map(fn (LectureFile $file) => [
                'id' => $file->getKey(), 'name' => $file->name, 'mime' => $file->mime, 'size_bytes' => $file->size_bytes, 'url' => $this->lectures->link($file),
            ])->values()->all(),
            ...$extra,
        ];
    }
}
