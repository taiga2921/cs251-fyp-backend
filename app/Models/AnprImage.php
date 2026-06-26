<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AnprImage extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'anpr_images';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'anpr_event_id',
        'image_type',
        'file_path',
        'file_size',
        'resolution',
        'expires_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'file_size' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function anprEvent(): BelongsTo
    {
        return $this->belongsTo(AnprEvent::class);
    }

    public function blockchainRecord(): HasOne
    {
        return $this->hasOne(BlockchainRecord::class, 'entity_id', 'id')
            ->where('blockchain_records.entity_type', 'anpr_image')
            ->where('blockchain_records.proof_type', 'evidence_file');
    }

    public function hasBlockchainEvidenceProof(): bool
    {
        if ($this->relationLoaded('blockchainRecord')) {
            return $this->blockchainRecord !== null;
        }

        return $this->blockchainRecord()->exists();
    }

    public static function hasProofedRowForEventAndType(string $anprEventId, string $imageType): bool
    {
        return static::query()
            ->where('anpr_event_id', $anprEventId)
            ->where('image_type', $imageType)
            ->whereHas('blockchainRecord')
            ->exists();
    }

    /**
     * @return list<string>
     */
    public static function blockchainCanonicalFieldNames(): array
    {
        return [
            'anpr_event_id',
            'image_type',
            'file_path',
            'file_size',
            'resolution',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function wouldChangeBlockchainCanonicalFields(array $attributes): bool
    {
        foreach (self::blockchainCanonicalFieldNames() as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            if (! $this->canonicalFieldMatches($field, $attributes[$field])) {
                return true;
            }
        }

        return false;
    }

    private function canonicalFieldMatches(string $field, mixed $newValue): bool
    {
        $current = $this->getAttribute($field);

        if ($field === 'file_size') {
            $normalizedCurrent = is_numeric($current) ? (int) $current : null;
            $normalizedNew = is_numeric($newValue) ? (int) $newValue : null;

            return $normalizedCurrent === $normalizedNew;
        }

        if ($current === null && $newValue === null) {
            return true;
        }

        return (string) $current === (string) $newValue;
    }
}
