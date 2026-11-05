<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    /**
     * Correlated subquery returning the average rating of this course.
     *
     * The same expression is produced by Eloquent's withAvg('reviews', 'rating'),
     * so filters, sorting and the payload can never disagree.
     */
    public const AVERAGE_RATING_SQL = '(select avg(course_reviews.rating) from course_reviews where course_reviews.course_id = courses.id)';

    /**
     * Columns a client is allowed to sort a course listing by.
     *
     * Kept on the model so the public listing and the admin listing can never
     * disagree about what is sortable, and so nothing can be interpolated into
     * the query without being whitelisted first.
     */
    public const SORTABLE_COLUMNS = [
        'title',
        'price',
        'rating',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'slug',
        'description',
        'thumbnail',
        'video_url',
        'price',
        'is_published',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_published' => 'boolean',
    ];

    public function instructor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('order');
    }

    public function quizzes()
    {
        return $this->hasManyThrough(Quiz::class, Lesson::class, 'course_id', 'lesson_id', 'id', 'id');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CourseReview::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * Only courses that are published are visible on public endpoints.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_published'), true);
    }

    /**
     * Free-text search across the course's public text columns.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $query) use ($like) {
            $query->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('slug', 'like', $like);
        });
    }

    /**
     * Filter by category.
     */
    public function scopeForCategory(Builder $query, ?int $categoryId): Builder
    {
        if ($categoryId === null) {
            return $query;
        }

        return $query->where($this->qualifyColumn('category_id'), $categoryId);
    }

    /**
     * Filter by instructor (course owner).
     */
    public function scopeForInstructor(Builder $query, ?int $instructorId): Builder
    {
        if ($instructorId === null) {
            return $query;
        }

        return $query->where($this->qualifyColumn('user_id'), $instructorId);
    }

    /**
     * Filter by an inclusive price range.
     */
    public function scopePriceBetween(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where($this->qualifyColumn('price'), '>=', $min);
        }

        if ($max !== null) {
            $query->where($this->qualifyColumn('price'), '<=', $max);
        }

        return $query;
    }

    /**
     * Only keep courses whose average rating is at least the given value.
     *
     * The rating is aggregated by the database in a correlated subquery, so no
     * extra round trip and no row-by-row work in PHP is needed.
     */
    public function scopeRatingAtLeast(Builder $query, ?float $rating): Builder
    {
        if ($rating === null) {
            return $query;
        }

        // The bound value is cast to a number in SQL: PDO sends PHP floats as
        // strings, and SQLite refuses to compare a REAL with a TEXT value, which
        // would make the filter silently match nothing.
        return $query->whereRaw(self::AVERAGE_RATING_SQL.' >= ? + 0', [$rating]);
    }

    /**
     * Order by the average rating (see self::AVERAGE_RATING_SQL).
     *
     * Courses without reviews have no rating; they are pushed to the end in
     * both directions instead of disappearing.
     */
    public function scopeOrderByAverageRating(Builder $query, string $direction = 'desc'): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderByRaw(self::AVERAGE_RATING_SQL.' is null')
            ->orderByRaw(self::AVERAGE_RATING_SQL.' '.$direction);
    }

    /**
     * Sort by one of the whitelisted columns, always with a stable tie breaker.
     *
     * Anything outside the whitelist falls back to the default order, so a
     * crafted `sort_by` can never reach the query as a column name.
     */
    public function scopeSortedBy(Builder $query, string $column = 'created_at', string $direction = 'desc'): Builder
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            $column = 'created_at';
        }

        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        if ($column === 'rating') {
            return $this->scopeOrderByAverageRating($query, $direction)
                ->orderBy('id', 'desc');
        }

        return $query->orderBy($column, $direction)->orderBy('id', 'desc');
    }
}
