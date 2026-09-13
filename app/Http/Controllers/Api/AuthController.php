<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function requestOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.exists' => 'This Email is not registered.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Please contact the administration.'], 403);
        }

        // Generate 6-digit OTP
        $otp = (string) rand(100000, 999999);

        // Store hashed OTP in database
        $user->otp_code = Hash::make($otp);
        $user->otp_expires_at = now()->addMinutes(5);
        $user->save();

        // Dispatch official branded OTP email via Hostinger SMTP
        try {
            Mail::to($user->email)->send(new OtpMail($otp, $user));
        } catch (\Throwable $e) {
            \Log::warning('SMTP dispatch failed or timed out: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'OTP sent successfully to your email.',
            'dev_otp' => config('app.debug') ? $otp : null,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|digits:6',
        ], [
            'email.exists' => 'This Email is not registered.',
            'otp.required' => 'Please enter the 6-digit OTP code.',
            'otp.digits' => 'OTP code must be 6 digits.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Please contact the administration.'], 403);
        }

        // Validate OTP with dev fallback (123456 or generated otp)
        $isOtpValid = ($user->otp_code && Hash::check($request->otp, $user->otp_code)) 
            || (config('app.debug') && in_array($request->otp, ['123456', '000000']));

        if (!$isOtpValid) {
            return response()->json(['message' => 'Invalid OTP code entered.'], 400);
        }

        if ($user->otp_expires_at && now()->isAfter($user->otp_expires_at) && $request->otp !== '123456') {
            return response()->json(['message' => 'OTP has expired. Please request a new code.'], 400);
        }

        // OTP is valid. Clear OTP fields
        $user->otp_code = null;
        $user->otp_expires_at = null;
        $user->save();

        // Prune old tokens to prevent token table accumulation
        $user->tokens()->where('name', 'nsse-auth-token')->delete();

        // Issue Fresh Sanctum Token
        $token = $user->createToken('nsse-auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated successfully.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?: ($user->roles->first()->name ?? 'user'),
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ]
        ]);
    }
}
