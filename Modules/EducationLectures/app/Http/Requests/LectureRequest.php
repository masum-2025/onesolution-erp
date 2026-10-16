<?php

namespace Modules\EducationLectures\Http\Requests;

use App\Platform\Support\Http\StrictFormRequest;

/**
 * Posting or changing a lecture. The section and subject are fixed once
 * posted; who may post for them, the subject being taught there and the
 * video site are checked by Lectures.
 */
class LectureRequest extends StrictFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'section_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'subject_id' => [$creating ? 'required' : 'prohibited', 'string', 'size:26'],
            'lesson_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:3', 'max:150'],
            'body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'video_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url:https'],
            'op_id' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'base_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
