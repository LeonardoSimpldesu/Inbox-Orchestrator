<?php

namespace Tests\Unit;

use Tests\TestCase;

class InertiaPagePathsTest extends TestCase
{
    /**
     * macOS filesystems ignore case, Linux (CI, production) does not: a path
     * that only matches case-insensitively works locally and breaks there.
     */
    public function test_page_paths_exist_with_exact_case(): void
    {
        $paths = config('inertia.pages.paths');
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertContains(basename($path), scandir(dirname($path)), "Inertia page path [{$path}] does not exist with this exact case.");
        }
    }
}
