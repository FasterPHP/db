<?php

declare(strict_types=1);

namespace FasterPhp\Db\Reconnect;

use PDOException;
use PHPUnit\Framework\TestCase;

final class DefaultStrategyTest extends TestCase
{
    public function testDefaultPatterns(): void
    {
        $strategy = new DefaultStrategy();
        $this->assertSame(DefaultStrategy::DEFAULT_PATTERNS, $strategy->getPatterns());
    }

    public function testCustomPatterns(): void
    {
        $patterns = ['pattern1', 'pattern2'];
        $strategy = new DefaultStrategy($patterns);
        $this->assertSame($patterns, $strategy->getPatterns());
    }

    public function testAddPattern(): void
    {
        $extraPattern = 'extra pattern';
        $strategy = new DefaultStrategy();
        $strategy->addPattern($extraPattern);
        $this->assertSame(
            array_merge(DefaultStrategy::DEFAULT_PATTERNS, [$extraPattern]),
            $strategy->getPatterns()
        );
    }

    public function testShouldReconnectTrue(): void
    {
        $patterns = ['pattern'];
        $strategy = new DefaultStrategy($patterns);

        $this->assertTrue($strategy->shouldReconnect(new PDOException('pattern')));
    }

    public function testShouldReconnectFalse(): void
    {
        $patterns = ['pattern'];
        $strategy = new DefaultStrategy($patterns);

        $this->assertFalse($strategy->shouldReconnect(new PDOException('something else')));
    }

    public function testDefaultMaxAttempts(): void
    {
        $strategy = new DefaultStrategy();
        $this->assertSame(1, $strategy->getMaxAttempts());
    }

    public function testCustomMaxAttempts(): void
    {
        $strategy = new DefaultStrategy(null, 3);
        $this->assertSame(3, $strategy->getMaxAttempts());
    }

    public function testGetDelayMsFirstAttempt(): void
    {
        $strategy = new DefaultStrategy(null, 3, 100, 2.0);
        $this->assertSame(0, $strategy->getDelayMs(1));
    }

    public function testGetDelayMsWithBackoff(): void
    {
        $strategy = new DefaultStrategy(null, 3, 100, 2.0);
        $this->assertSame(100, $strategy->getDelayMs(2)); // 100 * 2^0
        $this->assertSame(200, $strategy->getDelayMs(3)); // 100 * 2^1
        $this->assertSame(400, $strategy->getDelayMs(4)); // 100 * 2^2
    }
}
