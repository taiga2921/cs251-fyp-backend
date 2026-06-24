<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlockchainRecordResource;
use App\Models\BlockchainRecord;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BlockchainRecordController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $validated = request()->validate([
            'status' => ['nullable', Rule::in(['pending', 'queued', 'processing', 'submitted', 'confirmed', 'failed'])],
            'network' => ['nullable', Rule::in(['ganache', 'sepolia'])],
            'environment' => ['nullable', Rule::in(['local', 'staging', 'production'])],
            'entity_type' => ['nullable', 'string', 'max:100', 'required_with:entity_id'],
            'entity_id' => ['nullable', 'uuid', 'required_with:entity_type'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'confirmed_at', 'block_number'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

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

        if (! empty($validated['entity_type']) && ! empty($validated['entity_id'])) {
            $query->where('entity_type', $validated['entity_type'])
                ->where('entity_id', $validated['entity_id']);
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $perPage = $validated['per_page'] ?? 15;

        return BlockchainRecordResource::collection(
            $query->orderBy($sortBy, $sortOrder)->paginate($perPage)->withQueryString()
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(BlockchainRecord $blockchainRecord): BlockchainRecordResource
    {
        return new BlockchainRecordResource($blockchainRecord);
    }
}
