# Online Course API — Roles, Content, Quizzes and Certificates

Base URL: `/api`
Authentication: Laravel Sanctum bearer token (`Authorization: Bearer <token>`).
All request/response bodies are JSON unless a file upload or PDF download is noted.

## Role matrix

| Capability | Student | Admin |
| --- | --- | --- |
| Register / login | self-register allowed | created by admin |
| Browse published courses/lessons/quizzes | yes | yes |
| Create/update/delete courses | no | any course |
| Create/update/delete categories | no | yes |
| Create/update/delete lessons | no | any course |
| Create/update/delete quizzes/questions | no | any course |
| View enrolled students, progress, quiz results | no | all |
| Buy a course / start an enrollment | yes | yes |
| Mark lesson progress | enrolled student | enrolled student |
| Submit quiz attempts | enrolled student | enrolled student |
| Claim/download certificates | own | own; admin can inspect all |
| Manage users/enrollments | no | yes |

The API only supports the `student` and `admin` roles. All content management
endpoints are admin-only.

## Access and ownership rules

- Public course listing only returns `is_published = true` courses.
- Draft course detail is visible to admins only.
- A paid course's lessons and quizzes require an active enrollment. A free course
  remains readable without enrollment (backwards-compatible with the existing
  lesson-access tests). Writing progress/attempts always requires an active
  enrollment.
- Students never receive `correct_option` from course, lesson, quiz or question
  endpoints. Answer keys are returned to admins and in the graded attempt
  response after submission.
- Certificate eligibility always requires:
  1. an active enrollment,
  2. at least one lesson and every lesson completed,
  3. every published, required quiz passed with at least 60%.
- A course with no quizzes passes the quiz rule automatically.
- Certificates remain unique per `(user_id, course_id)`.

## Endpoints

### Authentication and profile

| Method | Path | Access |
| --- | --- | --- |
| POST | `/register` | public (`role` = `student`) |
| POST | `/login` | public |
| GET | `/user`, `/user/profile` | authenticated |
| PUT | `/user/profile` | authenticated |
| POST | `/logout` | authenticated |

### Courses and categories

| Method | Path | Access |
| --- | --- | --- |
| GET | `/courses` | public (published only, filters/sorting/pagination) |
| GET | `/courses/{course}` | public; drafts admin only |
| GET | `/my/courses` | student's active enrollments |
| POST | `/courses` | admin |
| PUT | `/courses/{course}` | admin |
| DELETE | `/courses/{course}` | admin |
| GET | `/categories`, `/categories/{category}` | public |
| POST/PUT/DELETE | `/categories...` | admin |

Course create/update accepts `title`, `price`, `category_id`, `description`,
`thumbnail` or `image_file`, `video_url` or `video_file`, and `is_published`.

### Lessons, progress and reporting

| Method | Path | Access |
| --- | --- | --- |
| GET | `/lessons`, `/lessons/{lesson}` | public with access checks |
| POST | `/lessons` | admin |
| PUT/DELETE | `/lessons/{lesson}` | admin |
| POST | `/lessons/{lesson}/progress` | enrolled student |
| DELETE | `/lessons/{lesson}/progress` | enrolled student |
| GET | `/courses/{course}/progress` | enrolled student or manager |
| GET | `/lesson-progress` | admin |

### Quizzes, questions and attempts

| Method | Path | Access |
| --- | --- | --- |
| GET | `/quizzes`, `/quizzes/{quiz}` | public with access checks, answers hidden |
| GET | `/questions`, `/questions/{question}` | public with access checks, answers hidden |
| POST | `/quizzes` | admin |
| PUT/DELETE | `/quizzes/{quiz}` | admin |
| POST | `/quizzes/{quiz}/questions` | admin |
| PUT/DELETE | `/questions/{question}` | admin |
| POST | `/quizzes/{quiz}/attempts` | enrolled student |
| GET | `/quizzes/{quiz}/attempts` | own attempts |
| GET | `/quiz-attempts/{attempt}` | owner or course manager |

