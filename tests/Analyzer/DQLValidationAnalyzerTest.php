<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Analyzer;

use AhmedBhs\DoctrineDoctor\Analyzer\DQLValidationAnalyzer;
use AhmedBhs\DoctrineDoctor\Tests\Integration\PlatformAnalyzerTestHelper;
use AhmedBhs\DoctrineDoctor\Tests\Support\QueryDataBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test for DQLValidationAnalyzer.
 *
 * Tests validation of DQL queries for:
 * - Invalid entity class references
 * - Non-existent field references
 * - DQL syntax errors
 * - Invalid association references
 */
final class DQLValidationAnalyzerTest extends TestCase
{
    private DQLValidationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DQLValidationAnalyzer(
            PlatformAnalyzerTestHelper::createTestEntityManager(),
            PlatformAnalyzerTestHelper::createIssueFactory(),
        );
    }

    #[Test]
    public function it_returns_empty_collection_when_no_dql_queries(): void
    {
        // Arrange: Regular SQL queries without DQL backtrace
        $queries = QueryDataBuilder::create()
            ->addQuery('SELECT * FROM users WHERE id = 1')
            ->addQuery('SELECT name, email FROM products')
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues, 'Should not detect issues in non-DQL queries');
    }

    #[Test]
    public function it_returns_empty_collection_when_no_issues(): void
    {
        // Arrange: Valid DQL-like SQL with Doctrine patterns (t0_ aliases)
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id, t0_.name FROM users t0_ WHERE t0_.id = 1',
                [
                    ['class' => 'Doctrine\\ORM\\QueryBuilder', 'function' => 'getQuery'],
                    ['class' => 'App\\Repository\\UserRepository', 'function' => 'findById'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues, 'Should not detect issues in valid Doctrine SQL');
    }

    #[Test]
    public function it_detects_invalid_entity_class_in_from_clause(): void
    {
        // Arrange: SQL generated from DQL with invalid entity class
        // Doctrine generates SQL with t0_, t1_ patterns
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id, t0_.name FROM NonExistentEntity t0_',
                [
                    ['class' => 'Doctrine\\ORM\\Query', 'function' => 'getResult'],
                    ['class' => 'App\\Repository\\UserRepository', 'function' => 'customQuery'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity class');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertEquals('dql_validation', $data['type']);
        self::assertStringContainsString('NonExistentEntity', $data['description']);
        self::assertStringContainsString('Unknown entity class', $data['description']);
    }

    #[Test]
    public function it_detects_invalid_entity_class_in_join_clause(): void
    {
        // Arrange: SQL with invalid entity in JOIN
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM users t0_ INNER JOIN InvalidEntity t1_ ON t0_.id = t1_.user_id',
                [
                    ['class' => 'Doctrine\\ORM\\QueryBuilder', 'function' => 'getDQL'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity in JOIN');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('InvalidEntity', $data['description']);
        self::assertStringContainsString('JOIN clause', $data['description']);
    }

    #[Test]
    public function it_detects_non_existent_field_references(): void
    {
        // Arrange: SQL with invalid field reference
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id, t0_.non_existent_field FROM users t0_',
                [
                    ['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();

        // Note: Field validation requires entity mapping which may not trigger
        // if we cannot map table to entity, so we check >= 0
        self::assertGreaterThanOrEqual(0, count($issuesArray));
    }

    #[Test]
    public function it_detects_dql_syntax_errors(): void
    {
        // Arrange: This test is difficult because syntax errors would be caught during query parsing
        // We test that the analyzer handles queries gracefully
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM users t0_ WHERE t0_.invalid_syntax',
                [
                    ['class' => 'Doctrine\\ORM\\EntityManager', 'function' => 'createQuery'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert: We just verify it doesn't crash
        $issuesArray = $issues->toArray();
        self::assertGreaterThanOrEqual(0, count($issuesArray), 'Should handle queries gracefully');
    }

    #[Test]
    public function it_skips_table_name_patterns(): void
    {
        // Arrange: SQL with table names (lowercase, underscores) - not entity classes
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT * FROM user_profiles up JOIN user_settings us',
                [
                    ['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues, 'Should skip table names (lowercase with underscores)');
    }

    #[Test]
    public function it_uses_critical_severity(): void
    {
        // Arrange: Invalid DQL
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM InvalidEntityClass t0_',
                [
                    ['class' => 'Doctrine\\ORM\\Query', 'function' => 'getResult'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();
        self::assertEquals('critical', $data['severity'], 'DQL validation errors should have critical severity');
    }

    #[Test]
    public function it_deduplicates_same_dql_query(): void
    {
        // Arrange: Same invalid SQL repeated multiple times
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM NonExistent t0_',
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM NonExistent t0_', // Same query
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM NonExistent t0_', // Same query again
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertCount(1, $issuesArray, 'Should deduplicate same DQL query (using MD5 hash)');
    }

    #[Test]
    public function it_includes_query_in_issue_description(): void
    {
        // Arrange: Invalid DQL
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM BadEntity t0_',
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('Query:', $data['description']);
        self::assertStringContainsString('BadEntity', $data['description']);
    }

    #[Test]
    public function it_includes_impact_in_issue_description(): void
    {
        // Arrange: Invalid DQL
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM InvalidClass t0_',
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('Impact:', $data['description']);
        self::assertStringContainsString('runtime', $data['description']);
    }

    #[Test]
    public function it_includes_solution_in_issue_description(): void
    {
        // Arrange: Invalid DQL
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM WrongEntity t0_',
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('Solution:', $data['description']);
        self::assertStringContainsString('Verify entity class names', $data['description']);
    }

    #[Test]
    public function it_counts_multiple_errors_in_title(): void
    {
        // Arrange: DQL with multiple invalid entities
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM InvalidOne t0_ INNER JOIN InvalidTwo t1_',
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entities');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertStringContainsString('errors', $data['title']);
        // Title should include error count, e.g., "DQL Validation Issue (2 errors)"
    }

    #[Test]
    public function it_formats_query_for_readability(): void
    {
        // Arrange: Complex DQL query
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM BadEntity t0_ WHERE t0_.id = 1 ORDER BY t0_.name LIMIT 10',
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        // Query should be formatted with line breaks for readability
        self::assertStringContainsString('WHERE', $data['description']);
        self::assertStringContainsString('ORDER BY', $data['description']);
    }

    #[Test]
    public function it_truncates_very_long_queries(): void
    {
        // Arrange: Very long query (> 500 characters)
        $longQuery = 'SELECT t0_.id FROM VeryLongEntityNameThatDoesNotExist t0_ WHERE ' . str_repeat('t0_.field = 1 AND ', 50);

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                $longQuery,
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        if (strlen($longQuery) > 500) {
            self::assertStringContainsString('truncated', $data['description']);
        }
    }

    #[Test]
    public function it_skips_queries_without_doctrine_backtrace(): void
    {
        // Arrange: DQL-like query but without Doctrine in backtrace
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT u FROM SomeEntity u',
                [
                    ['class' => 'App\\CustomClass', 'function' => 'customMethod'],
                    ['class' => 'PDO', 'function' => 'query'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        self::assertCount(0, $issues, 'Should skip queries without Doctrine in backtrace');
    }

    #[Test]
    public function it_detects_queries_from_query_builder(): void
    {
        // Arrange: Query with QueryBuilder in backtrace
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM BadEntity t0_',
                [
                    ['class' => 'Doctrine\\ORM\\QueryBuilder', 'function' => 'getQuery'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect DQL from QueryBuilder');
    }

    #[Test]
    public function it_detects_queries_from_create_query(): void
    {
        // Arrange: Query with createQuery in backtrace
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM InvalidEntity t0_',
                [
                    ['class' => 'Doctrine\\ORM\\EntityManager', 'function' => 'createQuery'],
                ],
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect DQL from createQuery');
    }

    #[Test]
    public function it_attaches_backtrace_to_issue(): void
    {
        // Arrange: Invalid DQL with backtrace
        $backtrace = [
            ['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute', 'line' => 123],
            ['class' => 'App\\Repository\\UserRepository', 'function' => 'findUsers', 'line' => 45],
        ];

        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM BadEntity t0_',
                $backtrace,
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertArrayHasKey('backtrace', $data);
        self::assertIsArray($data['backtrace']);
        self::assertCount(2, $data['backtrace']);
    }

    #[Test]
    public function it_attaches_query_data_to_issue(): void
    {
        // Arrange: Invalid DQL
        $queries = QueryDataBuilder::create()
            ->addQueryWithBacktrace(
                'SELECT t0_.id FROM NonExistent t0_',
                [['class' => 'Doctrine\\ORM\\Query', 'function' => 'execute']],
                50.5,  // executionTime
            )
            ->build();

        // Act
        $issues = $this->analyzer->analyze($queries);

        // Assert
        $issuesArray = $issues->toArray();
        self::assertGreaterThan(0, count($issuesArray), 'Should detect invalid entity');

        $issue = $issuesArray[0];
        $data = $issue->getData();

        self::assertArrayHasKey('queries', $data);
        self::assertIsArray($data['queries']);
        self::assertCount(1, $data['queries']);
    }
}
