<?php

namespace Tests\Unit;

use App\Services\LoadService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoadServiceTest extends TestCase
{
    private LoadService $load;

    protected function setUp(): void
    {
        parent::setUp();
        $this->load = new LoadService;
    }

    #[Test]
    public function due_soon_three_overloads(): void
    {
        $this->assertSame('overloaded', $this->load->computeLoad([
            'dueSoonCount' => 3,
            'distinctProjectsIncludingContrib' => 1,
            'hasOwnership' => true,
        ]));
    }

    #[Test]
    public function multi_project_with_two_due_soon_overloads(): void
    {
        $this->assertSame('overloaded', $this->load->computeLoad([
            'dueSoonCount' => 2,
            'distinctProjectsIncludingContrib' => 2,
            'hasOwnership' => true,
        ]));
    }

    #[Test]
    public function no_due_no_ownership_is_free(): void
    {
        $this->assertSame('free', $this->load->computeLoad([
            'dueSoonCount' => 0,
            'distinctProjectsIncludingContrib' => 0,
            'hasOwnership' => false,
        ]));
    }

    #[Test]
    public function else_balanced(): void
    {
        $this->assertSame('balanced', $this->load->computeLoad([
            'dueSoonCount' => 1,
            'distinctProjectsIncludingContrib' => 1,
            'hasOwnership' => true,
        ]));
    }
}