Quiz fields: `lesson_id`, `title`, `description`, `passing_score` (60–100,
default 60), `max_attempts` (0 = unlimited), `is_published`, `is_required`.
Question fields: `question`, `option_a`–`option_d`, `correct_option`
(`a`/`b`/`c`/`d` or `option_a`–`option_d`, normalised to `option_x`).

Attempt payload accepts either shape:

```json
{ "answers": { "12": "a", "13": "option_c" } }
```

```json
{ "answers": [
  { "question_id": 12, "selected_option": "a" },
  { "question_id": 13, "selected_option": "c" }
] }
```

Every question must be answered exactly once. The server scores the attempt,
stores `quiz_attempts` + `quiz_attempt_answers`, enforces `max_attempts`, and
returns `score`, `passed`, `attempt_number`, and per-question correctness.

### Certificates

| Method | Path | Access |
| --- | --- | --- |
| GET | `/certificates` | own certificates |
| POST | `/certificates` | claim own certificate (`course_id`) |
| GET | `/certificates/{certificate}` | owner/admin |
| GET | `/certificates/{certificate}/download` | owner/admin (PDF) |
| GET | `/certificates/verify/{number}` | public |
| GET | `/admin/certificates` | admin |

Claiming a certificate before every lesson + required quiz is finished returns
`403` with `message` and `progress` (including `progress.quizzes`).

### Payments, dashboard, wishlist and admin

PayWay endpoints are unchanged; see `docs/PAYWAY_PAYMENT_API.md`.

| Method | Path | Access |
| --- | --- | --- |
| POST | `/payments`, `/payments/{payment}/check` | authenticated |
| POST | `/payments/payway/callback` | public (HMAC) |
| GET | `/payments`, `/payments/{payment}` | admin |
| GET | `/dashboard` | authenticated |
| GET/ POST/ DELETE | `/wishlist...` | authenticated |
| POST/PUT/DELETE | `/courses/{course}/reviews...` | enrolled author |
| GET | `/admin/dashboard` | admin |
| GET | `/admin/courses`, `/admin/courses/{course}/analytics` | admin |
| GET/POST/PUT/DELETE | `/users...` | admin |
| GET/PUT/DELETE | `/enrollments...` | admin |

## Postman walkthrough

1. **Login as admin**
   `POST /api/login` with an admin email/password. Save `data.token` as
   `{{token}}`; add header `Authorization: Bearer {{token}}`.
2. **Create a category**
   `POST /api/categories`.
3. **Create a course**
   `POST /api/courses` as admin with `category_id`, `title`, `price`,
   `is_published: true`.
4. **Create a lesson**
   `POST /api/lessons` with `course_id` and lesson fields.
5. **Create a quiz and questions**
   `POST /api/quizzes` with `lesson_id`;
   `POST /api/quizzes/{quiz}/questions` with options and `correct_option`.
6. **Login as a student**
   Register with `POST /api/register` or log in, then use that token.
7. **Buy/free-enroll**
   `POST /api/payments` with `course_id`. Free courses activate enrollment
   immediately. For paid courses complete PayWay checkout then
   `POST /api/payments/{id}/check` until `status = paid`.
9. **Learn**
   `POST /api/lessons/{lesson}/progress` for every lesson;
   `GET /api/courses/{course}/progress` to verify 100%.
10. **Take the quiz**
    `GET /api/quizzes/{quiz}` (no answer key), then
    `POST /api/quizzes/{quiz}/attempts` with the answers map.
11. **Claim the certificate**
    `POST /api/certificates` with `course_id`.
    If a required quiz is missing/failed, the response explains why.
12. **Verify**
    `GET /api/certificates/verify/{certificate_number}` (no auth) or download
    the PDF as the owner/admin.

## Verification commands

```bash
php artisan migrate:status
php artisan route:list --path=api
php artisan test
./vendor/bin/pint --test
```
