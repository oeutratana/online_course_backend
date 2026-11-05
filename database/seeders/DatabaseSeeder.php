<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Course;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $courses = [
            [
                'id' => 1,
                'video_url' => 'https://www.youtube.com/watch?v=cbSrsYiRamo',
            ],
            [
                'id' => 2,
                'video_url' => 'https://youtu.be/tcd-rBP36vM?si=f6x3WT_i3zmMf0O1',
            ],
            [
                'id' => 3,
                'video_url' => 'https://youtu.be/dmzr_BUsvyY?si=9X6m1oMTcejvuTuK',
            ],
            [
                'id' => 4,
                'video_url' => 'https://youtu.be/c9Wg6Cb_YlU?si=E9PggXSr3_TTHGEI',
            ],
        ];

        foreach ($courses as $c) {
            $course = Course::find($c['id']);
            if ($course) {
                $course->update(['video_url' => $c['video_url']]);
            }
        }
    }
}
