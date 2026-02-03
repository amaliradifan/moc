<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetAtRiskCustomersRequest;
use App\Http\Resources\AtRiskCustomersResource;
use App\Services\Analytics\AtRiskCustomersService;
use Illuminate\Http\Request;

class AtRiskCustomersController extends Controller
{
    public function __construct(
        protected AtRiskCustomersService $service
    ) {}

    public function __invoke(GetAtRiskCustomersRequest $request, string $merchantId)
    {
        $data = $this->service->getAtRiskCustomers(
            merchantId: $merchantId,
            baselineDays: $request->validated('baseline_days'),
            compareDays: $request->validated('compare_days'),
        );

        return new AtRiskCustomersResource($data);
    }
}
