<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('expenses', function ($user) {
    // Authenticated administrators, directors, accountants, and principals can listen to financial events
    return in_array($user->role, ['admin', 'director', 'accountant', 'chairman', 'principal', 'super_admin', 'hr_accountant', 'teacher']);
});

Broadcast::channel('chat.{type}.{id}', function ($user, $type, $id) {
    // Authenticated staff and leadership can access message threads
    return in_array($user->role, ['admin', 'director', 'accountant', 'chairman', 'principal', 'super_admin', 'hr_accountant', 'teacher']);
});
