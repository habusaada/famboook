<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\SubmissionChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the Staff Change Request queue (PWA-5c). Allow-listed keys
 * only; the family is named by its public family_code, a request by its
 * CRQ code, the submitter by part of the name the queue shows, the channel
 * by its code — never an internal id. Authorization: the route's
 * change-request.view.
 */
class ChangeRequestIndexRequest extends FormRequest
{
    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ChangeRequestStatus::class)],
            'type' => ['sometimes', Rule::enum(ChangeRequestType::class)],
            'family' => ['sometimes', 'string', 'max:50'],
            // CO-02.7b: who submitted — the household or a Coordinator.
            'channel' => ['sometimes', Rule::enum(SubmissionChannel::class)],
            // CO-02.7b: part of the submitter's name, as the queue shows it.
            'submitter' => ['sometimes', 'string', 'min:2', 'max:100'],
            'request_code' => ['sometimes', 'string', 'regex:/^CRQ-[0-9]{6,12}$/'],
            'submitted_from' => ['sometimes', 'date_format:Y-m-d'],
            'submitted_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:submitted_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.enum' => 'حالة الطلب غير صالحة.',
            'type.enum' => 'نوع الطلب غير صالح.',
            'channel.enum' => 'جهة تقديم الطلب غير صالحة.',
            'submitter.min' => 'اكتب حرفين على الأقل من اسم مقدّم الطلب.',
            'submitter.max' => 'اسم مقدّم الطلب طويل جدًا.',
            'request_code.regex' => 'رقم الطلب غير صالح.',
            'submitted_from.date_format' => 'تاريخ البداية غير صالح.',
            'submitted_to.date_format' => 'تاريخ النهاية غير صالح.',
            'submitted_to.after_or_equal' => 'تاريخ النهاية قبل تاريخ البداية.',
            'per_page.max' => 'عدد العناصر في الصفحة أكبر من المسموح.',
        ];
    }
}
