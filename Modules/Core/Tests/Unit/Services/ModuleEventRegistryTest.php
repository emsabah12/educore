<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Modules\Core\Registry\ModuleEventRegistry;

final class ModuleEventRegistryTest extends TestCase
{
    private ModuleEventRegistry $eventRegistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventRegistry = new ModuleEventRegistry();
    }

    public function test_can_be_instantiated(): void
    {
        $this->assertInstanceOf(ModuleEventRegistry::class, $this->eventRegistry);
    }

    public function test_can_register_and_retrieve_listeners_for_an_event(): void
    {
        $eventClass = 'Modules\PPDB\Events\StudentRegistered';
        $listenerClass = 'Modules\Academic\Listeners\CreateStudentAcademicRecord';

        $this->eventRegistry->register($eventClass, $listenerClass);

        $listeners = $this->eventRegistry->getListenersFor($eventClass);

        $this->assertCount(1, $listeners);
        $this->assertEquals($listenerClass, $listeners[0]);
    }

    public function test_can_register_multiple_listeners_for_the_same_event(): void
    {
        $eventClass = 'Modules\PPDB\Events\StudentRegistered';
        $listenerA = 'Modules\Academic\Listeners\CreateStudentAcademicRecord';
        $listenerB = 'Modules\Finance\Listeners\GenerateStudentInvoice';

        $this->eventRegistry->register($eventClass, $listenerA);
        $this->eventRegistry->register($eventClass, $listenerB);

        $listeners = $this->eventRegistry->getListenersFor($eventClass);

        $this->assertCount(2, $listeners);
        $this->assertContains($listenerA, $listeners);
        $this->assertContains($listenerB, $listeners);
    }

    public function test_returns_empty_array_if_event_has_no_listeners(): void
    {
        $listeners = $this->eventRegistry->getListenersFor('Modules\NonExistent\Event');
        
        $this->assertIsArray($listeners);
        $this->assertEmpty($listeners);
    }
}