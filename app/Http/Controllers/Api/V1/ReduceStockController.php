<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReduceStockRequest;
use App\Http\Resources\ReduceStockResource;
use App\Services\Stock\StockReduceService;
use Illuminate\Http\JsonResponse;

class ReduceStockController extends Controller
{
    public function __construct(
        protected StockReduceService $stockService
    ) {}

    public function __invoke(ReduceStockRequest $request, string $productId): JsonResponse
    {
        try {
            $result = $this->stockService->reduce(
                productId: $productId,
                quantity:  $request->validated('quantity'),
            );

            return (new ReduceStockResource($result))->response()->setStatusCode(200);

        } catch (InsufficientStockException $e) {
            return (new ReduceStockResource($e->getPayload()))->response()->setStatusCode(422);
        }
    }
}
