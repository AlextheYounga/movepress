<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\Sync\SelectionRulesBuilder;
use PHPUnit\Framework\TestCase;

class SelectionRulesBuilderTest extends TestCase
{
    public function test_select_all_returns_empty_rules(): void
    {
        $builder = new SelectionRulesBuilder();

        $result = $builder->build([], true);

        $this->assertFalse($result['restrict']);
        $this->assertSame([], $result['includes']);
    }

    public function test_single_directory_includes_parents_and_glob(): void
    {
        $builder = new SelectionRulesBuilder();
        $selected = [['path' => 'wp-content/uploads', 'type' => 'dir']];

        $result = $builder->build($selected, false);

        $this->assertTrue($result['restrict']);
        $this->assertSame(['wp-content/', 'wp-content/uploads/', 'wp-content/uploads/***'], $result['includes']);
    }

    public function test_nested_directory_deduplicates_parent_entries(): void
    {
        $builder = new SelectionRulesBuilder();
        $selected = [['path' => 'wp-content/uploads/2024', 'type' => 'dir']];

        $result = $builder->build($selected, false);

        $this->assertTrue($result['restrict']);
        $this->assertSame(
            ['wp-content/', 'wp-content/uploads/', 'wp-content/uploads/2024/', 'wp-content/uploads/2024/***'],
            $result['includes'],
        );
    }

    public function test_mixed_directory_and_file_paths(): void
    {
        $builder = new SelectionRulesBuilder();
        $selected = [['path' => 'logs', 'type' => 'dir'], ['path' => 'wp-content/uploads/file.txt', 'type' => 'file']];

        $result = $builder->build($selected, false);

        $this->assertTrue($result['restrict']);
        $this->assertSame(
            ['logs/', 'logs/***', 'wp-content/', 'wp-content/uploads/', 'wp-content/uploads/file.txt'],
            $result['includes'],
        );
    }
}
