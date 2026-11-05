<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate {{ $certificate->certificate_number }}</title>
    <style>
        @page { margin: 0; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #1f2937;
            margin: 0;
        }

        .frame {
            position: relative;
            width: 100%;
            height: 100vh;
            padding: 28px;
            box-sizing: border-box;
        }

        .border {
            border: 6px double #b45309;
            height: 100%;
            padding: 46px 60px;
            box-sizing: border-box;
            text-align: center;
        }

        .brand {
            font-size: 13px;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #92400e;
            margin-bottom: 28px;
        }

        h1 {
            font-size: 38px;
            margin: 0 0 6px 0;
            letter-spacing: 1px;
        }

        .subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 34px;
        }

        .label {
            font-size: 12px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 10px;
        }

        .student {
            font-size: 32px;
            color: #111827;
            border-bottom: 2px solid #d1d5db;
            display: inline-block;
            padding: 0 28px 8px 28px;
            margin-bottom: 26px;
        }

        .statement {
            font-size: 14px;
            color: #374151;
            margin-bottom: 10px;
        }

        .course {
            font-size: 22px;
            color: #111827;
            margin-bottom: 26px;
        }

        .meta {
            font-size: 11px;
            color: #6b7280;
            margin-top: 30px;
        }

        .footer {
            width: 100%;
            font-size: 11px;
            color: #6b7280;
            margin-top: 34px;
        }
    </style>
</head>
<body>
    <div class="frame">
        <div class="border">
            <div class="brand">Online Course Platform</div>

            <h1>Certificate of Completion</h1>
            <div class="subtitle">This certificate is proudly presented to</div>

            <div class="label">Awarded to</div>
            <div class="student">{{ $studentName }}</div>

            <div class="statement">for successfully completing the course</div>
            <div class="course">{{ $courseTitle }}</div>

            @if ($categoryName)
                <div class="statement">Category: {{ $categoryName }}</div>
            @endif

            <div class="statement">
                Issued on {{ $issuedOn }}
                @if ($instructorName)
                    &nbsp;&middot;&nbsp; Instructed by {{ $instructorName }}
                @endif
            </div>

            <div class="meta">
                Certificate No. {{ $certificate->certificate_number }}
            </div>

            <div class="footer">
                Verify this certificate at<br>
                {{ $verificationUrl }}
            </div>
        </div>
    </div>
</body>
</html>
