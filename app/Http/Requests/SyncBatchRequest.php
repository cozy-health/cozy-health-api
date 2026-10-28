<?php
namespace App\Http\Requests;
class SyncBatchRequest extends ApiFormRequest
{
    public function rules(): array
    {
        // Reject oversized requests before any operation is committed. Individual
        // operation errors still belong in the results envelope.
        return ['operations' => ['sometimes', 'array', 'max:100']];
    }
}
