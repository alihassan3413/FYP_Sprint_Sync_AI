<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Time\UserTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class UserTimeTest extends TestCase
{
    public function test_it_accepts_valid_iana_identifiers(): void
    {
        $this->assertTrue(UserTime::isValid('Asia/Karachi'));
        $this->assertTrue(UserTime::isValid('UTC'));
        $this->assertFalse(UserTime::isValid('Mars/Olympus_Mons'));
        $this->assertFalse(UserTime::isValid(null));
        $this->assertFalse(UserTime::isValid(''));
    }

    public function test_it_falls_back_to_the_application_timezone(): void
    {
        config(['app.timezone' => 'UTC']);

        $this->assertSame('UTC', UserTime::resolve(null));
        $this->assertSame('UTC', UserTime::resolve('Not/AZone'));
        $this->assertSame('Asia/Karachi', UserTime::resolve('Asia/Karachi'));
    }

    public function test_it_converts_a_local_wall_clock_time_to_utc(): void
    {
        $utc = UserTime::toUtc('2026-09-01 15:00', 'Asia/Karachi');

        $this->assertSame('2026-09-01 10:00:00', $utc->toDateTimeString());
        $this->assertSame('UTC', $utc->timezoneName);
    }

    public function test_it_treats_input_as_the_application_timezone_when_the_user_has_none(): void
    {
        config(['app.timezone' => 'UTC']);

        $this->assertSame('2026-09-01 15:00:00', UserTime::toUtc('2026-09-01 15:00', null)->toDateTimeString());
    }

    public function test_it_round_trips_a_local_time_through_utc(): void
    {
        $local = '2026-09-01 15:00';
        $utc = UserTime::toUtc($local, 'Australia/Sydney');

        $this->assertSame($local, $utc->copy()->setTimezone('Australia/Sydney')->format('Y-m-d H:i'));
    }

    public function test_it_formats_a_utc_instant_in_the_users_zone_with_a_zone_label(): void
    {
        $utc = Carbon::parse('2026-09-01 10:00:00', 'UTC');

        $this->assertSame('September 1, 2026 3:00 PM (PKT)', UserTime::format($utc, 'Asia/Karachi'));
        $this->assertSame('September 1, 2026 10:00 AM (UTC)', UserTime::format($utc, 'UTC'));
    }

    public function test_it_labels_zones_without_an_abbreviation_using_a_utc_offset(): void
    {
        $utc = Carbon::parse('2026-09-01 10:00:00', 'UTC');

        $this->assertSame('September 1, 2026 2:00 PM (UTC+04:00)', UserTime::format($utc, 'Asia/Dubai'));
        $this->assertSame('September 1, 2026 3:45 PM (UTC+05:45)', UserTime::format($utc, 'Asia/Kathmandu'));
    }

    public function test_it_does_not_mutate_the_instant_it_formats(): void
    {
        $utc = Carbon::parse('2026-09-01 10:00:00', 'UTC');

        UserTime::format($utc, 'Asia/Karachi');

        $this->assertSame('UTC', $utc->timezoneName);
        $this->assertSame('2026-09-01 10:00:00', $utc->toDateTimeString());
    }

    public function test_two_users_see_the_same_instant_in_their_own_zones(): void
    {
        $utc = Carbon::parse('2026-09-01 10:00:00', 'UTC');

        $this->assertSame('September 1, 2026 3:00 PM (PKT)', UserTime::format($utc, 'Asia/Karachi'));
        $this->assertSame('September 1, 2026 6:00 AM (EDT)', UserTime::format($utc, 'America/New_York'));
    }
}
