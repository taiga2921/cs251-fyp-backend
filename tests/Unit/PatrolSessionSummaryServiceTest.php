<?php

namespace Tests\Unit;

use App\Models\PatrolSession;
use App\Services\PatrolSessionSummaryService;
use PHPUnit\Framework\TestCase;

class PatrolSessionSummaryServiceTest extends TestCase
{
    public function test_confidence_level_thresholds(): void
    {
        $service = new PatrolSessionSummaryService;
        $reflection = new \ReflectionClass($service);

        $level = $reflection->getMethod('confidenceLevel');
        $level->setAccessible(true);

        $this->assertSame('high', $level->invoke($service, 80));
        $this->assertSame('medium', $level->invoke($service, 50));
        $this->assertSame('low', $level->invoke($service, 49));
    }

    public function test_calculate_confidence_score_clamps_to_zero(): void
    {
        $service = new PatrolSessionSummaryService;
        $reflection = new \ReflectionClass($service);

        $method = $reflection->getMethod('calculateConfidenceScore');
        $method->setAccessible(true);

        $score = $method->invoke($service, 5, 5, 1, 1, 1);

        $this->assertSame(0, $score);
    }
}
