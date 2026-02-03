<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetMerchantAnalyticsRequest;
use App\Http\Resources\MerchantAnalyticsResource;
use App\Services\Analytics\MerchantAnalyticsService;
use Illuminate\Http\Request;

class MerchantAnalyticsController extends Controller
{
    public function __construct(
        protected MerchantAnalyticsService $analyticsService
    ) {}

    public function __invoke(GetMerchantAnalyticsRequest $request, string $merchantId)
    {
        $data = $this->analyticsService->getAnalytics(
            merchantId: $merchantId,
            startDate:  $request->validated('start_date'),
            endDate:    $request->validated('end_date'),
        );

        return new MerchantAnalyticsResource($data);
    }
}
