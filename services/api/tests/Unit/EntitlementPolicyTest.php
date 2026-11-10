<?php

namespace Tests\Unit;

use App\Models\Movie;
use App\Models\Role;
use App\Models\User;
use App\Support\Entitlements;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

/**
 * Entitlements are pure policy: no routes, no database. They still need the
 * container because the policy reads its switches from config/ott.php.
 */
class EntitlementPolicyTest extends TestCase
{
    public function test_unpublished_titles_are_refused_before_anything_else(): void
    {
        $this->assertSame(
            Entitlements::DENY_MOVIE_UNPUBLISHED,
            Entitlements::denyReason($this->viewer(now()->addMonth()), new Movie(['published' => false])),
        );
    }

    public function test_guests_cannot_play_published_titles(): void
    {
        $this->assertSame(
            Entitlements::DENY_UNAUTHENTICATED,
            Entitlements::denyReason(null, new Movie(['published' => true])),
        );
    }

    public function test_current_and_missing_subscriptions_are_entitled(): void
    {
        $published = new Movie(['published' => true]);

        $this->assertNull(Entitlements::denyReason($this->viewer(now()->addMonth()), $published));
        $this->assertNull(Entitlements::denyReason($this->viewer(null), $published));
    }

    public function test_expired_subscriptions_are_refused(): void
    {
        $this->assertSame(
            Entitlements::DENY_SUBSCRIPTION_EXPIRED,
            Entitlements::denyReason($this->viewer(now()->subMonth()), new Movie(['published' => true])),
        );
    }

    public function test_administrators_bypass_the_policy_for_quality_assurance(): void
    {
        $this->assertNull(Entitlements::denyReason($this->admin(Role::ADMIN), new Movie(['published' => true])));
        $this->assertNull(Entitlements::denyReason($this->admin(Role::ADMIN), new Movie(['published' => false])));
    }

    public function test_missing_subscription_can_be_switched_to_denied(): void
    {
        config(['ott.playback.allow_missing_subscription' => false]);

        $this->assertSame(
            Entitlements::DENY_SUBSCRIPTION_EXPIRED,
            Entitlements::denyReason($this->viewer(null), new Movie(['published' => true])),
        );
    }

    public function test_authentication_can_be_relaxed_for_a_public_trial(): void
    {
        config(['ott.playback.require_authentication' => false]);

        $this->assertNull(Entitlements::denyReason(null, new Movie(['published' => true])));
    }

    private function viewer(?\DateTimeInterface $expiresAt): User
    {
        $user = new User(['name' => 'Viewer']);
        $user->subscription_expires_at = $expiresAt;
        $user->setRelation('roles', new Collection([new Role(['slug' => Role::SUBSCRIBER])]));

        return $user;
    }

    private function admin(string $slug): User
    {
        $user = new User(['name' => 'Admin']);
        $user->setRelation('roles', new Collection([new Role(['slug' => $slug])]));

        return $user;
    }
}
