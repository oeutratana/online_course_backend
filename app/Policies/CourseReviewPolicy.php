<?php

namespace App\Policies;

use App\Models\CourseReview;
use App\Models\User;

class CourseReviewPolicy
{
    /**
     * A student owns a review when the review row carries their user id.
     */
    public function update(User $user, CourseReview $review): bool
    {
        return $user->role === 'admin' || $review->user_id === $user->id;
    }

    public function delete(User $user, CourseReview $review): bool
    {
        return $user->role === 'admin' || $review->user_id === $user->id;
    }
}
