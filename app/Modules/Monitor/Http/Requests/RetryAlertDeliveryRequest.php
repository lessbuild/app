<?php

namespace App\Modules\Monitor\Http\Requests;

class RetryAlertDeliveryRequest extends SearchAlertDeliveriesRequest
{
    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : $this->request->all();
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['generation' => ['required', 'integer', 'min:0'], 'confirm' => ['accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['confirm.accepted' => 'Confirm that you reviewed the previous attempt and understand a retry may create a duplicate.'];
    }
}
