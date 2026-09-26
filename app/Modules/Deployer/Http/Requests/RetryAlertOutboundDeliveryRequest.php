<?php

namespace App\Modules\Deployer\Http\Requests;

use App\Modules\Deployer\Models\AlertOutboundDelivery;
use Illuminate\Foundation\Http\FormRequest;

class RetryAlertOutboundDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('delivery');

        return $this->user() !== null && $delivery instanceof AlertOutboundDelivery
            && $this->user()->can('retry', $delivery);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['confirm' => ['accepted']];
    }
}
