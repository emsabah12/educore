<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Person\Contracts\PersonLifecycleEventRepositoryInterface;
use Modules\Core\Person\Contracts\PersonLifecycleServiceInterface;
use Modules\Core\Person\Contracts\PersonRepositoryInterface;
use Modules\Core\Person\Entities\Person;
use Modules\Core\Person\Enums\PersonLegalSex;
use Modules\Core\Person\Enums\PersonLifecycleEventType;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\Models\PersonModel;
use Modules\Core\Person\ValueObjects\PersonName;
use Modules\Core\Support\Uuid\UuidV7;
use Tests\TestCase;

final class PersonPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_person_model_generates_uuid_v7_when_id_is_missing(): void
    {
        $person = PersonModel::query()->create([
            'name' => 'Generated Person',
            'status' => PersonStatus::ACTIVE->value,
        ]);

        $this->assertTrue(
            UuidV7::validate((string) $person->id),
        );
    }

    public function test_repository_round_trips_canonical_person_biodata(): void
    {
        $repository = $this->app->make(
            PersonRepositoryInterface::class,
        );

        $personId = UuidV7::generate();

        $saved = $repository->save(
            new Person(
                id: $personId,
                name: new PersonName('Muhammad Ahmad Pratama'),
                status: PersonStatus::ACTIVE,
                givenName: 'Muhammad',
                middleName: 'Ahmad',
                familyName: 'Pratama',
                birthDate: new DateTimeImmutable('2000-01-02'),
                birthPlaceName: 'Bandung',
                birthCountryCode: 'ID',
                legalSex: PersonLegalSex::MALE,
                civilStatus: 'SINGLE',
            ),
        );

        $this->assertSame($personId, $saved->id());
        $this->assertSame('Muhammad', $saved->givenName());
        $this->assertSame('Ahmad', $saved->middleName());
        $this->assertSame('Pratama', $saved->familyName());
        $this->assertSame('2000-01-02', $saved->birthDate()?->format('Y-m-d'));
        $this->assertSame('Bandung', $saved->birthPlaceName());
        $this->assertSame('ID', $saved->birthCountryCode());
        $this->assertSame(PersonLegalSex::MALE, $saved->legalSex());
        $this->assertSame('SINGLE', $saved->civilStatus());

        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'name' => 'Muhammad Ahmad Pratama',
            'given_name' => 'Muhammad',
            'middle_name' => 'Ahmad',
            'family_name' => 'Pratama',
            'birth_place_name' => 'Bandung',
            'birth_country_code' => 'ID',
            'legal_sex' => 'M',
            'civil_status' => 'SINGLE',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_lifecycle_transition_persists_uuid_v7_event_and_actor_user_id(): void
    {
        $personRepository = $this->app->make(
            PersonRepositoryInterface::class,
        );
        $lifecycleService = $this->app->make(
            PersonLifecycleServiceInterface::class,
        );
        $eventRepository = $this->app->make(
            PersonLifecycleEventRepositoryInterface::class,
        );

        $personId = UuidV7::generate();
        $actorUserId = UuidV7::generate();

        $personRepository->save(
            new Person(
                id: $personId,
                name: new PersonName('Lifecycle Person'),
            ),
        );

        DB::table('users')->insert([
            'id' => $actorUserId,
            'person_id' => $personId,
            'email' => 'lifecycle-person@example.test',
            'password' => password_hash('test-password', PASSWORD_BCRYPT),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lifecycleService->deactivate(
            personId: $personId,
            actorUserId: $actorUserId,
            reason: 'Administrative deactivation.',
        );

        $events = $eventRepository->findByPersonId($personId);

        $this->assertCount(1, $events);
        $this->assertTrue(UuidV7::validate($events[0]->id()));
        $this->assertSame(
            PersonLifecycleEventType::DEACTIVATED,
            $events[0]->type(),
        );
        $this->assertSame($actorUserId, $events[0]->actorUserId());
        $this->assertSame(
            'Administrative deactivation.',
            $events[0]->reason(),
        );

        $this->assertDatabaseHas('person_lifecycle_events', [
            'id' => $events[0]->id(),
            'person_id' => $personId,
            'actor_user_id' => $actorUserId,
            'type' => PersonLifecycleEventType::DEACTIVATED->value,
        ]);
    }
}
