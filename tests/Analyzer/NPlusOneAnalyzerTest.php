<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\NPlusOneAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for NPlusOneAnalyzer.
 *
 * This analyzer detects N+1 query problems by identifying when the same
 * query pattern is executed multiple times (above a threshold).
 */
final class NPlusOneAnalyzerTest extends TestCase
{
    private NPlusOneAnalyzer $analyzer;

    protected function setUp(): void
    {
        $entityManager = PlatformAnalyzerTestHelper::createTestEntityManager([
            __DIR__ . '/../Fixtures/Entity',
        ]);

        $this->analyzer = new NPlusOneAnalyzer(
            $entityManager,
            PlatformAnalyzerTestHelper::createIssueFactory(),
            PlatformAnalyzerTestHelper::createSuggestionFactory(),
            5, // threshold: 5 similar queries to trigger detection
        );
    }

    #[Test]
    public function it_detects_n_plus_one_when_threshold_exceeded(): void
    {
        // Arrange: 10 identical queries (above threshold of 5)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.5)
            ->addQuery('SELECT * FROM users WHERE id = 2', 11.2)
            ->addQuery('SELECT * FROM users WHERE id = 3', 9.8)
            ->addQuery('SELECT * FROM users WHERE id = 4', 10.1)
            ->addQuery('SELECT * FROM users WHERE id = 5', 10.9)
            ->addQuery('SELECT * FROM users WHERE id = 6', 11.5)
            ->addQuery('SELECT * FROM users WHERE id = 7', 10.3)
            ->addQuery('SELECT * FROM users WHERE id = 8', 9.9)
            ->addQuery('SELECT * FROM users WHERE id = 9', 10.7)
            ->addQuery('SELECT * FROM users WHERE id = 10', 11.1)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect the N+1 pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect one N+1 pattern');

        $issue = $issuesArray[0];
        self::assertStringContainsString('N+1', $issue->getTitle());
        self::assertStringContainsString('10 queries', $issue->getTitle());
    }

    #[Test]
    public function it_does_not_detect_n_plus_one_below_threshold(): void
    {
        // Arrange: Only 4 similar queries (below threshold of 5)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.5)
            ->addQuery('SELECT * FROM users WHERE id = 2', 11.2)
            ->addQuery('SELECT * FROM users WHERE id = 3', 9.8)
            ->addQuery('SELECT * FROM users WHERE id = 4', 10.1)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect N+1 (below threshold)
        self::assertCount(0, $issues, 'Should not detect N+1 below threshold');
    }

    #[Test]
    public function it_normalizes_query_parameters(): void
    {
        // Arrange: Queries with different parameters but same pattern
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM posts WHERE user_id = 1", 5.0)
            ->addQuery("SELECT * FROM posts WHERE user_id = 2", 5.1)
            ->addQuery("SELECT * FROM posts WHERE user_id = 3", 5.2)
            ->addQuery("SELECT * FROM posts WHERE user_id = 4", 5.3)
            ->addQuery("SELECT * FROM posts WHERE user_id = 5", 5.4)
            ->addQuery("SELECT * FROM posts WHERE user_id = 6", 5.5)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize as same pattern despite different IDs
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should detect pattern despite different parameters');

        $issue = $issuesArray[0];
        self::assertStringContainsString('6 queries', $issue->getTitle());
    }

