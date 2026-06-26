<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPatrolMonitoring;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlockchainRecordResource;
use App\Http\Resources\BlockchainVerificationResource;
use App\Models\BlockchainRecord;
use App\Services\Blockchain\BlockchainRecordService;
use App\Services\Blockchain\BlockchainSubmittedRecordRefreshService;
use App\Services\Blockchain\BlockchainVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class BlockchainRecordController extends Controller
{
    use AuthorizesPatrolMonitoring;

    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorizePatrolMonitoring();
        $validated = request()->validate([
            'status' => ['nullable', Rule::in(['pending', 'queued', 'processing', 'submitted', 'confirmed', 'failed'])],
            'network' => ['nullable', Rule::in(['ganache', 'sepolia'])],
            'environment' => ['nullable', Rule::in(['local', 'staging', 'production'])],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'confirmed_at', 'block_number'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->buildFilteredQuery($validated);

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $perPage = $validated['per_page'] ?? 15;

        return BlockchainRecordResource::collection(
            $query->orderBy($sortBy, $sortOrder)->paginate($perPage)->withQueryString()
        );
    }

    public function summary(): JsonResponse
    {
        $this->authorizePatrolMonitoring();

        $networkCounts = BlockchainRecord::query()
            ->selectRaw('network, COUNT(*) as count')
            ->groupBy('network')
            ->orderBy('network')
            ->get()
            ->map(fn ($row) => [
                'network' => $row->network,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        $environmentCounts = BlockchainRecord::query()
            ->selectRaw('environment, COUNT(*) as count')
            ->groupBy('environment')
            ->orderBy('environment')
            ->get()
            ->map(fn ($row) => [
                'environment' => $row->environment,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        $latestFailedRecords = BlockchainRecord::query()
            ->failed()
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (BlockchainRecord $record) => [
                'id' => $record->id,
                'entity_type' => $record->entity_type,
                'entity_id' => $record->entity_id,
                'status' => $record->status,
                'network' => $record->network,
                'environment' => $record->environment,
                'last_error' => $record->last_error,
                'updated_at' => $record->updated_at,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Blockchain monitoring summary retrieved.',
            'data' => [
                'total' => BlockchainRecord::query()->count(),
                'pending' => BlockchainRecord::query()->pending()->count(),
                'queued' => BlockchainRecord::query()->queued()->count(),
                'processing' => BlockchainRecord::query()->processing()->count(),
                'submitted' => BlockchainRecord::query()->submitted()->count(),
                'confirmed' => BlockchainRecord::query()->confirmed()->count(),
                'failed' => BlockchainRecord::query()->failed()->count(),
                'network_counts' => $networkCounts,
                'environment_counts' => $environmentCounts,
                'latest_failed_records' => $latestFailedRecords,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function buildFilteredQuery(array $validated)
    {
        $query = BlockchainRecord::query();

        if (! empty($validated['status'])) {
            match ($validated['status']) {
                'pending' => $query->pending(),
                'queued' => $query->queued(),
                'processing' => $query->processing(),
                'submitted' => $query->submitted(),
                'confirmed' => $query->confirmed(),
                'failed' => $query->failed(),
            };
        }

        if (! empty($validated['network'])) {
            $query->byNetwork($validated['network']);
        }

        if (! empty($validated['environment'])) {
            $query->byEnvironment($validated['environment']);
        }

        if (! empty($validated['entity_type'])) {
            $query->where('entity_type', $validated['entity_type']);
        }

        if (! empty($validated['entity_id'])) {
            $query->where('entity_id', $validated['entity_id']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search): void {
                $builder->where('record_hash', 'like', '%'.$search.'%')
                    ->orWhere('tx_hash', 'like', '%'.$search.'%')
                    ->orWhere('entity_id', 'like', '%'.$search.'%');
            });
        }

        return $query;
    }

    /**
     * Display the specified resource.
     */
    public function show(BlockchainRecord $blockchainRecord): BlockchainRecordResource
    {
        $this->authorizePatrolMonitoring();

        $blockchainRecord->load([
            'jobs' => fn ($query) => $query->latest('created_at'),
            'verifications' => fn ($query) => $query->latest('verified_at'),
            'verifications.verifiedBy',
        ]);

        return new BlockchainRecordResource($blockchainRecord);
    }

    public function retry(
        BlockchainRecord $blockchainRecord,
        BlockchainRecordService $blockchainRecordService,
    ): BlockchainRecordResource|JsonResponse {
        try {
            $record = $blockchainRecordService->retryFailedRecord($blockchainRecord);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'data' => null,
            ], 422);
        }

        return new BlockchainRecordResource($record);
    }

    public function refresh(
        BlockchainRecord $blockchainRecord,
        BlockchainSubmittedRecordRefreshService $refreshService,
    ): BlockchainRecordResource|JsonResponse {
        $this->authorizePatrolMonitoring();

        if (! $refreshService->isEligibleForRefresh($blockchainRecord)) {
            return response()->json([
                'success' => false,
                'message' => 'Only submitted blockchain records with an existing transaction hash can be refreshed.',
                'data' => null,
            ], 422);
        }

        $refreshService->refreshSubmittedRecord($blockchainRecord);

        $blockchainRecord->refresh()->load([
            'jobs' => fn ($query) => $query->latest('created_at'),
            'verifications' => fn ($query) => $query->latest('verified_at'),
            'verifications.verifiedBy',
        ]);

        return new BlockchainRecordResource($blockchainRecord);
    }

    public function verify(
        BlockchainRecord $blockchainRecord,
        BlockchainVerificationService $verificationService,
    ): BlockchainVerificationResource {
        $this->authorizePatrolMonitoring();

        $verification = $verificationService->verify(
            $blockchainRecord,
            'manual',
            request()->user('api'),
        );

        return new BlockchainVerificationResource($verification);
    }
}
