<?php

namespace Tests\Unit\Blockchain;

use App\Support\BlockchainCanonicalJson;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BlockchainCanonicalJsonTest extends TestCase
{
    public function test_same_top_level_payload_with_different_key_order_produces_identical_json(): void
    {
        $first = [
            'entity_type' => 'anpr_event',
            'entity_id' => 'event-1',
            'proof_type' => 'entity_created',
        ];

        $second = [
            'proof_type' => 'entity_created',
            'entity_id' => 'event-1',
            'entity_type' => 'anpr_event',
        ];

        $this->assertSame(
            BlockchainCanonicalJson::encode($first),
            BlockchainCanonicalJson::encode($second)
        );
    }

    public function test_same_nested_associative_payload_with_different_key_order_produces_identical_json(): void
    {
        $first = [
            'outer' => [
                'z_field' => 1,
                'a_field' => [
                    'nested_z' => true,
                    'nested_a' => null,
                ],
            ],
        ];

        $second = [
            'outer' => [
                'a_field' => [
                    'nested_a' => null,
                    'nested_z' => true,
                ],
                'z_field' => 1,
            ],
        ];

        $this->assertSame(
            BlockchainCanonicalJson::encode($first),
            BlockchainCanonicalJson::encode($second)
        );
    }

    public function test_list_array_order_is_preserved_and_not_sorted(): void
    {
        $payload = [
            'sequence' => ['third', 'first', 'second'],
        ];

        $json = BlockchainCanonicalJson::encode($payload);

        $this->assertSame('{"sequence":["third","first","second"]}', $json);
    }

    public function test_empty_arrays_are_encoded_as_empty_json_lists(): void
    {
        $json = BlockchainCanonicalJson::encode([
            'items' => [],
        ]);

        $this->assertSame('{"items":[]}', $json);
    }

    public function test_scalar_values_remain_stable(): void
    {
        $payload = [
            'boolean_true' => true,
            'boolean_false' => false,
            'null_value' => null,
            'integer_value' => 42,
            'string_value' => 'ABC-1234',
            'decimal_value' => 0.9200,
        ];

        $json = BlockchainCanonicalJson::encode($payload);

        $this->assertSame(
            '{"boolean_false":false,"boolean_true":true,"decimal_value":0.92,"integer_value":42,"null_value":null,"string_value":"ABC-1234"}',
            $json
        );
    }

    public function test_timestamps_normalize_to_utc_iso8601_with_z_suffix(): void
    {
        $payload = [
            'detection_time' => Carbon::parse('2026-06-21 10:00:00', 'Asia/Kuala_Lumpur'),
        ];

        $json = BlockchainCanonicalJson::encode($payload);

        $this->assertSame('{"detection_time":"2026-06-21T02:00:00Z"}', $json);
    }

    public function test_canonical_json_output_has_no_unstable_whitespace(): void
    {
        $json = BlockchainCanonicalJson::encode([
            'entity_type' => 'anpr_event',
            'entity_id' => 'event-1',
            'nested' => [
                'b' => 2,
                'a' => 1,
            ],
        ]);

        $this->assertStringNotContainsString("\n", $json);
        $this->assertStringNotContainsString("\r", $json);
        $this->assertDoesNotMatchRegularExpression('/:\s+/', $json);
        $this->assertDoesNotMatchRegularExpression('/,\s+/', $json);
    }

    public function test_unsupported_value_types_throw_clear_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported value type for canonical JSON encoding: object');

        BlockchainCanonicalJson::normalize(new \stdClass);
    }
}