    #[Test]
    public function it_normalizes_string_literals(): void
    {
        // Arrange: Queries with different string values
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM users WHERE name = 'Alice'", 5.0)
            ->addQuery("SELECT * FROM users WHERE name = 'Bob'", 5.1)
            ->addQuery("SELECT * FROM users WHERE name = 'Charlie'", 5.2)
            ->addQuery("SELECT * FROM users WHERE name = 'David'", 5.3)
            ->addQuery("SELECT * FROM users WHERE name = 'Eve'", 5.4)
            ->addQuery("SELECT * FROM users WHERE name = 'Frank'", 5.5)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize as same pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('6 queries', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_normalizes_in_clauses(): void
    {
        // Arrange: Queries with different IN clause contents
        $queries = QueryDataBuilder::create()
            ->addQuery("SELECT * FROM posts WHERE id IN (1, 2, 3)", 5.0)
            ->addQuery("SELECT * FROM posts WHERE id IN (4, 5)", 5.1)
            ->addQuery("SELECT * FROM posts WHERE id IN (6, 7, 8, 9)", 5.2)
            ->addQuery("SELECT * FROM posts WHERE id IN (10)", 5.3)
            ->addQuery("SELECT * FROM posts WHERE id IN (11, 12)", 5.4)
            ->addQuery("SELECT * FROM posts WHERE id IN (13, 14, 15)", 5.5)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize as same pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);
        self::assertStringContainsString('6 queries', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_calculates_total_execution_time(): void
    {
        // Arrange: 6 queries with 10ms each = 60ms total
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 10.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 10.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should mention total time
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $description = $issuesArray[0]->getDescription();
        self::assertStringContainsString('60.00', $description, 'Should show total execution time');
    }

    #[Test]
    public function it_assigns_info_severity_for_small_impact(): void
    {
        // Arrange: 6 queries (above threshold) but low execution time
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 1.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 1.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be INFO severity (< 10 queries, < 500ms total)
        $issuesArray = $issues->toArray();
        self::assertEquals('info', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_warning_severity_for_medium_impact(): void
    {
        // Arrange: 15 queries (above 10) = warning
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 15; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 10.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be WARNING severity (> 10 queries)
        $issuesArray = $issues->toArray();
        self::assertEquals('warning', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_critical_severity_for_many_queries(): void
    {
        // Arrange: 25 queries (above 20) = critical
        $queries = QueryDataBuilder::create();

        for ($i = 1; $i <= 25; $i++) {
            $queries->addQuery("SELECT * FROM users WHERE id = {$i}", 5.0);
        }

        // Act
        $issues = $this->analyzer->analyze($queries->build());

        // Assert: Should be CRITICAL severity (> 20 queries)
        $issuesArray = $issues->toArray();
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_critical_severity_for_high_total_time(): void
    {
        // Arrange: 6 queries with 200ms each = 1200ms total (> 1000ms)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 200.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 200.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be CRITICAL severity (> 1000ms total)
        $issuesArray = $issues->toArray();
        self::assertEquals('critical', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_assigns_warning_severity_for_medium_total_time(): void
    {
        // Arrange: 6 queries with 100ms each = 600ms total (> 500ms but < 1000ms)
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 100.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 100.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should be WARNING severity (> 500ms total)
        $issuesArray = $issues->toArray();
        self::assertEquals('warning', $issuesArray[0]->getSeverity()->value);
    }

    #[Test]
    public function it_generates_join_fetch_suggestion_for_foreign_key_pattern(): void
    {
        // Arrange: Pattern matching "WHERE t0.xxx_id = ?"
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 1', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 2', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 3', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 4', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 5', 5.0)
            ->addQuery('SELECT * FROM comments t0 WHERE t0.post_id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide JOIN FETCH suggestion
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion, 'Should provide suggestion');
        self::assertStringContainsString('JOIN', $suggestion->getCode());
        self::assertStringContainsString('post', strtolower($suggestion->getCode()));
    }

    #[Test]
    public function it_generates_suggestion_for_simple_select_pattern(): void
    {
        // Arrange: Simple SELECT with foreign key
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 1', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 2', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 3', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 4', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 5', 5.0)
            ->addQuery('SELECT id, title FROM posts WHERE author_id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should provide suggestion
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray);

        $suggestion = $issuesArray[0]->getSuggestion();
        self::assertNotNull($suggestion, 'Should provide suggestion for simple SELECT');
    }

    #[Test]
    public function it_detects_multiple_n_plus_one_patterns(): void
    {
        // Arrange: Two different N+1 patterns
        $queries = QueryDataBuilder::create()
            // Pattern 1: Loading posts by user_id (6 times)
            ->addQuery('SELECT * FROM posts WHERE user_id = 1', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 2', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 3', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 4', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 5', 5.0)
            ->addQuery('SELECT * FROM posts WHERE user_id = 6', 5.0)
            // Pattern 2: Loading comments by post_id (7 times)
            ->addQuery('SELECT * FROM comments WHERE post_id = 1', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 2', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 3', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 4', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 5', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 6', 3.0)
            ->addQuery('SELECT * FROM comments WHERE post_id = 7', 3.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should detect BOTH patterns
        $issuesArray = $issues->toArray();
        self::assertCount(2, $issuesArray, 'Should detect two different N+1 patterns');

        // Verify both patterns are detected
        $hasPosts = false;
        $hasComments = false;

        foreach ($issuesArray as $issue) {
            if (str_contains($issue->getTitle(), '6 queries')) {
                $hasPosts = true;
            }
            if (str_contains($issue->getTitle(), '7 queries')) {
                $hasComments = true;
            }
        }

        self::assertTrue($hasPosts, 'Should detect posts pattern');
        self::assertTrue($hasComments, 'Should detect comments pattern');
    }

    #[Test]
    public function it_includes_query_pattern_in_description(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Description should include normalized pattern
        $issuesArray = $issues->toArray();
        $description = $issuesArray[0]->getDescription();

        self::assertStringContainsString('Pattern:', $description);
        self::assertStringContainsString('SELECT', $description);
        self::assertStringContainsString('users', strtolower($description));
    }

    #[Test]
    public function it_includes_backtrace_from_first_query(): void
    {
        // Arrange: Add backtrace to first query
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace('SELECT * FROM users WHERE id = 1', [
                ['file' => 'UserRepository.php', 'line' => 42],
            ], 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should include backtrace from first query
        $issuesArray = $issues->toArray();
        $backtrace = $issuesArray[0]->getBacktrace();

        self::assertNotNull($backtrace);
        self::assertIsArray($backtrace);
        self::assertArrayHasKey('file', $backtrace[0]);
        self::assertEquals('UserRepository.php', $backtrace[0]['file']);
    }

    #[Test]
    public function it_normalizes_whitespace(): void
    {
        // Arrange: Same query with different whitespace
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT   *   FROM   users   WHERE   id = 2', 5.0)
            ->addQuery("SELECT * \n FROM users \n WHERE id = 3", 5.0)
            ->addQuery("SELECT *\tFROM\tusers\tWHERE\tid = 4", 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should recognize all as same pattern
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should normalize whitespace');
        self::assertStringContainsString('6 queries', $issuesArray[0]->getTitle());
    }

    #[Test]
    public function it_handles_empty_query_collection(): void
    {
        // Arrange: Empty collection
        $queries = QueryDataBuilder::create()->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should return empty collection
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_handles_single_query(): void
    {
        // Arrange: Only one query
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect N+1
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_handles_all_different_queries(): void
    {
        // Arrange: All different query patterns
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users', 5.0)
            ->addQuery('SELECT * FROM posts', 5.0)
            ->addQuery('SELECT * FROM comments', 5.0)
            ->addQuery('INSERT INTO logs VALUES (1)', 5.0)
            ->addQuery('UPDATE settings SET value = 1', 5.0)
            ->addQuery('DELETE FROM cache WHERE id = 1', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: Should NOT detect N+1 (all different patterns)
        self::assertCount(0, $issues);
    }

    #[Test]
    public function it_has_performance_category(): void
    {
        // Arrange
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 2', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 3', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 4', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 5', 5.0)
            ->addQuery('SELECT * FROM users WHERE id = 6', 5.0)
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertEquals('performance', $issuesArray[0]->getCategory());
    }
}
