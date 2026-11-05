<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    /**
     * Certificates are personal records: only the owner (or an admin) may read
     * or download them.
     */
    public function view(User $user, Certificate $certificate): bool
    {
        return $user->role === 'admin' || $certificate->user_id === $user->id;
    }

    public function download(User $user, Certificate $certificate): bool
    {
        return $this->view($user, $certificate);
    }
}
